<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DiscussionBridge\WordPress\Materializer;
use DiscussionBridge\WordPress\Admin;
use DiscussionBridge\WordPress\Plugin;
use DiscussionBridge\WordPress\Presentation;
use DiscussionBridge\WordPress\Publisher;
use DiscussionBridge\WordPress\Settings;
use DiscussionBridge\WordPress\WpDiscourseGuard;
use DiscussionBridge\WordPress\Client;
use DiscussionBridge\WordPress\PublishedContent;

$tests = [];
function test(string $name, callable $body): void { global $tests; $tests[] = [$name, $body]; }
function expect(bool $condition, string $message = 'expectation failed'): void { if (!$condition) throw new RuntimeException($message); }
function expect_error(mixed $value, string $code): void { expect($value instanceof WP_Error, 'expected WP_Error'); expect($value->get_error_code() === $code, 'expected ' . $code . ', got ' . $value->get_error_code()); }
function configured_post(int $id = 1, string $status = 'publish'): WP_Post { $post = new WP_Post($id, $status, 'post', 'Bridge post', '<p>Body</p>', 9, 'bridge-post'); $GLOBALS['dbt']['posts'][$id] = $post; $GLOBALS['dbt']['meta'][$id][Publisher::ENABLED_META] = '1'; return $post; }
function source_success(string $url, array $args): array {
    unset($url);
    $record = json_decode($args['body'], true)['bridge_record'];
    return dbt_response(200, ['outcome' => 'resolved', 'resource_id' => '33333333-3333-4333-8333-333333333333',
        'topic_id' => 12, 'topic_url' => 'https://bridge.example/t/bridge-post/12', 'core_fallback' => false,
        'source_revision' => $record['source_publication']['revision'], 'source_publication_outcome' => 'unchanged']);
}
function valid_record(string $resource = '22222222-2222-4222-8222-222222222222'): array { return [
    'state' => 'healthy', 'direction' => 'from_discourse', 'resource_id' => $resource,
    'title' => 'From The Bridge', 'content_html' => '<h2>Portable</h2><script>bad()</script><p>Safe body</p>',
    'topic_id' => 42, 'topic_url' => 'https://bridge.example/t/from-the-bridge/42',
    'source' => ['platform' => 'discourse', 'origin' => 'https://bridge.example', 'topic_id' => 42,
        'topic_url' => 'https://bridge.example/t/from-the-bridge/42', 'post_id' => 99, 'post_version' => 3,
        'revision' => 'post:99:version:3', 'updated_at' => '2026-09-02T12:00:00Z',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil']],
    'bindings' => [['role' => 'presentation', 'state' => 'active', 'native_materialization' => true,
        'canonical_url' => 'https://wordpress.example/from-the-bridge/']],
]; }

test('package, plugin, block and asset versions agree', function (): void {
    $plugin = file_get_contents(__DIR__ . '/../wordpress-discussion-bridge.php');
    $block = json_decode((string) file_get_contents(__DIR__ . '/../blocks/from-discourse/block.json'), true);
    $asset = require __DIR__ . '/../blocks/from-discourse/index.asset.php';
    expect(str_contains((string) $plugin, 'Version: ' . DISCUSSIONBRIDGE_WORDPRESS_VERSION));
    expect(($block['version'] ?? null) === DISCUSSIONBRIDGE_WORDPRESS_VERSION);
    expect(($asset['version'] ?? null) === DISCUSSIONBRIDGE_WORDPRESS_VERSION);
});

test('operator results translate protocol outcomes into direct language', function (): void {
    expect(Admin::operator_result_label('materialized', '') === 'Post created');
    expect(Admin::operator_result_label('created', 'bridge_record_created') === 'Discourse topic created');
    expect(Admin::operator_result_label('resolved', 'existing_bridge_record') === 'Existing Discourse topic found');
    expect(Admin::operator_result_label('failed', 'unauthorized') === 'Delivery failed');
});

test('discussion mode accepts canonical Interactive and fails closed otherwise', function (): void {
    dbt_reset();
    expect(Settings::sanitize_comments_mode('interactive') === 'interactive');
    expect(Settings::comments_mode() === 'interactive');
    foreach (['fullInteractive', 'full_interactive', 'bridge'] as $mode) {
        expect(Settings::sanitize_comments_mode($mode) === 'none');
        $GLOBALS['dbt']['options'][Settings::COMMENTS_MODE_OPTION] = $mode;
        expect(Settings::comments_mode() === 'none');
    }
    expect(count($GLOBALS['dbt']['settings_errors']) === 6);
});

test('service author must exist and be able to publish', function (): void {
    dbt_reset();
    expect(Settings::service_author_id() === 7);
    $GLOBALS['dbt']['users'][7]->capabilities = [];
    expect(Settings::service_author_id() === 0);
    expect(Settings::sanitize_service_author('missing') === '');
});

test('published opted-in editions queue once; draft, imported and disabled do not', function (): void {
    dbt_reset(); $post = configured_post();
    Publisher::post_saved(1, $post, true, new WP_Post(1, 'draft'));
    expect(count($GLOBALS['dbt']['scheduled']) === 1);
    wp_clear_scheduled_hook(Publisher::DELIVERY_HOOK, [1]);
    Publisher::post_saved(1, $post, true, new WP_Post(1, 'publish'));
    expect(count($GLOBALS['dbt']['scheduled']) === 1, 'published source edit was not queued');
    update_post_meta(1, Materializer::IMPORTED_META, '1');
    wp_clear_scheduled_hook(Publisher::DELIVERY_HOOK, [1]);
    Publisher::post_saved(1, $post, true, new WP_Post(1, 'publish'));
    expect($GLOBALS['dbt']['scheduled'] === [], 'imported source looped into To Discourse');
    delete_post_meta(1, Materializer::IMPORTED_META);
    Publisher::queue(1, false);
    Publisher::post_saved(1, $post, true, new WP_Post(1, 'publish'));
    expect(count($GLOBALS['dbt']['scheduled']) === 1);
    $post->post_status = 'draft'; Publisher::post_saved(1, $post, true, null);
    unset($GLOBALS['dbt']['meta'][1][Publisher::ENABLED_META]); $post->post_status = 'publish'; Publisher::post_saved(1, $post, true, null);
    expect(count($GLOBALS['dbt']['scheduled']) === 1);
});

test('published Gutenberg post queues after its opt-in meta becomes available', function (): void {
    dbt_reset();
    $post = new WP_Post(7, 'publish', 'post', 'WordPress Sandbox', '<p>Body</p>', 7, 'wordpress-sandbox');
    $GLOBALS['dbt']['posts'][7] = $post;
    $_POST = [
        'discussionbridge_post_nonce' => 'valid',
        'discussionbridge_enabled' => '1',
        'discussionbridge_comments_mode' => 'interactive',
    ];
    Admin::save_meta_box(7, $post);
    expect(get_post_meta(7, Publisher::ENABLED_META, true) === '1', 'opt-in meta was not saved');
    expect(get_post_meta(7, '_discussionbridge_status', true) === 'queued', 'post was not queued after meta save');
    expect(count($GLOBALS['dbt']['scheduled']) === 1, 'expected one recovery delivery event');
    Admin::save_meta_box(7, $post);
    expect(count($GLOBALS['dbt']['scheduled']) === 1, 'duplicate delivery event was scheduled');
    $_POST = [];
});

test('configured WP Discourse blocks delivery before network', function (): void {
    dbt_reset(); configured_post();
    $GLOBALS['dbt']['options']['discourse_connect'] = ['url' => 'https://forum.example', 'api-key' => 'key', 'publish-username' => 'system'];
    expect(WpDiscourseGuard::conflicts(1));
    Publisher::deliver(1);
    expect(count($GLOBALS['dbt']['requests']) === 0);
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'wp_discourse_conflict');
});

test('delivery uses stable identity, protected header and validates success', function (): void {
    dbt_reset(); configured_post();
    $success = ['outcome' => 'created', 'resource_id' => '33333333-3333-4333-8333-333333333333', 'topic_id' => 12,
        'topic_url' => 'https://bridge.example/t/bridge-post/12', 'core_fallback' => false];
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = 'source_success';
    update_post_meta(1, '_discussionbridge_correlation_id', 'wp-first');
    Publisher::deliver(1); Publisher::deliver(1);
    expect(count($GLOBALS['dbt']['requests']) === 2);
    $first = $GLOBALS['dbt']['requests'][0][1]; $second = $GLOBALS['dbt']['requests'][1][1];
    $a = json_decode($first['body'], true)['bridge_record']; $b = json_decode($second['body'], true)['bridge_record'];
    expect($a['external_id'] === $b['external_id'] && $a['canonical_url'] === $b['canonical_url']);
    expect(($first['headers']['X-DiscussionBridge-Secret'] ?? '') === str_repeat('s', 43));
    expect(!str_contains($first['body'], str_repeat('s', 43)), 'secret leaked into request body');
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, array_merge($success, ['core_fallback' => true]));
    Publisher::deliver(1);
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'invalid_success_response');
});

test('To source editions retain native dates, accepted CAS and stable topic identity', function (): void {
    dbt_reset(); $post = configured_post();
    $post->post_date_gmt = '2026-07-01 10:00:00';
    $post->post_modified_gmt = '2026-10-10 12:00:00';
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = 'source_success';
    Publisher::deliver(1);
    $first = json_decode($GLOBALS['dbt']['requests'][0][1]['body'], true)['bridge_record'];
    expect($first['source_publication']['previous_revision'] === null);
    expect($first['source_publication']['created_at'] === '2026-07-01T10:00:00Z');
    expect($first['source_publication']['updated_at'] === '2026-10-10T12:00:00Z');
    $post->post_title = 'Changed native title';
    $post->post_content = '<p>Changed \\math &amp; body.</p>';
    $post->post_modified_gmt = '2026-10-12 09:00:00';
    $GLOBALS['dbt']['responses'][$url] = static function (string $url, array $args): array {
        $response = source_success($url, $args);
        $data = json_decode($response['body'], true);
        $data['topic_url'] = 'https://bridge.example/t/changed-native-title/12';
        $data['source_publication_outcome'] = 'updated';
        return dbt_response(200, $data);
    };
    Publisher::deliver(1);
    $second = json_decode($GLOBALS['dbt']['requests'][1][1]['body'], true)['bridge_record'];
    expect($second['source_publication']['previous_revision'] === $first['source_publication']['revision']);
    expect($second['source_publication']['revision'] !== $first['source_publication']['revision']);
    expect(get_post_meta(1, '_discussionbridge_topic_id', true) === 12);
    expect(get_post_meta(1, '_discussionbridge_topic_url', true) === 'https://bridge.example/t/changed-native-title/12');
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
    $GLOBALS['dbt']['permalink_prefix'] = 'moved/';
    Publisher::deliver(1);
    expect(count($GLOBALS['dbt']['requests']) === 2, 'canonical migration made a new remote claim');
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'source_canonical_drift');
});

test('oversized native source is preserved with verified bounded HTML and JSON excerpts', function (): void {
    foreach ([str_repeat('é &amp; \\ value ', 8000), str_repeat('"\\', 21000)] as $body) {
        dbt_reset(); $post = configured_post();
        $post->post_content = '<div><p>' . $body . '</p></div>';
        $original = $post->post_content;
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
        $GLOBALS['dbt']['responses'][$url] = 'source_success';
        Publisher::deliver(1);
        expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
        $request = $GLOBALS['dbt']['requests'][0][1]['body'];
        $record = json_decode($request, true)['bridge_record'];
        expect(strlen($request) <= 65536);
        expect(strlen($record['content_html']) <= PublishedContent::MAX_BYTES);
        expect(preg_match('//u', $record['content_html']) === 1);
        expect($record['source_publication']['excerpted'] === true);
        expect($record['source_publication']['content_sha256'] === hash('sha256', $original));
        expect($record['source_publication']['content_bytes'] === strlen($original));
        expect(str_ends_with($record['content_html'], '<p><a href="https://wordpress.example/bridge-post/">Read More</a></p>'));
        expect($record['source_publication']['created_at'] === null && $record['source_publication']['updated_at'] === null);
        expect($post->post_content === $original, 'delivery replaced native source with an excerpt');
    }
});

test('accepted receipt failure leaves an exact retry and healthy receipts do not exhaust failed-attempt budget', function (): void {
    dbt_reset(); $post = configured_post();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = 'source_success';
    $GLOBALS['dbt']['meta_write_callback'] = static fn(int $id, string $name, mixed $value): bool => $name !== '_discussionbridge_resource_id';
    Publisher::deliver(1);
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'attention');
    expect(get_post_meta(1, '_discussionbridge_source_revision', true) === '');
    $GLOBALS['dbt']['meta_write_callback'] = null;
    Publisher::deliver(1);
    $a = json_decode($GLOBALS['dbt']['requests'][0][1]['body'], true)['bridge_record'];
    $b = json_decode($GLOBALS['dbt']['requests'][1][1]['body'], true)['bridge_record'];
    expect($a['source_publication'] === $b['source_publication'], 'receipt retry changed the source request');
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
    for ($i = 0; $i < 5; $i++) {
        Publisher::deliver(1);
        expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
        expect(get_post_meta(1, '_discussionbridge_attempts', true) === 0);
    }
    expect(count($GLOBALS['dbt']['requests']) === 7);
    $post->post_content = '<p>New edition</p>';
    Publisher::deliver(1);
    expect(count($GLOBALS['dbt']['requests']) === 8);
    expect(get_post_meta(1, '_discussionbridge_attempts', true) === 0);
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
});

test('an edit during receiver acceptance retains its accepted CAS and queues the later edition', function (): void {
    dbt_reset(); configured_post();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = static function (string $url, array $args): array {
        $GLOBALS['dbt']['posts'][1]->post_content = '<p>Edited during delivery</p>';
        return source_success($url, $args);
    };
    Publisher::deliver(1);
    $accepted = get_post_meta(1, '_discussionbridge_source_revision', true);
    expect(preg_match('/\A[a-f0-9]{64}\z/D', $accepted) === 1);
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'queued');
    $GLOBALS['dbt']['scheduled'] = [];
    $GLOBALS['dbt']['responses'][$url] = 'source_success';
    Publisher::deliver(1);
    $next = json_decode($GLOBALS['dbt']['requests'][1][1]['body'], true)['bridge_record'];
    expect($next['source_publication']['previous_revision'] === $accepted);
    expect($next['source_publication']['revision'] !== $accepted);
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
});

test('transient transport and receiver failures stop after three persisted attempts while terminal errors do not retry', function (): void {
    foreach (['transport', 408, 429, 500, 502, 503, 504, 401, 409, 422] as $status) {
        dbt_reset(); configured_post();
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
        $GLOBALS['dbt']['responses'][$url] = $status === 'transport'
            ? new WP_Error('connection_reset') : dbt_response($status, ['outcome' => 'rejected', 'reason' => 'receiver_unavailable']);
        $transient = $status === 'transport' || in_array($status, [408, 429, 500, 502, 503, 504], true);
        Publisher::deliver(1);
        expect(get_post_meta(1, '_discussionbridge_status', true) === ($transient ? 'retrying' : 'attention'));
        expect(count($GLOBALS['dbt']['scheduled']) === ($transient ? 1 : 0));
        if (!$transient) continue;
        for ($attempt = 2; $attempt <= 3; $attempt++) {
            $GLOBALS['dbt']['scheduled'] = []; // A native Cron worker consumed the preceding event.
            Publisher::deliver(1);
            expect((int) get_post_meta(1, '_discussionbridge_attempts', true) === $attempt);
        }
        expect(get_post_meta(1, '_discussionbridge_status', true) === 'attention');
        expect($GLOBALS['dbt']['scheduled'] === [] && count($GLOBALS['dbt']['requests']) === 3);
        Publisher::deliver(1);
        expect(count($GLOBALS['dbt']['requests']) === 3, 'fourth delivery dispatched after the attempt ceiling');
        expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'retry_limit_reached');
        Publisher::queue(1, true);
        $GLOBALS['dbt']['responses'][$url] = 'source_success';
        Publisher::deliver(1);
        expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
    }
});

test('renderer selection is off for new imports and preserves legacy and explicit operator choices', function (): void {
    dbt_reset(); expect(Materializer::materialize(valid_record()) === 'created');
    $GLOBALS['dbt']['queried_id'] = 100;
    Presentation::enqueue_mapped_discussion();
    expect(!in_array('discussionbridge-rich-content', $GLOBALS['dbt']['scripts'], true));
    update_post_meta(100, Presentation::RICH_CONTENT_META, 'enabled');
    expect(Presentation::rich_content_enabled(100));
    $record = valid_record(); $record['source']['post_version'] = 4; $record['source']['revision'] = 'post:99:version:4';
    $record['content_html'] = '<p>Changed source</p>';
    expect(Materializer::materialize($record) === 'updated');
    expect(Presentation::rich_content_enabled(100), 'source update erased operator rendering choice');
    delete_post_meta(100, Presentation::RICH_CONTENT_META);
    expect(Presentation::rich_content_enabled(100), 'legacy import renderer was silently suppressed');
    $GLOBALS['dbt']['options'][Settings::RICH_CONTENT_OPTION] = 'disabled';
    expect(!Presentation::rich_content_enabled(100));
    update_post_meta(100, Presentation::RICH_CONTENT_META, 'enabled');
    expect(Presentation::rich_content_enabled(100));
});

test('native operator content drift and copied ownership cannot be silently overwritten', function (): void {
    dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    $GLOBALS['dbt']['posts'][100]->post_content = '<p>An operator native edit</p>';
    $record['content_html'] = '<p>A later source edit</p>';
    $record['source']['post_version'] = 4; $record['source']['revision'] = 'post:99:version:4';
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_native_drift');
    expect($GLOBALS['dbt']['posts'][100]->post_content === '<p>An operator native edit</p>');
    delete_post_meta(100, Materializer::IMPORTED_META);
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_ownership');
});

test('resource census finds copied ownership in unselected native types and auto-drafts before mutation', function (): void {
    foreach (['page', 'attachment'] as $type) {
        dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
        $copy = clone $GLOBALS['dbt']['posts'][100]; $copy->ID = 101; $copy->post_type = $type; $copy->post_status = 'auto-draft';
        $GLOBALS['dbt']['posts'][101] = $copy; $GLOBALS['dbt']['meta'][101] = $GLOBALS['dbt']['meta'][100];
        $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
        expect_error(Materializer::materialize($record), 'discussionbridge_materialization_collision');
        $record['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('c', 64)];
        expect_error(Materializer::withdraw($record), 'discussionbridge_materialization_collision');
        expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before, 'ambiguous census mutated native state');
    }
});

test('changed native type and destination selection retain the original identity without adoption or withdrawal', function (): void {
    dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    $GLOBALS['dbt']['posts'][100]->post_type = 'page';
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_post_type');
    $withdrawn = $record; $withdrawn['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('c', 64)];
    expect_error(Materializer::withdraw($withdrawn), 'discussionbridge_materialization_post_type');
    expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before, 'unselected destination was mutated');
    $GLOBALS['dbt']['options'][Settings::POST_TYPES_OPTION] = ['page'];
    expect(Materializer::materialize($record) === 'unchanged');
    expect(count($GLOBALS['dbt']['posts']) === 1 && $GLOBALS['dbt']['posts'][100]->post_type === 'page');
});

test('unregistered native types and statuses retain durable ownership and expose copied claims', function (): void {
    dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    $durable = clone $GLOBALS['dbt']['posts'][100]; $durable->ID = 4294967295;
    $durable->post_type = 'retired_catalog_item'; $durable->post_status = 'retired_custom_status';
    $GLOBALS['dbt']['posts'][$durable->ID] = $durable; $GLOBALS['dbt']['meta'][$durable->ID] = $GLOBALS['dbt']['meta'][100];
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_collision');
    $withdrawn = $record; $withdrawn['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('c', 64)];
    expect_error(Materializer::withdraw($withdrawn), 'discussionbridge_materialization_collision');
    expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before);
    unset($GLOBALS['dbt']['posts'][100], $GLOBALS['dbt']['meta'][100]);
    $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_post_type');
    expect_error(Materializer::withdraw($withdrawn), 'discussionbridge_materialization_post_type');
    expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before);
    expect($GLOBALS['dbt']['deleted'] === [], 'unregistered durable object was deleted');
});

test('orphan and mismatched durable native identities never authorize replacement or withdrawal', function (): void {
    foreach (['orphan', 'mismatched-id'] as $condition) {
        dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
        if ($condition === 'orphan') unset($GLOBALS['dbt']['posts'][100]);
        else $GLOBALS['dbt']['posts'][100]->ID = 101;
        $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
        expect_error(Materializer::materialize($record), 'discussionbridge_materialization_ownership');
        $record['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('c', 64)];
        expect_error(Materializer::withdraw($record), 'discussionbridge_withdrawal_ownership');
        expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before);
        expect($GLOBALS['dbt']['deleted'] === [], 'orphan evidence was deleted');
    }
});

test('native prepared census uses the site table and bounded distinct IDs while malformed or failed queries refuse all mutation', function (): void {
    dbt_reset(); $GLOBALS['wpdb']->postmeta = 'fixture_site27_postmeta';
    $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    expect(str_starts_with($GLOBALS['dbt']['census_queries'][0], 'SELECT DISTINCT post_id FROM fixture_site27_postmeta '));
    expect(str_ends_with($GLOBALS['dbt']['census_queries'][0], 'ORDER BY post_id ASC LIMIT 2'));
    expect(!str_contains($GLOBALS['dbt']['census_queries'][0], 'post_type') && !str_contains($GLOBALS['dbt']['census_queries'][0], 'post_status'));
    if (class_exists('SQLite3')) {
        $GLOBALS['dbt']['extra_postmeta_rows'][] = [100, '_discussionbridge_resource_id', $record['resource_id']];
        expect(Materializer::materialize($record) === 'unchanged', 'duplicate metadata rows for one native ID became competing owners');
    }
    foreach ([null, [0], ['0'], ['00100'], ['100oops'], ['9223372036854775808'], [100, 100], [100, 101, 102], [true], [[]], 'database-error', 'exception'] as $result) {
        dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
        $before = serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]);
        $GLOBALS['dbt']['census_override'] = static function (DBT_WPDB $database, string $query) use ($result): mixed {
            unset($query);
            if ($result === 'exception') throw new RuntimeException('synthetic read failure');
            if ($result === 'database-error') { $database->last_error = 'synthetic database failure'; return []; }
            return $result;
        };
        expect_error(Materializer::materialize($record), 'discussionbridge_materialization_census');
        $record['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('c', 64)];
        expect_error(Materializer::withdraw($record), 'discussionbridge_materialization_census');
        expect(serialize([$GLOBALS['dbt']['posts'], $GLOBALS['dbt']['meta']]) === $before);
        expect($GLOBALS['dbt']['deleted'] === [], 'failed census deleted a native object');
    }
});

test('metadata-only source date corrections retain exact dates, native identity and operator choices', function (): void {
    dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    update_post_meta(100, Presentation::COMMENTS_MODE_META, 'full');
    update_post_meta(100, Presentation::RICH_CONTENT_META, 'enabled');
    $record['source']['updated_at'] = '2026-09-02T12:00:00.123456789Z';
    $record['source']['created_at'] = '2026-08-01T01:02:03.123Z';
    expect(Materializer::materialize($record) === 'updated');
    expect(get_post_meta(100, '_discussionbridge_source_updated_at', true) === $record['source']['updated_at']);
    expect(get_post_meta(100, '_discussionbridge_source_created_at', true) === $record['source']['created_at']);
    expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === 'full');
    expect(get_post_meta(100, Presentation::RICH_CONTENT_META, true) === 'enabled');
    expect(count($GLOBALS['dbt']['posts']) === 1 && get_permalink(100) === 'https://wordpress.example/from-the-bridge/');
    expect(Materializer::materialize($record) === 'unchanged');
});

test('client rejects non-JSON success responses', function (): void {
    dbt_reset(); configured_post();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, '<html>wrong</html>', 'text/html');
    Publisher::deliver(1);
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'discussionbridge_invalid_response');
});

test('materialization uses configured local owner and visible source provenance', function (): void {
    dbt_reset(); $result = Materializer::materialize(valid_record());
    expect($result === 'created');
    $post = $GLOBALS['dbt']['posts'][100];
    expect($post->post_author === 7, 'source/request user became local owner');
    expect(str_contains($post->post_content, 'Source author:'));
    expect(str_contains($post->post_content, 'Phil'));
    expect(str_contains($post->post_content, 'post:99:version:3'));
    expect(!str_contains($post->post_content, '<script>'));
    expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === 'interactive');
    expect(Materializer::materialize(valid_record()) === 'unchanged');
});

test('materialization supports a WordPress-generated dated permalink', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['permalink_prefix'] = '2026/09/14/';
    $record = valid_record();
    $record['bindings'][0]['canonical_url'] = 'https://wordpress.example/2026/09/14/from-the-bridge/';
    expect(Materializer::materialize($record) === 'created');
    expect($GLOBALS['dbt']['posts'][100]->post_name === 'from-the-bridge');
    expect(get_permalink(100) === $record['bindings'][0]['canonical_url']);
    expect(Materializer::materialize($record) === 'unchanged');
});

test('materialization honors the site default and preserves per-post discussion choices on revision updates', function (): void {
    foreach (['none', 'simple', 'full', 'interactive'] as $mode) {
        dbt_reset();
        $GLOBALS['dbt']['options'][Settings::COMMENTS_MODE_OPTION] = $mode;
        $record = valid_record();
        expect(Materializer::materialize($record) === 'created');
        expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === $mode, 'creation replaced the configured default');

        $selected = $mode === 'simple' ? 'full' : 'simple';
        update_post_meta(100, Presentation::COMMENTS_MODE_META, $selected);
        $record['source']['post_version'] = 4;
        $record['source']['revision'] = 'post:99:version:4';
        $record['source']['updated_at'] = '2026-09-03T12:00:00Z';
        $record['content_html'] = '<p>Updated source body.</p>';
        expect(Materializer::materialize($record) === 'updated');
        expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === $selected, 'revision replaced the local discussion choice');
        expect(count($GLOBALS['dbt']['posts']) === 1);
        expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $record['resource_id']);
        expect(get_post_meta(100, '_discussionbridge_canonical_url', true) === $record['bindings'][0]['canonical_url']);
    }
});

test('native imported presentation appends discussion after provenance and avoids a second source first post', function (): void {
    foreach (['simple', 'full', 'interactive'] as $mode) {
        dbt_reset(); (new ReflectionProperty(Presentation::class, 'embed_enqueued'))->setValue(null, false);
        $record = valid_record(); expect(Materializer::materialize($record) === 'created');
        $GLOBALS['dbt']['queried_id'] = 100; update_post_meta(100, Presentation::COMMENTS_MODE_META, $mode);
        $GLOBALS['dbt']['responses']['https://bridge.example/t/42.json'] = dbt_response(200, [
            'post_stream' => ['stream' => [99, 100], 'posts' => [
                ['id' => 99, 'post_number' => 1, 'username' => 'source', 'cooked' => '<p>Source first post</p>'],
                ['id' => 100, 'post_number' => 2, 'username' => 'reply', 'cooked' => '<p>Local native reply</p>', 'created_at' => '2026-09-02T12:00:00Z'],
            ]],
        ]);
        Presentation::enqueue_mapped_discussion();
        $html = Presentation::append_mapped_discussion($GLOBALS['dbt']['posts'][100]->post_content);
        expect(substr_count($html, 'Safe body') === 1 && str_contains($html, 'Published from'));
        expect(strpos($html, 'Published from') < strpos($html, 'discussionbridge-discussion'));
        expect(!Presentation::native_comments_open(true, 100));
        if ($mode === 'simple') expect(str_contains($html, 'Local native reply') && !str_contains($html, 'Source first post'));
        else {
            expect(str_contains($html, 'data-discussionbridge-embed="' . $mode . '"'));
            expect(str_contains($html, 'class_name=discussion-bridge-source-presentation') === ($mode === 'interactive'));
        }
    }
    (new ReflectionProperty(Presentation::class, 'embed_enqueued'))->setValue(null, false);
});

test('legacy native adoption refuses unproven content or owner repair and retains identity', function (): void {
    dbt_reset(); $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $GLOBALS['dbt']['posts'][100]->post_author = 0;
    $GLOBALS['dbt']['posts'][100]->post_content = '<p>Legacy imported body without current provenance.</p>';
    delete_post_meta(100, '_discussionbridge_managed_native_sha256');
    $before_resource = get_post_meta(100, '_discussionbridge_resource_id', true);
    $before_url = get_post_meta(100, '_discussionbridge_canonical_url', true);
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_legacy_reconciliation');
    expect(count($GLOBALS['dbt']['posts']) === 1);
    expect($GLOBALS['dbt']['posts'][100]->post_author === 0);
    expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $before_resource);
    expect(get_post_meta(100, '_discussionbridge_canonical_url', true) === $before_url);
    expect($GLOBALS['dbt']['posts'][100]->post_content === '<p>Legacy imported body without current provenance.</p>');
});

test('materialization fails closed on missing author, collision and identity drift', function (): void {
    dbt_reset(); unset($GLOBALS['dbt']['options'][Settings::SERVICE_AUTHOR_OPTION]);
    expect_error(Materializer::materialize(valid_record()), 'discussionbridge_materialization_service_author');
    dbt_reset(); $record = valid_record();
    foreach ([20, 21] as $id) { $GLOBALS['dbt']['posts'][$id] = new WP_Post($id, 'publish'); update_post_meta($id, '_discussionbridge_resource_id', $record['resource_id']); }
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_collision');
    dbt_reset(); Materializer::materialize($record);
    update_post_meta(100, '_discussionbridge_source_revision', 'older');
    update_post_meta(100, '_discussionbridge_canonical_url', 'https://wordpress.example/changed/');
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_identity_drift');
});

test('materialization never reports success when WordPress cannot publish the draft', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['wp_update_callback'] = fn(array $data): WP_Error => new WP_Error('publish_failed', 'publish failed');
    expect_error(Materializer::materialize(valid_record()), 'publish_failed');
    expect($GLOBALS['dbt']['posts'] === [], 'failed materialization retained an unowned draft');
    expect($GLOBALS['dbt']['meta'] === [], 'failed materialization wrote healthy metadata');

    dbt_reset();
    $GLOBALS['dbt']['wp_update_callback'] = fn(array $data): int => (int) $data['ID'];
    expect_error(Materializer::materialize(valid_record()), 'discussionbridge_materialization_publish');
    expect($GLOBALS['dbt']['posts'] === [], 'unpublished materialization retained a draft');
});

test('materialization refuses publication when critical ownership metadata cannot persist', function (): void {
    foreach (['resource_id', 'topic_id', 'topic_url', 'canonical_url', 'source_revision', 'status'] as $key) {
        dbt_reset();
        $GLOBALS['dbt']['meta_write_callback'] = static fn(int $id, string $name, mixed $value): bool => $name !== '_discussionbridge_' . $key;
        expect_error(Materializer::materialize(valid_record()), 'discussionbridge_materialization_metadata');
        expect($GLOBALS['dbt']['posts'] === [], 'failed metadata write retained a publication');
        expect($GLOBALS['dbt']['meta'] === [], 'failed metadata write retained partial ownership');
        $GLOBALS['dbt']['meta_write_callback'] = null;
        expect(Materializer::materialize(valid_record()) === 'created', 'retry collided after failed creation');
        expect(count($GLOBALS['dbt']['posts']) === 1);
    }
});

test('materialization restores the previous owned post after a revision metadata failure', function (): void {
    dbt_reset();
    $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $before = clone $GLOBALS['dbt']['posts'][100];
    $before_meta = $GLOBALS['dbt']['meta'][100];
    $record['title'] = 'New source title';
    $record['content_html'] = '<p>New source body.</p>';
    $record['source']['post_version'] = 4;
    $record['source']['revision'] = 'post:99:version:4';
    $GLOBALS['dbt']['meta_write_callback'] = static fn(int $id, string $name, mixed $value): bool => !($name === '_discussionbridge_source_revision' && $value === 'post:99:version:4');
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_metadata');
    expect($GLOBALS['dbt']['posts'][100] == $before, 'partial update changed the previous native publication');
    expect($GLOBALS['dbt']['meta'][100] === $before_meta, 'partial update changed the previous mapping');
    $GLOBALS['dbt']['meta_write_callback'] = null;
    expect(Materializer::materialize($record) === 'updated');
    expect(Materializer::materialize($record) === 'unchanged', 'unchanged metadata returns were mistaken for failures');
});

test('same-revision native withdrawal and URL drift never report unchanged or republish', function (): void {
    foreach (['draft', 'private', 'trash'] as $status) {
        dbt_reset();
        $record = valid_record();
        expect(Materializer::materialize($record) === 'created');
        $GLOBALS['dbt']['posts'][100]->post_status = $status;
        $before = clone $GLOBALS['dbt']['posts'][100];
        expect_error(Materializer::materialize($record), 'discussionbridge_materialization_unpublished');
        expect($GLOBALS['dbt']['posts'][100] == $before, 'operator withdrawal was overwritten');
    }
    dbt_reset();
    $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $GLOBALS['dbt']['permalink_prefix'] = 'moved/';
    $before = clone $GLOBALS['dbt']['posts'][100];
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_permalink');
    expect($GLOBALS['dbt']['posts'][100] == $before);
});

test('selected native Latest uses exact source edits and retains ordinary WordPress query defaults', function (): void {
    dbt_reset(); $record = valid_record();
    $record['source']['updated_at'] = '2026-09-02T14:00:00+02:00';
    expect(Materializer::materialize($record) === 'created');
    expect(get_post_meta(100, '_discussionbridge_source_updated_at_timestamp', true) === (new DateTimeImmutable('2026-09-02T12:00:00Z'))->format('U.u'));
    $GLOBALS['wpdb'] = (object) ['postmeta' => 'wp_postmeta', 'posts' => 'wp_posts'];
    $clauses = ['join' => '', 'orderby' => 'wp_posts.post_date DESC'];
    expect(Materializer::source_time_query_clauses($clauses, new WP_Query()) === $clauses);
    $selected = Materializer::source_time_query_clauses($clauses, new WP_Query(['discussionbridge_source_modified' => true]));
    expect(str_contains($selected['join'], "meta_key = '_discussionbridge_source_updated_at_timestamp'"));
    expect(str_contains($selected['orderby'], 'COALESCE') && str_contains($selected['orderby'], 'post_modified_gmt'));
    $GLOBALS['dbt']['is_admin'] = true;
    expect(Materializer::source_time_query_clauses($clauses, new WP_Query(['discussionbridge_source_modified' => true])) === $clauses);
});

test('selected Latest keeps GMT fallback independent of database session timezone and exact pre-epoch fractions', function (): void {
    foreach ([
        '1969-12-31T23:59:59.123456789Z' => '-0.876543211',
        '1969-12-31T23:59:59.999999999Z' => '-0.000000001',
        '1969-12-31T23:59:59.000000001Z' => '-0.999999999',
        '1969-12-31T23:59:59Z' => '-1.000000',
        '1969-12-31T23:59:58.5Z' => '-1.500000',
        '1970-01-01T00:00:00.000000001Z' => '0.000000001',
    ] as $time => $expected) {
        dbt_reset(); $record = valid_record(); $record['source']['updated_at'] = $time;
        expect(Materializer::materialize($record) === 'created');
        expect(get_post_meta(100, '_discussionbridge_source_updated_at_timestamp', true) === $expected, 'wrong exact Unix decimal for ' . $time);
    }
    $clauses = Materializer::source_time_query_clauses(['join' => '', 'orderby' => ''], new WP_Query(['discussionbridge_source_modified' => true]));
    expect(str_contains($clauses['orderby'], "TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', wp_posts.post_modified_gmt)"));
    expect(!str_contains($clauses['orderby'], 'UNIX_TIMESTAMP('), 'GMT fallback depends on the database session timezone');
});

test('native edition metadata catches grace edits and preserves operator presentation', function (): void {
    dbt_reset();
    $record = valid_record();
    $record['source']['edition'] = str_repeat('a', 64);
    $record['source']['created_at'] = '2026-08-01T10:00:00Z';
    $record['source']['forum_name'] = 'A real source forum';
    $record['bindings'][0]['presentation_mode'] = 'full';
    expect(Materializer::materialize($record) === 'created');
    expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === 'full');
    expect(get_post_meta(100, '_discussionbridge_source_created_at', true) === $record['source']['created_at']);
    expect(str_contains($GLOBALS['dbt']['posts'][100]->post_content, 'A real source forum'));
    update_post_meta(100, Presentation::COMMENTS_MODE_META, 'simple');
    $record['source']['edition'] = str_repeat('b', 64);
    $record['title'] = 'A grace-period title';
    $record['content_html'] = '<p>Grace-period source edit.</p>';
    expect(Materializer::materialize($record) === 'updated');
    expect($GLOBALS['dbt']['posts'][100]->post_title === $record['title']);
    expect(get_post_meta(100, '_discussionbridge_source_revision', true) === 'post:99:version:3');
    expect(get_post_meta(100, '_discussionbridge_source_edition', true) === str_repeat('b', 64));
    expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === 'simple');
    expect(Materializer::materialize($record) === 'unchanged');
});

test('explicit source withdrawal and restoration retain the same owned native identity', function (): void {
    dbt_reset();
    $record = valid_record();
    $record['source']['edition'] = str_repeat('a', 64);
    expect(Materializer::materialize($record) === 'created');
    update_post_meta(100, Presentation::COMMENTS_MODE_META, 'simple');
    $withdrawn = $record;
    $withdrawn['content_html'] = null;
    $withdrawn['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('b', 64), 'reason' => 'source_unavailable'];
    expect(Materializer::withdraw($withdrawn) === 'withdrawn');
    expect($GLOBALS['dbt']['posts'][100]->post_status === 'draft');
    expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $record['resource_id']);
    expect(get_post_meta(100, '_discussionbridge_source_withdrawn', true) === '1');
    expect(Materializer::withdraw($withdrawn) === 'unchanged');
    $record['source']['edition'] = str_repeat('c', 64);
    expect(Materializer::materialize($record) === 'updated');
    expect($GLOBALS['dbt']['posts'][100]->post_status === 'publish');
    expect(get_post_meta(100, '_discussionbridge_source_withdrawn', true) === '');
    expect(get_post_meta(100, Presentation::COMMENTS_MODE_META, true) === 'simple');
    expect(count($GLOBALS['dbt']['posts']) === 1);
});

test('failed withdrawal recovery metadata does not leave unavailable source content published', function (): void {
    dbt_reset(); $record = valid_record(); expect(Materializer::materialize($record) === 'created');
    $record['content_html'] = null;
    $record['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('f', 64), 'reason' => 'source_unavailable'];
    $GLOBALS['dbt']['meta_write_callback'] = static fn(int $id, string $name, mixed $value): bool => $name !== '_discussionbridge_source_withdrawn';
    expect_error(Materializer::withdraw($record), 'discussionbridge_materialization_metadata');
    expect($GLOBALS['dbt']['posts'][100]->post_status === 'draft');
    expect(get_post_meta(100, '_discussionbridge_status', true) === 'attention');
    expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $record['resource_id']);
    $GLOBALS['dbt']['meta_write_callback'] = null;
    expect(Materializer::withdraw($record) === 'withdrawn');
    expect(get_post_meta(100, '_discussionbridge_withdrawal_previous_status', true) === 'publish');
    expect(Materializer::materialize(valid_record()) === 'updated');
    expect($GLOBALS['dbt']['posts'][100]->post_status === 'publish');
});

test('complete source chunks verify bytes and hydrate content larger than the old 64KiB cap', function (): void {
    dbt_reset();
    $record = valid_record();
    $html = '<p>' . str_repeat('complete source ', 10000) . '</p>';
    $edition = str_repeat('e', 64);
    $digest = hash('sha256', $html);
    $path = '/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '/content.json';
    $record['content_html'] = null;
    $record['source']['edition'] = $edition;
    $record['source']['status'] = 'active';
    $record['source']['content'] = ['bytes' => strlen($html), 'sha256' => $digest, 'path' => $path, 'chunk_bytes' => 32768];
    for ($offset = 0; $offset < strlen($html); $offset += 32768) {
        $chunk = substr($html, $offset, 32768);
        $next = $offset + strlen($chunk);
        $url = 'https://bridge.example' . $path . '?edition=' . $edition . '&offset=' . $offset;
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['resource_id' => $record['resource_id'],
            'edition' => $edition, 'offset' => $offset, 'total_bytes' => strlen($html), 'content_sha256' => $digest,
            'chunk_base64' => base64_encode($chunk), 'next_offset' => $next === strlen($html) ? null : $next]);
    }
    $hydrated = (new Client())->publication_content($record);
    expect(is_array($hydrated) && $hydrated['content_html'] === $html);
    expect(Materializer::materialize($hydrated) === 'created');
    expect(str_contains($GLOBALS['dbt']['posts'][100]->post_content, $html));
    $GLOBALS['dbt']['responses']['https://bridge.example' . $path . '?edition=' . $edition . '&offset=0'] = dbt_response(200,
        ['resource_id' => $record['resource_id'], 'edition' => $edition, 'offset' => 1, 'total_bytes' => strlen($html),
            'content_sha256' => $digest, 'chunk_base64' => base64_encode('bad'), 'next_offset' => 3]);
    expect_error((new Client())->publication_content($record), 'discussionbridge_invalid_content_chunk');
    $record['source']['content']['path'] = 'https://foreign.example/content';
    $before = count($GLOBALS['dbt']['requests']);
    expect_error((new Client())->publication_content($record), 'discussionbridge_invalid_content_descriptor');
    expect(count($GLOBALS['dbt']['requests']) === $before, 'descriptor caused an off-origin request');
});

test('live From rendering revalidates source eligibility before reusing a verified body', function (): void {
    dbt_reset(); $record = valid_record();
    $html = '<p>Source that may become private</p>';
    $record['content_html'] = null;
    $record['source']['status'] = 'active';
    $record['source']['edition'] = str_repeat('e', 64);
    $path = '/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '/content.json';
    $digest = hash('sha256', $html);
    $record['source']['content'] = ['bytes' => strlen($html), 'sha256' => $digest, 'path' => $path, 'chunk_bytes' => 32768];
    $detail = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=' . Client::PUBLICATION_CONTRACT;
    $chunk = 'https://bridge.example' . $path . '?edition=' . str_repeat('e', 64) . '&offset=0';
    $GLOBALS['dbt']['responses'][$detail] = dbt_response(200, ['bridge_record' => $record]);
    $GLOBALS['dbt']['responses'][$chunk] = dbt_response(200, ['resource_id' => $record['resource_id'], 'edition' => str_repeat('e', 64),
        'offset' => 0, 'total_bytes' => strlen($html), 'content_sha256' => $digest, 'chunk_base64' => base64_encode($html), 'next_offset' => null]);
    $attributes = ['resourceId' => $record['resource_id'], 'commentsMode' => 'none'];
    expect(str_contains(Presentation::render_block($attributes), 'Source that may become private'));
    expect(str_contains(Presentation::render_block($attributes), 'Source that may become private'));
    expect(count($GLOBALS['dbt']['requests']) === 3, 'same edition did not reuse verified body after fresh metadata');
    $record['source'] = ['status' => 'withdrawn', 'edition' => str_repeat('f', 64), 'reason' => 'source_unavailable'];
    $GLOBALS['dbt']['responses'][$detail] = dbt_response(200, ['bridge_record' => $record]);
    expect(Presentation::render_block($attributes) === '', 'private source reused a formerly public body');
    expect($GLOBALS['dbt']['transients'] === [], 'withdrawal retained a renderable body cache');
});

test('content hydration restarts at most three editions and rejects complete digest and UTF8 errors', function (): void {
    dbt_reset(); $record = valid_record();
    $record['content_html'] = null;
    $record['source']['edition'] = str_repeat('a', 64);
    $path = '/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '/content.json';
    $record['source']['content'] = ['bytes' => 4, 'sha256' => hash('sha256', 'body'), 'path' => $path, 'chunk_bytes' => 32768];
    $chunk = 'https://bridge.example' . $path . '?edition=' . str_repeat('a', 64) . '&offset=0';
    $detail = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=' . Client::PUBLICATION_CONTRACT;
    $GLOBALS['dbt']['responses'][$chunk] = dbt_response(409, ['outcome' => 'reconciliation_required', 'reason' => 'source_changed']);
    $GLOBALS['dbt']['responses'][$detail] = dbt_response(200, ['bridge_record' => $record]);
    expect_error((new Client())->publication_content($record), 'discussionbridge_source_changed');
    expect(count(array_filter($GLOBALS['dbt']['requests'], fn($request) => $request[0] === $chunk)) === 3);
    $GLOBALS['dbt']['responses'][$chunk] = dbt_response(200, ['resource_id' => $record['resource_id'], 'edition' => str_repeat('a', 64),
        'offset' => 0, 'total_bytes' => 4, 'content_sha256' => hash('sha256', 'body'), 'chunk_base64' => base64_encode('oops'), 'next_offset' => null]);
    expect_error((new Client())->publication_content($record), 'discussionbridge_invalid_content_digest');
    $bad = "\xFF\xFF\xFF\xFF";
    $record['source']['content']['sha256'] = hash('sha256', $bad);
    $GLOBALS['dbt']['responses'][$chunk] = dbt_response(200, ['resource_id' => $record['resource_id'], 'edition' => str_repeat('a', 64),
        'offset' => 0, 'total_bytes' => 4, 'content_sha256' => hash('sha256', $bad), 'chunk_base64' => base64_encode($bad), 'next_offset' => null]);
    expect_error((new Client())->publication_content($record), 'discussionbridge_invalid_content_digest');
});

test('publication sync completes page 101 and rejects inconsistent pagination', function (): void {
    dbt_reset();
    for ($page = 1; $page <= 101; $page++) {
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records.json?page=' . $page . '&publication_contract=' . Client::PUBLICATION_CONTRACT . ($page > 1 ? '&snapshot=snap' : '');
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, [
            'bridge_records' => [],
            'pagination' => ['page' => $page, 'pages' => 101, 'total' => 0, 'snapshot' => 'snap'],
        ]);
    }
    $totals = Materializer::sync();
    expect($totals['failed'] === 0, 'valid page 101 was rejected');
    expect(count($GLOBALS['dbt']['requests']) === 101, 'publication feed was truncated before page 101');

    dbt_reset();
    foreach ([1 => 2, 2 => 3] as $page => $pages) {
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records.json?page=' . $page . '&publication_contract=' . Client::PUBLICATION_CONTRACT . ($page > 1 ? '&snapshot=snap' : '');
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, [
            'bridge_records' => [],
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => 0, 'snapshot' => 'snap'],
        ]);
    }
    $totals = Materializer::sync();
    expect($totals['failed'] === 1, 'inconsistent pagination was accepted');
});

test('block and shortcode render sanitized credential-free From Discourse content', function (): void {
    dbt_reset(); $record = valid_record();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=0.3.0-alpha.1';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
    $block = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => 'none']);
    $shortcode = Presentation::shortcode(['resource_id' => $record['resource_id'], 'comments' => 'none']);
    expect($block === $shortcode && str_contains($block, 'Safe body'));
    expect(!str_contains($block, '<script>') && !str_contains($block, str_repeat('s', 43)));
    expect(str_contains((string) json_encode($GLOBALS['dbt']['requests']), str_repeat('s', 43)), 'authenticated request omitted protected header');
});

test('unsupported block and shortcode modes cannot enable Interactive', function (): void {
    dbt_reset(); $record = valid_record();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=0.3.0-alpha.1';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
    foreach (['fullInteractive', 'full_interactive'] as $mode) {
        $html = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => $mode]);
        $shortcode = Presentation::shortcode(['resource_id' => $record['resource_id'], 'comments' => $mode]);
        expect($html === $shortcode && str_contains($html, 'discussionbridge-record--none'));
        expect(!str_contains($html, 'id="discourse-comments"'));
        expect($GLOBALS['dbt']['scripts'] === []);
    }
});

test('explicit invalid block attributes never inherit the absent Interactive default', function (): void {
    foreach ([null, '', false, 0, [], (object) [], 'bridge', 'fullInteractive', 'full_interactive'] as $mode) {
        dbt_reset(); $record = valid_record();
        (new ReflectionProperty(Presentation::class, 'embed_enqueued'))->setValue(null, false);
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=0.3.0-alpha.1';
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
        $html = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => $mode]);
        expect(str_contains($html, 'Safe body'), 'invalid mode must preserve authorized source content');
        expect(str_contains($html, 'discussionbridge-record--none'), 'explicit invalid mode inherited a default');
        expect(!str_contains($html, 'id="discourse-comments"'), 'invalid mode displayed an embed');
        expect($GLOBALS['dbt']['scripts'] === [], 'invalid mode enqueued a script or Interactive config');
        expect(($GLOBALS['dbt']['inline'] ?? []) === [], 'invalid mode emitted Interactive configuration');
        expect($GLOBALS['dbt']['meta'] === [], 'invalid mode converted saved values');
    }
});

test('absent and canonical block modes preserve their distinct presentation behavior', function (): void {
    foreach (['absent' => null, 'interactive' => 'interactive', 'full' => 'full', 'none' => 'none'] as $case => $mode) {
        dbt_reset(); $record = valid_record();
        (new ReflectionProperty(Presentation::class, 'embed_enqueued'))->setValue(null, false);
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=0.3.0-alpha.1';
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
        $attributes = ['resourceId' => $record['resource_id']];
        if ($case !== 'absent') { $attributes['commentsMode'] = $mode; }
        $html = Presentation::render_block($attributes);
        $expected = $case === 'absent' ? 'interactive' : $mode;
        expect(str_contains($html, 'discussionbridge-record--' . $expected));
        expect(str_contains($html, 'full_app=true') === ($expected === 'interactive'));
        expect(str_contains($html, 'data-discussionbridge-embed=') === ($expected !== 'none'));
        expect($GLOBALS['dbt']['meta'] === [], 'presentation converted saved values');
    }
    (new ReflectionProperty(Presentation::class, 'embed_enqueued'))->setValue(null, false);
});

test('Simple mode batches missing replies and exposes bounded Show more', function (): void {
    dbt_reset(); $record = valid_record();
    $record_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json?publication_contract=0.3.0-alpha.1';
    $topic_url = 'https://bridge.example/t/42.json';
    $posts_url = 'https://bridge.example/t/42/posts.json?post_ids%5B0%5D=4&post_ids%5B1%5D=5&post_ids%5B2%5D=6&post_ids%5B3%5D=7';
    $GLOBALS['dbt']['responses'][$record_url] = dbt_response(200, ['bridge_record' => $record]);
    $base = fn(int $id) => ['id' => $id, 'post_number' => $id, 'username' => 'reader', 'name' => 'Bridge Reader', 'cooked' => '<p>Comment ' . $id . '</p>', 'created_at' => '2026-09-02T12:00:00Z'];
    $GLOBALS['dbt']['responses'][$topic_url] = dbt_response(200, ['post_stream' => ['stream' => [1,2,3,4,5,6,7], 'posts' => [$base(2), $base(3)]]]);
    $GLOBALS['dbt']['responses'][$posts_url] = dbt_response(200, ['post_stream' => ['posts' => [$base(4),$base(5),$base(6),$base(7)]]]);
    $GLOBALS['dbt']['responses']['https://bridge.example/'] = new WP_Error('branding_unavailable');
    $html = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => 'simple']);
    expect(str_contains($html, 'Comment 7'));
    expect(str_contains($html, 'Show 1 more comment'));
    expect(count($GLOBALS['dbt']['requests']) === 4, 'expected record, topic, batched posts and branding requests');
});

test('activation and boot create only non-autoloaded encrypted credential storage', function (): void {
    dbt_reset(); unset($GLOBALS['dbt']['options']['discussionbridge_site_id']);
    Plugin::activate(); $first = $GLOBALS['dbt']['options']['discussionbridge_site_id']; Plugin::activate();
    expect($GLOBALS['dbt']['options']['discussionbridge_site_id'] === $first);
    expect(array_key_exists(Settings::CONNECTION_SECRET_OPTION, $GLOBALS['dbt']['options']));
    Plugin::boot();
    expect(count($GLOBALS['dbt']['actions']) >= 8 && count($GLOBALS['dbt']['filters']) >= 3);
});

test('activation and repeated boot preserve configured operator choices and never enqueue publication', function (): void {
    dbt_reset(); $GLOBALS['dbt']['options'][Settings::COMMENTS_MODE_OPTION] = 'full';
    $GLOBALS['dbt']['options'][Settings::RICH_CONTENT_OPTION] = 'enabled';
    $GLOBALS['dbt']['options'][Settings::POST_TYPES_OPTION] = ['page'];
    $GLOBALS['dbt']['options'][Settings::CONNECTION_SECRET_OPTION] = 'opaque-existing-ciphertext';
    $before = $GLOBALS['dbt']['options'];
    Plugin::activate(); Plugin::boot(); Plugin::activate();
    expect($GLOBALS['dbt']['options'] === $before, 'activation replaced existing choices or credentials');
    expect($GLOBALS['dbt']['scheduled'] === [] && $GLOBALS['dbt']['requests'] === [] && $GLOBALS['dbt']['posts'] === []);
});

test('admin credential storage encrypts, masks and preserves the connection secret', function (): void {
    dbt_reset();
    $secret = str_repeat('w', 43);
    $encrypted = Settings::sanitize_connection_secret($secret);
    expect($encrypted !== '' && $encrypted !== $secret, 'secret was stored as plaintext');
    expect(str_starts_with($encrypted, 'v1:'), 'encrypted credential envelope is unversioned');
    $GLOBALS['dbt']['options'][Settings::CONNECTION_SECRET_OPTION] = $encrypted;
    $decrypt = new ReflectionMethod(Settings::class, 'decrypt_secret');
    expect($decrypt->invoke(null, $encrypted) === $secret, 'encrypted credential did not round trip');
    expect(Settings::sanitize_connection_secret($encrypted) === $encrypted, 'repeated WordPress sanitizer pass re-encrypted the envelope');
    $encrypt = new ReflectionMethod(Settings::class, 'encrypt_secret');
    $legacy_double_encrypted = $encrypt->invoke(null, $encrypted);
    expect($decrypt->invoke(null, $legacy_double_encrypted) === $secret, 'existing double-encrypted credential was not recovered');
    $tampered = substr($encrypted, 0, -1) . ($encrypted[-1] === 'A' ? 'B' : 'A');
    expect($decrypt->invoke(null, $tampered) === '', 'tampered credential did not fail closed');
    expect(Settings::sanitize_connection_secret('') === $encrypted, 'blank settings save erased the credential');
    expect(Settings::connection_secret_source() === 'server_constant', 'protected server source lost precedence');
    expect(!str_contains(file_get_contents(__DIR__ . '/../src/Admin.php'), 'value="<?php echo Settings::connection_secret'), 'admin field redisplays secret');
});

test('admin credential storage rejects copied panels and malformed secrets', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['options'][Settings::CONNECTION_SECRET_OPTION] = 'retained-envelope';
    $copied_panel = 'dbc_aaaaaaaaaaaaaaaaaaaaaaaa' . "\n" . str_repeat('s', 43);
    expect(Settings::sanitize_connection_secret($copied_panel) === 'retained-envelope');
    expect(Settings::sanitize_connection_secret(str_repeat('s', 42)) === 'retained-envelope');
    expect(count($GLOBALS['dbt']['settings_errors']) === 2, 'malformed secret errors were not reported');
});

test('topic and platform URL identities reject encoded separators and traversal', function (): void {
    dbt_reset();
    foreach (['/t/a%2fb/42', '/t/a%252Fb/42', '/t/a%5cb/42', '/t/../42', '/t/bad%xx/42', '/t/a//42'] as $path) {
        expect(!Settings::topic_url_matches('https://bridge.example' . $path, 42), 'ambiguous topic URL was accepted');
    }
    expect(Settings::topic_url_matches('https://bridge.example/t/normal-title/42', 42));
    $record = valid_record(); $record['bindings'][0]['canonical_url'] = 'https://wordpress.example/path/%2e%2e/from-the-bridge/';
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_binding');
    foreach (['2130706433', '0x7f000001', '0177.0.0.1', 'forum.example.', 'bad_host.example'] as $host) {
        expect(Settings::sanitize_server_url('https://' . $host) === '');
    }
});

test('connection secret and lane match the receiver admission grammar', function (): void {
    expect(Settings::sanitize_lane('articles') === 'articles', 'valid lane rejected');
    expect(Settings::sanitize_lane('Bad Lane') === '', 'invalid lane accepted');
    $validator = new ReflectionMethod(Settings::class, 'validated_secret');
    expect($validator->invoke(null, str_repeat('s', 42)) === '', 'short secret accepted');
    expect($validator->invoke(null, str_repeat('é', 129)) === '', 'oversized byte secret accepted');
    expect($validator->invoke(null, str_repeat('s', 43)."\ninside") === '', 'control-bearing secret accepted');
    expect($validator->invoke(null, str_repeat('s', 43)) === str_repeat('s', 43), 'valid secret rejected');
});

// Test-only protected secret source. It is never an option or rendered value.
define('DISCUSSIONBRIDGE_CONNECTION_SECRET', str_repeat('s', 43));

$passed = 0;
test('source dates reject normalization and relative expressions before native mutation', function (): void {
    foreach (['2026-02-30T00:00:00Z', '2026-02-29T00:00:00Z', '2026-04-31T00:00:00Z', '2026-01-01T24:00:00Z', '2026-01-01T00:00:00+01:60', 'next Monday'] as $invalid) {
        foreach (['created_at', 'updated_at'] as $field) {
            dbt_reset(); $record = valid_record(); $record['source'][$field] = $invalid;
            expect_error(Materializer::materialize($record), 'discussionbridge_materialization_source');
            expect($GLOBALS['dbt']['posts'] === [], 'invalid source time mutated native posts');
        }
    }
    dbt_reset(); $record = valid_record();
    $record['source']['created_at'] = '2024-02-29T10:00:00.123456789+02:00';
    $record['source']['updated_at'] = '2024-02-29T08:00:00.123456788Z';
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_source');
    expect($GLOBALS['dbt']['posts'] === []);
    $record['source']['updated_at'] = '2024-02-29T08:00:00.123456789Z';
    expect(Materializer::materialize($record) === 'created');
    expect(get_post_meta(100, '_discussionbridge_source_updated_at', true) === $record['source']['updated_at']);
    expect(get_post_meta(100, '_discussionbridge_source_updated_at_timestamp', true) === '1709193600.123456789');
    $clauses = Materializer::source_time_query_clauses(['join' => '', 'orderby' => ''], new WP_Query(['discussionbridge_source_modified' => true]));
    expect(str_contains($clauses['join'], 'DECIMAL(23,9)'), 'native Latest discarded source fractional precision');
});

foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($tests) . " tests passed\n");
