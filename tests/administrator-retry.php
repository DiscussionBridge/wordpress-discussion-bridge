<?php

declare(strict_types=1);

// Included by integration.php. Actual Admin handler/publisher/journal/writer;
// reduced array-backed WordPress/HTTP host. Redirect/die are test exceptions so
// the real registered action can be observed without terminating the runner.
use DiscussionBridge\WordPress\Admin;
use DiscussionBridge\WordPress\CmsJournal;
use DiscussionBridge\WordPress\CmsMutation;
use DiscussionBridge\WordPress\CmsPresentation;
use DiscussionBridge\WordPress\CmsSourcePublisher;
use DiscussionBridge\WordPress\FromSynchronizer;
use DiscussionBridge\WordPress\Publisher;
use DiscussionBridge\WordPress\Settings;

final class ArRedirect extends RuntimeException {}
final class ArDenied extends RuntimeException {}
function absint(mixed $value): int { return abs((int) $value); }
function admin_url(string $path = ''): string { return 'https://wordpress.example/wp-admin/' . $path; }
function get_current_user_id(): int { return $GLOBALS['dbt']['current_user_id'] ?? 12; }
function wp_create_nonce(string $action): string { return 'synthetic-nonce-' . $action; }
function wp_nonce_field(string $action, string $name = '_wpnonce'): void { unset($action, $name); }
function checked(mixed $checked, mixed $current = true, bool $echo = true): string { unset($echo); return $checked == $current ? 'checked' : ''; }
function selected(mixed $selected, mixed $current = true, bool $echo = true): string { unset($echo); return $selected == $current ? 'selected' : ''; }
function check_admin_referer(string $action): int {
    $GLOBALS['dbt']['nonce_actions'][] = $action;
    if (!wp_verify_nonce((string) ($_POST['_wpnonce'] ?? ''), $action)) throw new ArDenied('nonce denied');
    return 1;
}
function wp_die(string $message): never { throw new ArDenied($message); }
function wp_safe_redirect(string $location): bool { $GLOBALS['dbt']['redirect'] = $location; throw new ArRedirect($location); }

function ar_refusal(string $code = 'invalid_field', bool $accepted_predecessor = false): stdClass {
    configured();
    if ($accepted_predecessor) {
        $accepted = establish_source();
        $GLOBALS['dbt']['posts'][1]->post_content = '<p>A genuine next native edition</p>';
        $GLOBALS['dbt']['ar_predecessor'] = $accepted;
        $record = record_for($accepted);
        mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    } else { mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); }
    mock('V4-02', (object) ['outcome' => 'rejected', 'code' => $code, 'retryable' => false,
        'retry_after_seconds' => null, 'correlation_id' => null, 'core_fallback' => false], 422);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result), 'fixture must obtain a definitive refused request');
    $state = (new CmsJournal(1))->read();
    expect($state instanceof stdClass && $state->pending instanceof stdClass, 'refused request must be durable');
    return $state;
}

function ar_submit(string $identity, array $overrides = [], string $method = 'POST'): bool {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_POST = array_merge(['post_id' => '1', 'request_identity' => hash('sha256', $identity),
        '_wpnonce' => wp_create_nonce('discussionbridge_retry_1')], $overrides);
    try { Admin::retry(); }
    catch (ArRedirect) { return true; }
    catch (ArDenied) { return false; }
    throw new RuntimeException('registered retry action neither redirected nor denied');
}

function ar_post_count(): int { return count(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST')); }

test('administrator retry registered action records exact native authorization before queuing unchanged refusal', function (): void {
    $old = ar_refusal(); $posts = ar_post_count();
    expect(ar_submit($old->pending->identity), 'actual admin action must accept current refusal');
    $state = (new CmsJournal(1))->read();
    expect(isset($state->retry_action), 'actual action must durably authorize the refused identity');
    expect($state->retry_action->request_identity === $old->pending->identity && $state->retry_action->actor_id === '12');
    expect($state->pending == $old->pending && $state->capture == $old->capture && ar_post_count() === $posts);
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && $GLOBALS['dbt']['native_lock'] === null);
});

test('administrator retry duplicate action is idempotent before worker restart and creates one linked request', function (): void {
    $old = ar_refusal(); expect(ar_submit($old->pending->identity));
    $action = (new CmsJournal(1))->read()->retry_action ?? null;
    expect(ar_submit($old->pending->identity)); $state = (new CmsJournal(1))->read();
    expect($state->retry_action == $action && count($GLOBALS['dbt']['scheduled']) === 1);
    mock('V4-02', fn($url, $args) => dbt_response(201, source_receipt(json_decode($args['body'])->bridge_record)));
    // A new serialized worker reads only persisted authorization; no in-process action object is passed.
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $done = (new CmsJournal(1))->read(); $history = $done->rejected_history;
    expect(count($history) === 1 && $history[0]->request == $old->pending && $history[0]->capture == $old->capture);
    expect($history[0]->operator_action == $action && $history[0]->continuation === 'explicit_native_retry');
    expect($history[0]->replacement_identity !== $old->pending->identity && $history[0]->replacement_mutation_id !== $old->pending->options->body->mutation_id);
    expect($done->accepted_history[0]->request->authorized_retry == $action && ar_post_count() === 2);
});

test('administrator retry old form cannot authorize a newer definitive refusal', function (): void {
    $old = ar_refusal(); expect(ar_submit($old->pending->identity));
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    $new = (new CmsJournal(1))->read(); expect($new->pending->identity !== $old->pending->identity);
    $GLOBALS['dbt']['scheduled'] = []; $meta = $GLOBALS['dbt']['meta']; $posts = ar_post_count();
    expect(!ar_submit($old->pending->identity), 'stale submitted identity must be denied');
    expect((new CmsJournal(1))->read() == $new && $GLOBALS['dbt']['meta'] === $meta && $GLOBALS['dbt']['scheduled'] === [] && ar_post_count() === $posts);
    expect(ar_submit($new->pending->identity), 'new refusal needs its own explicit action');
});

foreach (['manage_options', 'edit_post'] as $capability) test('administrator retry denies missing native capability ' . $capability, function () use ($capability): void {
    $old = ar_refusal(); $GLOBALS['dbt']['capabilities'][$capability] = false;
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry denies invalid nonce without authorizing or resetting attempts', function (): void {
    $old = ar_refusal(); $GLOBALS['dbt']['nonce_valid'] = false; $meta = $GLOBALS['dbt']['meta'];
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['meta'] === $meta && $GLOBALS['dbt']['scheduled'] === []);
});

foreach (['missing' => null, 'array' => ['invalid'], 'short' => 'bad', 'oversized' => str_repeat('a', 65), 'nonhex' => str_repeat('z', 64), 'wrong' => str_repeat('0', 64)] as $label => $identity) test('administrator retry denies ' . $label . ' refusal identity', function () use ($identity): void {
    $old = ar_refusal(); expect(!ar_submit($old->pending->identity, ['request_identity' => $identity]));
    expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry requires POST and rejects an anonymous native actor', function (): void {
    $old = ar_refusal(); expect(!ar_submit($old->pending->identity, [], 'GET'));
    $GLOBALS['dbt']['current_user_id'] = 0; expect(!ar_submit($old->pending->identity));
    expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
});

foreach (['capability', 'actor', 'nonce'] as $change) test('administrator retry rechecks ' . $change . ' while the native source is captured under writer', function () use ($change): void {
    $old = ar_refusal();
    $GLOBALS['dbt']['native_row_callback'] = function () use ($change): void {
        $GLOBALS['dbt']['native_row_callback'] = null;
        if ($change === 'capability') $GLOBALS['dbt']['capabilities']['manage_options'] = false;
        elseif ($change === 'actor') $GLOBALS['dbt']['current_user_id'] = 99;
        else $GLOBALS['dbt']['nonce_valid'] = false;
    };
    expect(!ar_submit($old->pending->identity));
    expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === [] && $GLOBALS['dbt']['native_lock'] === null);
});

test('administrator retry refuses a failed durable authorization write before queue reset', function (): void {
    $old = ar_refusal(); $store = new CmsJournal(1); $meta = $GLOBALS['dbt']['meta'];
    $GLOBALS['dbt']['option_write_callback'] = fn($key, $value) => $key !== $store->option_name();
    expect(!ar_submit($old->pending->identity)); expect($store->read() == $old && $GLOBALS['dbt']['meta'] === $meta && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry rejects changed native scope and From receipt without touching the exact refusal', function (): void {
    $old = ar_refusal(); update_option(Settings::LANE_OPTION, 'another-lane');
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old);
    update_option(Settings::LANE_OPTION, 'wordpress'); $GLOBALS['dbt']['meta'][1][CmsPresentation::META] = ['existing' => 'from receipt'];
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry preserves historical unknown refusal fields without inventing a new eligibility gate', function (): void {
    $old = ar_refusal(); unset($old->pending->rejection); expect((new CmsJournal(1))->write($old, fn() => true));
    expect(ar_submit($old->pending->identity));
    mock('V4-02', fn($url, $args) => dbt_response(201, source_receipt(json_decode($args['body'])->bridge_record)));
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    $history = (new CmsJournal(1))->read()->rejected_history[0];
    expect($history->request == $old->pending && $history->refusal_evidence->decoded_error_known === false);
    expect($history->refusal_evidence->unknown_fields === ['raw_error', 'correlation_id', 'unretained_decoded_fields']);
});

test('administrator retry preserves accepted predecessor IDs history and CAS on a corrected unchanged source', function (): void {
    $old = ar_refusal('invalid_field', true); $accepted = $GLOBALS['dbt']['ar_predecessor'];
    expect(ar_submit($old->pending->identity));
    mock('V4-02', function ($url, $args) use ($old, $accepted): array {
        $request = json_decode($args['body']);
        expect($request->mutation_id !== $old->pending->options->body->mutation_id);
        expect($request->bridge_record == $old->capture->body && $request->bridge_record->expected_source_revision === $accepted->receipt->source_revision);
        $receipt = source_receipt($request->bridge_record); $receipt->outcome = 'updated'; $receipt->reason = 'source_revision_updated'; return dbt_response(200, $receipt);
    });
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy'); $done = (new CmsJournal(1))->read();
    expect($done->accepted_history[0] == $accepted->accepted_history[0] && count($done->accepted_history) === 2);
    expect($done->receipt->resource_id === $accepted->receipt->resource_id && $done->receipt->topic_id === $accepted->receipt->topic_id);
    expect($done->rejected_history[0]->request == $old->pending && $done->rejected_history[0]->receipt == $accepted->receipt);
});

test('administrator retry stops before fresh mutation when receiver accepted predecessor has diverged', function (): void {
    $old = ar_refusal('invalid_field', true); expect(ar_submit($old->pending->identity)); $posts = ar_post_count();
    $record = record_for($GLOBALS['dbt']['ar_predecessor']); $record->source_revision = 'unknown-current-edition';
    mock('V4-04', $record, 200, '', ['resource_id' => $record->resource_id]);
    $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard));
    expect(is_wp_error($result) && $result->get_error_code() === 'discussionbridge_cms_to_rejected_predecessor_reconciliation');
    $state = (new CmsJournal(1))->read(); expect($state->pending == $old->pending && $state->capture == $old->capture && !isset($state->rejected_history) && ar_post_count() === $posts);
});

test('administrator retry excludes preservation422 without generic queue reset', function (): void {
    $old = ar_refusal('content_preservation_unavailable'); $meta = $GLOBALS['dbt']['meta'];
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['meta'] === $meta && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry excludes uncertain previous mutation and retains exact UUID for replay', function (): void {
    configured(); mock('V4-01', connection()); mock('V4-03', (object) ['items' => [], 'next_cursor' => null]); mock('V4-02', new WP_Error('synthetic-timeout'));
    FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)); $old = (new CmsJournal(1))->read();
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && ar_post_count() === 1 && $GLOBALS['dbt']['scheduled'] === []);
    mock('V4-02', fn($url, $args) => dbt_response(201, source_receipt(json_decode($args['body'])->bridge_record)));
    expect(FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::step(1, $guard)) === 'healthy');
    expect((new CmsJournal(1))->read()->accepted_history[0]->request->identity === $old->pending->identity);
});

test('administrator retry denies changed native source without archiving or queuing the refused intent', function (): void {
    $old = ar_refusal(); $GLOBALS['dbt']['posts'][1]->post_content = '<p>Genuinely different source</p>';
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
});

test('administrator retry denies unavailable shared writer without any authorization or queue reset', function (): void {
    $old = ar_refusal(); $GLOBALS['dbt']['native_lock'] = 'other'; $meta = $GLOBALS['dbt']['meta'];
    expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['meta'] === $meta && $GLOBALS['dbt']['scheduled'] === []);
});

foreach (['withdrawal', 'source_move'] as $lifecycle) {
    test('administrator retry helper excludes independent lifecycle ' . $lifecycle, function () use ($lifecycle): void {
        $old = ar_refusal(); $old->$lifecycle = (object) ['retained' => true]; expect((new CmsJournal(1))->write($old, fn() => true));
        $result = FromSynchronizer::serialized(fn($guard) => CmsSourcePublisher::authorize_retry(1, $old->pending->identity, 12, $guard));
        expect(is_wp_error($result), 'either lifecycle alone must block retry authorization');
        expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
    });
    test('administrator retry registered action excludes independent lifecycle ' . $lifecycle, function () use ($lifecycle): void {
        $old = ar_refusal(); $old->$lifecycle = (object) ['retained' => true]; expect((new CmsJournal(1))->write($old, fn() => true));
        expect(!ar_submit($old->pending->identity)); expect((new CmsJournal(1))->read() == $old && $GLOBALS['dbt']['scheduled'] === []);
    });
}

test('administrator retry native control exposes exact identity and nonce without request content or secret', function (): void {
    $old = ar_refusal(); ob_start(); Admin::render_meta_box($GLOBALS['dbt']['posts'][1]); $html = ob_get_clean();
    expect(str_contains($html, 'data-discussionbridge-retry') && str_contains($html, hash('sha256', $old->pending->identity)), 'eligible source needs an explicit retry control');
    expect(str_contains($html, wp_create_nonce('discussionbridge_retry_1')) && str_contains($html, 'type="button"'));
    expect(!str_contains($html, $old->pending->identity) && !str_contains($html, $old->capture->body->content_html) && !str_contains($html, Settings::connection_secret()));
});

foreach (['preservation', 'from', 'withdrawal', 'source_move'] as $excluded) test('administrator retry native control excludes ' . $excluded, function () use ($excluded): void {
    $old = ar_refusal($excluded === 'preservation' ? 'content_preservation_unavailable' : 'invalid_field');
    if ($excluded === 'from') $GLOBALS['dbt']['meta'][1][CmsPresentation::META] = ['current' => 'from receipt'];
    elseif (in_array($excluded, ['withdrawal', 'source_move'], true)) { $old->$excluded = (object) ['retained' => true]; expect((new CmsJournal(1))->write($old, fn() => true)); }
    ob_start(); Admin::render_meta_box($GLOBALS['dbt']['posts'][1]); $html = ob_get_clean();
    expect(!str_contains($html, 'data-discussionbridge-retry'));
});

test('administrator retry asset is registered only on the native post edit screen for administrators', function (): void {
    configured(); Admin::enqueue_assets('post.php'); expect(in_array('discussionbridge-source-retry', $GLOBALS['dbt']['scripts'], true));
    configured(); Admin::enqueue_assets('index.php'); expect($GLOBALS['dbt']['scripts'] === []);
    configured(); $GLOBALS['dbt']['capabilities']['manage_options'] = false; Admin::enqueue_assets('post.php'); expect($GLOBALS['dbt']['scripts'] === []);
});
