<?php

declare(strict_types=1);

// Actual scheduler entry points under the maintained reduced native host. Callbacks inject one boundary
// interleaving/failure, not a native cron implementation or proof of physical concurrent WordPress behavior.
use DiscussionBridge\WordPress\CmsCoordinator;
use DiscussionBridge\WordPress\CmsJournal;
use DiscussionBridge\WordPress\FromSynchronizer;
use DiscussionBridge\WordPress\Settings;

/** Preserve the old-context ciphertext so changing configuration cannot hide a reset behind another journal key. */
function sr_fixture(string $status = 'healthy', ?int $due = null): array {
    configured(); FromSynchronizer::ensure_scheduled();
    $state = get_option(FromSynchronizer::STATE_OPTION); $state['status'] = $status;
    $state['failures'] = $status === 'healthy' ? 0 : 3; $state['reason'] = $status === 'healthy' ? '' : 'retained_local_reason';
    $state['last_success'] = time() - 500; $state['last_attempt'] = time() - 100;
    $state['last_wake'] = null; $state['next_due'] = $due;
    update_option(FromSynchronizer::STATE_OPTION, $state, false);
    wp_clear_scheduled_hook(FromSynchronizer::HOOK);
    if ($due !== null) wp_schedule_single_event($due, FromSynchronizer::HOOK);
    $work = fixture('lease')->work; $work->state = 'attention'; $work->attempt = 3;
    $work->last_failure_code = 'transport_timeout';
    $tree = CmsCoordinator::fresh('scheduler-protected-history');
    $tree->attention_jobs->{$work->work_id} = (object) ['work' => $work, 'native_proof' => 'retained-native-proof', 'exact_pending_history' => 'preserve'];
    $tree->local_issues->{'known-limitation'} = (object) ['evidence' => 'retained-local-issue'];
    $store = new CmsJournal(); expect($store->write($tree, fn() => true));
    return ['state' => $state, 'option' => $store->option_name(), 'ciphertext' => get_option($store->option_name())];
}

function sr_protected(array $before): void {
    expect(get_option($before['option']) === $before['ciphertext'], 'the original protected Work/history/local issue bytes changed');
    expect(array_filter($GLOBALS['dbt']['requests'], fn($r) => $r[1]['method'] === 'POST') === [], 'local recovery must not dispatch receiver Work/To retry');
}

function sr_query_once(string $needle, callable $change): void {
    $GLOBALS['dbt']['native_query_callback'] = static function (string $query) use ($needle, $change): void {
        if (!str_contains($query, $needle)) return;
        unset($GLOBALS['dbt']['native_query_callback']); $change();
    };
}

test('scheduler recovery WR1 coalesced blocked due wake persists unblocked state before reporting queued', function (): void {
    foreach ([time() - 2, time(), time() + 1, time() + 300, null] as $due) {
        $before = sr_fixture('configuration_required', $due);
        $result = FromSynchronizer::retry_synchronization(); $state = get_option(FromSynchronizer::STATE_OPTION);
        expect(in_array($result, ['queued', 'already_queued'], true), 'a current authorized local wake should coalesce');
        expect($state['status'] !== 'configuration_required' && $state['last_wake'] !== null, 'queued wake left the scheduler blocked');
        expect($state['epoch'] === $before['state']['epoch'] && $state['failures'] === 3 && count($GLOBALS['dbt']['scheduled']) === 1);
        // WordPress removes a due event before invoking its callback. Verify this wake reaches a bounded read.
        wp_clear_scheduled_hook(FromSynchronizer::HOOK); mock('V4-01', new WP_Error('timeout'));
        FromSynchronizer::run();
        expect($GLOBALS['dbt']['requests'] !== [] && get_option(FromSynchronizer::STATE_OPTION)['status'] === 'local_error', 'coalesced wake became a no-op');
        sr_protected($before);
    }
});

test('scheduler recovery WR1 duplicate current wake keeps one event and all Work failure history', function (): void {
    $before = sr_fixture('configuration_required', time() + 300);
    expect(FromSynchronizer::retry_synchronization() === 'queued');
    expect(in_array(FromSynchronizer::retry_synchronization(), ['queued', 'already_queued'], true));
    $state = get_option(FromSynchronizer::STATE_OPTION);
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && $state['status'] !== 'configuration_required' && $state['failures'] === 3);
    expect($state['last_wake'] !== null && $state['epoch'] === $before['state']['epoch']); sr_protected($before);
});

test('scheduler recovery WR1 coalesced wake cannot claim success when its state persistence fails', function (): void {
    $before = sr_fixture('configuration_required', time());
    $GLOBALS['dbt']['option_write_callback'] = static fn($key, $value): bool => $key !== FromSynchronizer::STATE_OPTION;
    $result = FromSynchronizer::retry_synchronization();
    expect(is_wp_error($result), 'failed persisted reopening must not return already_queued');
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state'] && count($GLOBALS['dbt']['scheduled']) === 1);
    sr_protected($before);
});

test('scheduler recovery WR1 manual actor change during lock acquisition cannot queue a wake', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['current_user_id'] = 12;
    sr_query_once('GET_LOCK(', static function (): void { $GLOBALS['dbt']['current_user_id'] = 13; });
    expect(is_wp_error(FromSynchronizer::retry_synchronization()), 'another actor cannot inherit the in-flight local action');
    expect($GLOBALS['dbt']['scheduled'] === []); sr_protected($before);
});

test('scheduler recovery WR1 manual permission loss during lock acquisition cannot queue a wake', function (): void {
    $before = sr_fixture();
    sr_query_once('GET_LOCK(', static function (): void { $GLOBALS['dbt']['capabilities']['manage_options'] = false; });
    expect(is_wp_error(FromSynchronizer::retry_synchronization()));
    expect($GLOBALS['dbt']['scheduled'] === []); sr_protected($before);
});

test('scheduler recovery WR2 missing connection reports disabled without erasing stored last success', function (): void {
    $before = sr_fixture('healthy', time() + 300);
    update_option(Settings::CONNECTION_SECRET_OPTION, '', false);
    FromSynchronizer::ensure_scheduled(); $status = FromSynchronizer::status();
    expect($status['status'] === 'disabled' && $status['next_due'] === null && $GLOBALS['dbt']['scheduled'] === [], 'disabled connection shows stale healthy queue');
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state'] && $status['last_success'] === $before['state']['last_success']);
    sr_protected($before);
});

test('scheduler recovery WR2 missing service author reports disabled without resetting protected work', function (): void {
    $before = sr_fixture('healthy', time() + 300); unset($GLOBALS['dbt']['users'][7]);
    FromSynchronizer::ensure_scheduled(); $status = FromSynchronizer::status();
    expect($status['status'] === 'disabled' && $status['next_due'] === null && $GLOBALS['dbt']['scheduled'] === []);
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state'] && $status['last_success'] === $before['state']['last_success']); sr_protected($before);
});

test('scheduler recovery WR2 lost service author capability reports disabled and retains historical success', function (): void {
    $before = sr_fixture('healthy', time() + 300);
    $GLOBALS['dbt']['users'][7]->capabilities['publish_posts'] = false;
    FromSynchronizer::ensure_scheduled(); $status = FromSynchronizer::status();
    expect($status['status'] === 'disabled' && $status['next_due'] === null && $GLOBALS['dbt']['scheduled'] === []);
    expect($status['last_success'] === $before['state']['last_success'] && get_option(FromSynchronizer::STATE_OPTION) === $before['state']);
    sr_protected($before);
});

test('scheduler recovery WR2 deleted secret lifecycle reports disabled rather than a phantom queued check', function (): void {
    $before = sr_fixture('healthy', time() + 300);
    delete_option(Settings::CONNECTION_SECRET_OPTION);
    // The reduced option host does not dispatch lifecycle hooks; call the actual handler explicitly.
    FromSynchronizer::configuration_changed(Settings::CONNECTION_SECRET_OPTION);
    $state = get_option(FromSynchronizer::STATE_OPTION); $status = FromSynchronizer::status();
    expect(!Settings::ready() && $status['status'] === 'disabled' && $status['next_due'] === null);
    expect($GLOBALS['dbt']['scheduled'] === [] && get_option(FromSynchronizer::STATE_OPTION) === $state);
    sr_protected($before);
});

test('scheduler recovery WR2 status reports actual earliest event rather than stale saved next due', function (): void {
    $due = time() + 80; $before = sr_fixture('healthy', $due);
    $state = $before['state']; $state['next_due'] = time() + 900; update_option(FromSynchronizer::STATE_OPTION, $state, false);
    array_unshift($GLOBALS['dbt']['scheduled'], [time() + 300, FromSynchronizer::HOOK, []]);
    $status = FromSynchronizer::status();
    expect($status['next_due'] === $due && !$status['overdue']);
    expect(get_option(FromSynchronizer::STATE_OPTION) === $state, 'status should not rewrite historical local success'); sr_protected($before);
});

test('scheduler recovery WR2 absent event stays absent while old expected due still exposes overdue health', function (): void {
    $before = sr_fixture('healthy'); $state = $before['state']; $state['next_due'] = time() - 700;
    update_option(FromSynchronizer::STATE_OPTION, $state, false); $status = FromSynchronizer::status();
    expect($status['next_due'] === null && $status['overdue'], 'saved due is history, not a queued event');
    sr_protected($before);
});

test('scheduler recovery QUEUE replacement enqueue failure is truthful and later init can repair one event', function (): void {
    $before = sr_fixture('healthy', time() + 300);
    $GLOBALS['dbt']['before_schedule'] = static function (): void { unset($GLOBALS['dbt']['before_schedule']); $GLOBALS['dbt']['schedule_failure'] = true; };
    $result = FromSynchronizer::retry_synchronization(); $status = FromSynchronizer::status();
    expect(is_wp_error($result) && $status['status'] === 'scheduling_unavailable', 'replacement failure was reported queued');
    expect($status['next_due'] === null && $GLOBALS['dbt']['scheduled'] === []);
    $GLOBALS['dbt']['schedule_failure'] = false; FromSynchronizer::ensure_scheduled(); FromSynchronizer::ensure_scheduled();
    expect(count($GLOBALS['dbt']['scheduled']) === 1); sr_protected($before);
});

test('scheduler recovery QUEUE failed clear retains actual event and cannot claim immediate replacement', function (): void {
    $due = time() + 300; $before = sr_fixture('healthy', $due);
    $GLOBALS['dbt']['before_clear'] = static function (): void { unset($GLOBALS['dbt']['before_clear']); $GLOBALS['dbt']['clear_failure'] = true; };
    $result = FromSynchronizer::retry_synchronization(); $status = FromSynchronizer::status();
    expect(is_wp_error($result), 'failed clear must not create a duplicate and claim queued');
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && wp_next_scheduled(FromSynchronizer::HOOK) === $due);
    expect($status['status'] === 'scheduling_unavailable' && $status['next_due'] === $due); sr_protected($before);
});

test('scheduler recovery QUEUE enqueue followed by failed due persistence cannot claim durable success', function (): void {
    $before = sr_fixture();
    $GLOBALS['dbt']['option_write_callback'] = static fn($key, $value): bool => $key !== FromSynchronizer::STATE_OPTION || !is_array($value) || !is_int($value['next_due'] ?? null);
    $result = FromSynchronizer::retry_synchronization(); $event = wp_next_scheduled(FromSynchronizer::HOOK);
    expect(is_wp_error($result), 'queue insertion is not persisted local health');
    expect($event !== false && count($GLOBALS['dbt']['scheduled']) === 1 && FromSynchronizer::status()['next_due'] === $event);
    sr_protected($before);
});

test('scheduler recovery BUSY healthy contention keeps one60 second continuation without Work or counter resets', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['native_lock'] = 'other'; $start = time();
    FromSynchronizer::run(); FromSynchronizer::run(); $due = wp_next_scheduled(FromSynchronizer::HOOK);
    expect(is_int($due) && $due >= $start + 60 && $due <= time() + 60 && count($GLOBALS['dbt']['scheduled']) === 1);
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state'] && $GLOBALS['dbt']['requests'] === []); sr_protected($before);
});

test('scheduler recovery BUSY healthy contention preserves an existing earlier continuation', function (): void {
    $due = time() + 25; $before = sr_fixture('healthy', $due); $GLOBALS['dbt']['native_lock'] = 'other';
    FromSynchronizer::run(); expect(count($GLOBALS['dbt']['scheduled']) === 1 && wp_next_scheduled(FromSynchronizer::HOOK) === $due);
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state']); sr_protected($before);
});

test('scheduler recovery BUSY stale readiness after lock contention cannot recreate disabled polling', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['native_lock'] = 'other';
    sr_query_once('GET_LOCK(', static function (): void { update_option(Settings::CONNECTION_SECRET_OPTION, '', false); });
    FromSynchronizer::run();
    expect($GLOBALS['dbt']['scheduled'] === [] && $GLOBALS['dbt']['requests'] === [], 'stale busy worker scheduled after readiness loss');
    expect(get_option(FromSynchronizer::STATE_OPTION) === $before['state']); sr_protected($before);
});

test('scheduler recovery BUSY stale epoch cannot schedule or overwrite the new current state', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['native_lock'] = 'other'; $replacement = $before['state'];
    $replacement['epoch'] = wp_generate_uuid4(); $replacement['reason'] = 'new_epoch';
    sr_query_once('GET_LOCK(', static function () use ($replacement): void { update_option(FromSynchronizer::STATE_OPTION, $replacement, false); });
    FromSynchronizer::run();
    expect($GLOBALS['dbt']['scheduled'] === [] && get_option(FromSynchronizer::STATE_OPTION) === $replacement, 'old busy worker consumed the new epoch'); sr_protected($before);
});

test('scheduler recovery BUSY stale scope preserves a legitimately queued current configuration event', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['native_lock'] = 'other'; $replacement = $before['state'];
    $replacement['epoch'] = wp_generate_uuid4(); $replacement['reason'] = 'new_scope'; $current_due = time() + 90;
    sr_query_once('GET_LOCK(', static function () use (&$replacement, $current_due): void {
        update_option(Settings::LANE_OPTION, 'new-current-lane', false);
        $replacement['scope'] = (new ReflectionMethod(FromSynchronizer::class, 'scope'))->invoke(null);
        $replacement['next_due'] = $current_due;
        update_option(FromSynchronizer::STATE_OPTION, $replacement, false);
        wp_schedule_single_event($current_due, FromSynchronizer::HOOK);
    });
    FromSynchronizer::run();
    expect(count($GLOBALS['dbt']['scheduled']) === 1 && wp_next_scheduled(FromSynchronizer::HOOK) === $current_due);
    expect(get_option(FromSynchronizer::STATE_OPTION) === $replacement && $GLOBALS['dbt']['requests'] === []); sr_protected($before);
});

test('scheduler recovery BUSY newly blocked state cannot receive an unguarded continuation', function (): void {
    $before = sr_fixture(); $GLOBALS['dbt']['native_lock'] = 'other'; $replacement = $before['state']; $replacement['status'] = 'configuration_required';
    sr_query_once('GET_LOCK(', static function () use ($replacement): void { update_option(FromSynchronizer::STATE_OPTION, $replacement, false); });
    FromSynchronizer::run();
    expect($GLOBALS['dbt']['scheduled'] === [] && get_option(FromSynchronizer::STATE_OPTION) === $replacement); sr_protected($before);
});
