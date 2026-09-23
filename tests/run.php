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
use DiscussionBridge\WordPress\ForumPublisher;
use DiscussionBridge\WordPress\PlatformCatalog;

$tests = [];
function test(string $name, callable $body): void { global $tests; $tests[] = [$name, $body]; }
function expect(bool $condition, string $message = 'expectation failed'): void { if (!$condition) throw new RuntimeException($message); }
function expect_error(mixed $value, string $code): void { expect($value instanceof WP_Error, 'expected WP_Error'); expect($value->get_error_code() === $code, 'expected ' . $code . ', got ' . $value->get_error_code()); }
function configured_post(int $id = 1, string $status = 'publish'): WP_Post { $post = new WP_Post($id, $status, 'post', 'Bridge post', '<p>Body</p>', 9, 'bridge-post'); $GLOBALS['dbt']['posts'][$id] = $post; $GLOBALS['dbt']['meta'][$id][Publisher::ENABLED_META] = '1'; return $post; }
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
function forum_resolve_result(array $publication, string $resource_id, string $outcome = 'created'): array { return [
    'outcome' => $outcome,
    'resource_id' => $resource_id,
    'publication_program' => in_array(
        $publication['publication_program'] ?? null,
        ['forum_sync_pending', 'forum_sync'],
        true
    ) ? $publication['publication_program'] : 'forum_sync_pending',
    'external_id' => $publication['external_id'],
    'canonical_url' => $publication['canonical_url'],
    'pending_publication_revision' => $publication['publication_revision'],
    'pending_mapping_revision' => $publication['mapping_revision'],
    'pending_destination' => $publication['destination'],
]; }
function forum_acknowledgement_result(string $resource_id, array $acknowledgement): array { return [
    'outcome' => 'acknowledged',
    'resource_id' => $resource_id,
    'destination_state' => in_array($acknowledgement['outcome'] ?? null, ['held', 'unpublished'], true) ? 'held' : 'healthy',
    'acknowledged_source_revision' => $acknowledgement['source_revision'] ?? null,
    'acknowledged_publication_revision' => $acknowledgement['publication_revision'],
    'acknowledged_mapping_revision' => $acknowledgement['mapping_revision'] ?? null,
]; }

test('package, plugin, block and asset versions agree', function (): void {
    $plugin = file_get_contents(__DIR__ . '/../wordpress-discussion-bridge.php');
    $admin = file_get_contents(__DIR__ . '/../src/Admin.php');
    $block = json_decode((string) file_get_contents(__DIR__ . '/../blocks/from-discourse/block.json'), true);
    $asset = require __DIR__ . '/../blocks/from-discourse/index.asset.php';
    expect(str_contains((string) $plugin, 'Version: ' . DISCUSSIONBRIDGE_WORDPRESS_VERSION));
    expect(str_contains((string) $admin, 'Advanced category route'));
    expect(str_contains((string) $admin, 'matching advanced route; otherwise leave blank'));
    expect(!str_contains((string) $admin, "__('Lane', 'discussionbridge')"));
    expect(($block['version'] ?? null) === DISCUSSIONBRIDGE_WORDPRESS_VERSION);
    expect(($asset['version'] ?? null) === DISCUSSIONBRIDGE_WORDPRESS_VERSION);
    $rich_content = (string) file_get_contents(__DIR__ . '/../assets/discussionbridge-rich-content.js');
    expect(str_contains($rich_content, 'discussionbridge-mermaid'));
    expect(str_contains($rich_content, 'discussionbridge-math'));
    expect(str_contains($rich_content, '.md-table'));
    expect(str_contains($rich_content, 'mathml'));
});

test('operator results translate protocol outcomes into direct language', function (): void {
    expect(Admin::operator_result_label('materialized', '') === 'WordPress post created');
    expect(Admin::operator_result_label('updated', '') === 'WordPress post updated');
    expect(Admin::operator_result_label('created', 'bridge_record_created') === 'Discourse topic created');
    expect(Admin::operator_result_label('resolved', 'existing_bridge_record') === 'Existing Discourse topic found');
    expect(Admin::operator_result_label('failed', 'unauthorized') === 'Delivery failed');
});

test('discussion mode emits Interactive and accepts the historical token', function (): void {
    dbt_reset();
    expect(Settings::sanitize_comments_mode('interactive') === 'interactive');
    expect(Settings::sanitize_comments_mode('fullInteractive') === 'interactive');
    expect(Settings::comments_mode() === 'interactive');
    expect(Settings::sanitize_comments_mode('bridge') === 'interactive');
});

test('service author must exist and be able to publish', function (): void {
    dbt_reset();
    expect(Settings::service_author_id() === 7);
    $GLOBALS['dbt']['users'][7]->capabilities = [];
    expect(Settings::service_author_id() === 0);
    expect(Settings::sanitize_service_author('missing') === '');
});

test('first publish queues once; draft, edit and disabled do not', function (): void {
    dbt_reset(); $post = configured_post();
    Publisher::post_saved(1, $post, true, new WP_Post(1, 'draft'));
    expect(count($GLOBALS['dbt']['scheduled']) === 1);
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
    dbt_reset(); $post = configured_post();
    $success = ['outcome' => 'created', 'resource_id' => '33333333-3333-4333-8333-333333333333', 'topic_id' => 12,
        'topic_url' => 'https://bridge.example/t/bridge-post/12', 'core_fallback' => false];
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/resolve.json';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(201, $success);
    update_post_meta(1, '_discussionbridge_correlation_id', 'wp-first');
    Publisher::deliver(1); Publisher::deliver(1);
    expect(count($GLOBALS['dbt']['requests']) === 2);
    $first = $GLOBALS['dbt']['requests'][0][1]; $second = $GLOBALS['dbt']['requests'][1][1];
    $a = json_decode($first['body'], true)['bridge_record']; $b = json_decode($second['body'], true)['bridge_record'];
    expect($a['external_id'] === $b['external_id'] && $a['canonical_url'] === $b['canonical_url']);
    expect(($first['headers']['X-DiscussionBridge-Secret'] ?? '') === str_repeat('s', 43));
    expect(!str_contains($first['body'], str_repeat('s', 43)), 'secret leaked into request body');
    expect(get_post_meta(1, '_discussionbridge_status', true) === 'healthy');
    $post->post_name = 'bridge-post-moved';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, array_merge($success, ['outcome' => 'resolved']));
    Publisher::deliver(1);
    $moved = json_decode($GLOBALS['dbt']['requests'][2][1]['body'], true)['bridge_record'];
    expect($moved['external_id'] === $a['external_id'], 'a slug move changed the native post identity');
    expect($moved['canonical_url'] === 'https://wordpress.example/bridge-post-moved/');
    expect(get_post_meta(1, '_discussionbridge_resource_id', true) === $success['resource_id']);
    expect(get_post_meta(1, '_discussionbridge_topic_id', true) === 12);
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, array_merge($success, [
        'outcome' => 'resolved', 'topic_id' => 13, 'topic_url' => 'https://bridge.example/t/bridge-post/13',
    ]));
    Publisher::deliver(1);
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'returned_identity_mismatch');
    expect(get_post_meta(1, '_discussionbridge_topic_id', true) === 12);
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, array_merge($success, ['core_fallback' => true]));
    Publisher::deliver(1);
    expect(get_post_meta(1, '_discussionbridge_last_reason', true) === 'invalid_success_response');
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

test('client accepts the endpoint-specific escaped source-detail envelope and preserves receiver reasons', function (): void {
    dbt_reset();
    $detail_url = 'https://bridge.example/discussion-bridge/v1/source-topics/42.json';
    $escaped_content = str_repeat("\x01", 49152);
    $GLOBALS['dbt']['responses'][$detail_url] = dbt_response(200, [
        'eligible' => true,
        'source_topic' => ['topic_id' => 42, 'content_html' => $escaped_content],
    ]);
    $result = (new Client())->source_topic(42);
    expect(is_array($result) && $result['source_topic']['content_html'] === $escaped_content);
    expect($GLOBALS['dbt']['requests'][0][1]['limit_response_size'] === 384 * 1024);

    $resolve_url = 'https://bridge.example/discussion-bridge/v1/source-topics/42/resolve.json';
    $GLOBALS['dbt']['responses'][$resolve_url] = dbt_response(422, [
        'outcome' => 'rejected', 'reason' => 'source_revision_changed',
    ]);
    $error = (new Client())->resolve_source_topic(42, []);
    expect_error($error, 'discussionbridge_source_revision_changed');
});

test('legacy materializer does not compete with the forum synchronization program', function (): void {
    dbt_reset();
    $record = valid_record();
    $record['publication_program'] = 'forum_sync';
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/bridge-records.json?page=1'] = dbt_response(200, [
        'bridge_records' => [$record],
        'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'stable'],
    ]);

    $result = Materializer::sync($failures);

    expect($result === ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0]);
    expect($failures === [] && $GLOBALS['dbt']['posts'] === []);
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

test('updating a mapped dated post preserves its authorized publication date', function (): void {
    dbt_reset();
    $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $post = $GLOBALS['dbt']['posts'][100];
    $post->post_date = '2026-09-14 20:58:09';
    $post->post_date_gmt = '2026-09-14 20:58:09';
    $GLOBALS['dbt']['dated_permalinks'] = true;
    $record['bindings'][0]['canonical_url'] = 'https://wordpress.example/2026/09/14/from-the-bridge/';
    update_post_meta(100, '_discussionbridge_canonical_url', $record['bindings'][0]['canonical_url']);
    $record['source']['revision'] = 'post:99:version:4';
    $record['source']['post_version'] = 4;
    $result = Materializer::materialize($record);
    expect($result === 'updated', 'expected updated, got ' . ($result instanceof WP_Error ? $result->get_error_code() : $result));
    expect($post->post_date === '2026-09-14 20:58:09', 'post_date changed');
    expect($post->post_date_gmt === '2026-09-14 20:58:09', 'post_date_gmt changed');
    expect(get_permalink(100) === $record['bindings'][0]['canonical_url'], 'dated permalink changed');
    expect(count($GLOBALS['dbt']['posts']) === 1, 'duplicate mapped post');
    expect(Materializer::materialize($record) === 'unchanged', 'exact retry was not unchanged');
});

test('same-revision materialization repairs a legacy authorless post without changing identity', function (): void {
    dbt_reset(); $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $GLOBALS['dbt']['posts'][100]->post_author = 0;
    $GLOBALS['dbt']['posts'][100]->post_content = '<p>Legacy imported body without current provenance.</p>';
    $before_resource = get_post_meta(100, '_discussionbridge_resource_id', true);
    $before_url = get_post_meta(100, '_discussionbridge_canonical_url', true);
    expect(Materializer::materialize($record) === 'updated');
    expect(count($GLOBALS['dbt']['posts']) === 1, 'repair created a duplicate post');
    expect($GLOBALS['dbt']['posts'][100]->post_author === 7, 'repair did not apply configured service author');
    expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $before_resource);
    expect(get_post_meta(100, '_discussionbridge_canonical_url', true) === $before_url);
    expect(str_contains($GLOBALS['dbt']['posts'][100]->post_content, 'Source author:'));
    expect(Materializer::materialize($record) === 'unchanged');
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

test('verified URL migration adopts the same already-moved WordPress post', function (): void {
    dbt_reset();
    $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $old_url = $record['bindings'][0]['canonical_url'];
    $new_url = 'https://wordpress.example/moved-from-the-bridge/';
    $record['bindings'][0]['canonical_url'] = $new_url;
    $record['bindings'][0]['url_migration'] = [
        'old_url' => $old_url, 'new_url' => $new_url,
        'redirect_status' => 301, 'verified_at' => '2026-09-16T12:00:00.000000Z',
    ];
    expect_error(Materializer::materialize($record), 'discussionbridge_materialization_identity_drift');
    $GLOBALS['dbt']['posts'][100]->post_name = 'moved-from-the-bridge';
    expect(Materializer::materialize($record) === 'updated');
    expect(count($GLOBALS['dbt']['posts']) === 1);
    expect(get_post_meta(100, '_discussionbridge_resource_id', true) === $record['resource_id']);
    expect(get_post_meta(100, '_discussionbridge_canonical_url', true) === $new_url);
    expect(Materializer::materialize($record) === 'unchanged');
});

test('verified dated URL migration keeps its original date and mapped post', function (): void {
    dbt_reset();
    $record = valid_record();
    expect(Materializer::materialize($record) === 'created');
    $post = $GLOBALS['dbt']['posts'][100];
    $post->post_date = '2026-09-14 20:58:09';
    $post->post_date_gmt = '2026-09-14 20:58:09';
    $GLOBALS['dbt']['dated_permalinks'] = true;
    $old_url = 'https://wordpress.example/2026/09/14/from-the-bridge/';
    $new_url = 'https://wordpress.example/2026/09/14/from-the-bridge-moved/';
    update_post_meta(100, '_discussionbridge_canonical_url', $old_url);
    $post->post_name = 'from-the-bridge-moved';
    $record['bindings'][0]['canonical_url'] = $new_url;
    $record['bindings'][0]['url_migration'] = [
        'old_url' => $old_url, 'new_url' => $new_url,
        'redirect_status' => 301, 'verified_at' => '2026-09-16T12:00:00.000000Z',
    ];
    $record['source']['revision'] = 'post:99:version:4';
    $record['source']['post_version'] = 4;
    expect(Materializer::materialize($record) === 'updated');
    expect(get_permalink(100) === $new_url);
    expect(count($GLOBALS['dbt']['posts']) === 1);
    expect(get_post_meta(100, '_discussionbridge_canonical_url', true) === $new_url);
    expect(Materializer::materialize($record) === 'unchanged');
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

test('publication sync completes page 101 and rejects inconsistent pagination', function (): void {
    dbt_reset();
    for ($page = 1; $page <= 101; $page++) {
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records.json?page=' . $page . ($page > 1 ? '&snapshot=snap' : '');
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
        $url = 'https://bridge.example/discussion-bridge/v1/bridge-records.json?page=' . $page . ($page > 1 ? '&snapshot=snap' : '');
        $GLOBALS['dbt']['responses'][$url] = dbt_response(200, [
            'bridge_records' => [],
            'pagination' => ['page' => $page, 'pages' => $pages, 'total' => 0, 'snapshot' => 'snap'],
        ]);
    }
    $failure_codes = [];
    $totals = Materializer::sync($failure_codes);
    expect($totals['failed'] === 1, 'inconsistent pagination was accepted');
    expect($failure_codes === ['invalid_record_pagination'], 'pagination failure reason was not retained');
});

test('publication sync returns bounded materialization failure codes', function (): void {
    dbt_reset();
    unset($GLOBALS['dbt']['options'][Settings::SERVICE_AUTHOR_OPTION]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/bridge-records.json?page=1'] = dbt_response(200, [
        'bridge_records' => [valid_record()],
        'pagination' => ['page' => 1, 'pages' => 1, 'total' => 1, 'snapshot' => 'snap'],
    ]);
    $failure_codes = [];
    $totals = Materializer::sync($failure_codes);
    expect($totals['failed'] === 1, 'materialization failure was not counted');
    expect($failure_codes === ['discussionbridge_materialization_service_author'], 'materialization failure reason was not retained');
});

test('block and shortcode render sanitized credential-free From Discourse content', function (): void {
    dbt_reset(); $record = valid_record();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
    $block = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => 'none']);
    $shortcode = Presentation::shortcode(['resource_id' => $record['resource_id'], 'comments' => 'none']);
    expect($block === $shortcode && str_contains($block, 'Safe body'));
    expect(!str_contains($block, '<script>') && !str_contains($block, str_repeat('s', 43)));
    expect(str_contains((string) json_encode($GLOBALS['dbt']['requests']), str_repeat('s', 43)), 'authenticated request omitted protected header');
});

test('historical block mode renders through the canonical Interactive class', function (): void {
    dbt_reset(); $record = valid_record();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
    $html = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => 'fullInteractive']);
    expect(str_contains($html, 'discussionbridge-record--interactive'));
    expect(!str_contains($html, 'discussionbridge-record--fullInteractive'));
});

test('Simple mode batches missing replies and exposes bounded Show more', function (): void {
    dbt_reset(); $record = valid_record();
    $record_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json';
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

test('connection secret and advanced category route match the receiver admission grammar', function (): void {
    dbt_reset();
    expect(Settings::sanitize_lane('articles') === 'articles', 'valid lane rejected');
    expect(Settings::sanitize_lane('Bad Lane') === '', 'invalid lane accepted');
    expect(
        $GLOBALS['dbt']['settings_errors'] === [[
            Settings::LANE_OPTION,
            'invalid_lane',
            'DiscussionBridge advanced category route is invalid.',
        ]],
        'invalid advanced category route did not use operator-facing vocabulary'
    );
    $validator = new ReflectionMethod(Settings::class, 'validated_secret');
    expect($validator->invoke(null, str_repeat('s', 42)) === '', 'short secret accepted');
    expect($validator->invoke(null, str_repeat('é', 129)) === '', 'oversized byte secret accepted');
    expect($validator->invoke(null, str_repeat('s', 43)."\ninside") === '', 'control-bearing secret accepted');
    expect($validator->invoke(null, str_repeat('s', 43)) === str_repeat('s', 43), 'valid secret rejected');
});

test('WordPress reports stable native post types and taxonomies', function (): void {
    dbt_reset();
    $catalog = PlatformCatalog::build();
    expect($catalog['platform'] === 'wordpress');
    expect(array_column($catalog['containers'], 'id') === ['post_type:post', 'post_type:page']);
    expect($catalog['containers'][0]['taxonomy_ids'] === ['category', 'post_tag']);
    expect($catalog['containers'][1]['taxonomy_ids'] === []);
    expect($catalog['taxonomies'][0]['terms'][0]['id'] === 'category:4');
    expect($catalog['taxonomies'][1]['terms'][0]['id'] === 'post_tag:8');
    expect(array_column($catalog['authors'], 'id') === ['user:7', 'user:9']);
    expect($catalog['service_author_id'] === 'user:7');
    expect($catalog['presentation_modes'] === ['simple', 'full', 'fullInteractive', 'native']);
    expect($catalog['capabilities'] === ['updates' => true, 'unpublish' => true, 'drafts' => true]);
});

test('WordPress catalog fails closed instead of omitting selectable authors', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['users'] = [];
    for ($id = 1; $id <= 501; $id++) {
        $GLOBALS['dbt']['users'][$id] = new WP_User($id, 'publisher-' . $id, 'Publisher ' . $id, ['publish_posts' => true]);
    }
    $GLOBALS['dbt']['options'][Settings::SERVICE_AUTHOR_OPTION] = 'publisher-501';

    expect_error(PlatformCatalog::build(), 'discussionbridge_platform_author_inventory_too_large');
});

test('WordPress catalog fails locally when platform containers exceed the receiver contract', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['post_types'] = [];
    for ($id = 1; $id <= 501; $id++) {
        $GLOBALS['dbt']['post_types']['type_' . $id] = 'type_' . $id;
    }
    expect_error(PlatformCatalog::build(), 'discussionbridge_platform_container_limit');
    expect($GLOBALS['dbt']['requests'] === []);
});

test('WordPress catalog rejects taxonomy read failures and an irreducible byte overflow', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['get_terms_callback'] = static fn(array $args): WP_Error => new WP_Error(
        'database_unavailable',
        (string) ($args['taxonomy'] ?? '')
    );
    expect_error(PlatformCatalog::build(), 'discussionbridge_platform_taxonomy_inventory_failed');

    dbt_reset();
    $GLOBALS['dbt']['terms'] = [];
    for ($id = 1; $id <= 2500; $id++) {
        $GLOBALS['dbt']['terms'][] = new WP_Term($id, str_repeat('x', 180) . $id, 'category');
    }
    expect_error(PlatformCatalog::build(), 'discussionbridge_platform_catalog_too_large');
});

test('automatic polling exposes catalog failures as operator attention', function (): void {
    dbt_reset();
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(503, [
        'outcome' => 'rejected', 'reason' => 'temporarily_unavailable',
    ]);

    ForumPublisher::poll();

    $state = ForumPublisher::state();
    expect($state['status'] === 'attention');
    expect($state['failure_codes'] === ['discussionbridge_temporarily_unavailable']);
    expect(count($GLOBALS['dbt']['scheduled']) === 1, 'poll recovery was not rescheduled');
});

test('forum synchronization uploads the catalog and waits for an operator mapping', function (): void {
    dbt_reset();
    $url = 'https://bridge.example/discussion-bridge/v1/platform-catalog.json';
    $GLOBALS['dbt']['options'][ForumPublisher::STATE_OPTION] = array_replace(
        ForumPublisher::initial_state(),
        ['catalog_revision' => str_repeat('o', 64)]
    );
    $GLOBALS['dbt']['responses'][$url] = static function (string $request_url, array $args): array {
        unset($request_url);
        if (($args['method'] ?? '') === 'GET') {
            return dbt_response(200, [
                'catalog_revision' => str_repeat('a', 64),
                'destination_mapping_state' => 'attention',
            ]);
        }
        $body = json_decode((string) $args['body'], true);
        expect(($body['expected_catalog_revision'] ?? '') === str_repeat('a', 64));
        return dbt_response(200, [
            'outcome' => 'accepted',
            'catalog_revision' => str_repeat('a', 64),
            'destination_mapping_state' => 'attention',
        ]);
    };

    $state = ForumPublisher::start();

    expect(is_array($state) && $state['status'] === 'awaiting_mapping');
    expect(count($GLOBALS['dbt']['scheduled']) === 0, 'sync was scheduled without a mapping');
    expect(count($GLOBALS['dbt']['requests']) === 2, 'catalog reconciliation did not read before replacement');
    $request = $GLOBALS['dbt']['requests'][1][1];
    expect(($request['method'] ?? '') === 'PUT');
    expect(($request['headers']['X-DiscussionBridge-Adapter'] ?? '') === 'wordpress-discussion-bridge');
    expect(($request['headers']['X-DiscussionBridge-Adapter-Version'] ?? '') === DISCUSSIONBRIDGE_WORDPRESS_VERSION);
    expect(!str_contains((string) $request['body'], str_repeat('s', 43)), 'secret leaked into the catalog body');
});

test('forum synchronization materializes and acknowledges an exact native WordPress publication', function (): void {
    dbt_reset();
    $catalog_url = 'https://bridge.example/discussion-bridge/v1/platform-catalog.json';
    $GLOBALS['dbt']['responses'][$catalog_url] = static function (string $url, array $args): array {
        unset($url);
        return dbt_response(200, ($args['method'] ?? '') === 'PUT' ? [
            'outcome' => 'accepted',
            'catalog_revision' => str_repeat('c', 64),
            'destination_mapping_state' => 'current',
        ] : [
            'catalog_revision' => str_repeat('c', 64),
            'destination_mapping_revision' => str_repeat('m', 64),
            'destination_mapping_state' => 'current',
        ]);
    };
    $destination = [
        'state' => 'ready',
        'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:post',
        'destination_terms' => [[
            'source_tag_id' => 12,
            'destination_taxonomy_id' => 'category',
            'destination_term_id' => 'category:4',
        ]],
        'presentation_mode' => 'full',
        'authorship_policy' => 'fixed',
        'destination_author_id' => 'user:9',
        'slug_policy' => 'topic_id',
    ];
    $item = [
        'topic_id' => 42,
        'topic_url' => 'https://bridge.example/t/forum-policy-guide/42',
        'title' => 'Forum policy guide',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:99:version:3',
        'publication_revision' => str_repeat('p', 64),
        'destination' => $destination,
        'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42.json'] = dbt_response(200, [
        'source_topic' => $item + ['content_html' => '<h2>Policy</h2><p>Forum-owned content.</p>'],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42/resolve.json'] = static function (string $url, array $args): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(($body['publication']['external_id'] ?? '') === 'wordpress:post:100');
        expect(
            ($body['publication']['canonical_url'] ?? '') === 'https://wordpress.example/discourse-topic-42/',
            'draft publication used its query-bearing draft permalink instead of its future canonical URL'
        );
        expect(($body['publication']['destination']['destination_container_id'] ?? '') === 'post_type:post');
        return dbt_response(201, forum_resolve_result(
            $body['publication'],
            '44444444-4444-4444-8444-444444444444'
        ));
    };
    $ack_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/44444444-4444-4444-8444-444444444444/acknowledgement.json';
    $GLOBALS['dbt']['responses'][$ack_url] = static function (string $url, array $args): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(($body['acknowledgement']['outcome'] ?? '') === 'created');
        expect(($body['acknowledgement']['native_destination']['external_id'] ?? '') === 'wordpress:post:100');
        return dbt_response(200, forum_acknowledgement_result(
            '44444444-4444-4444-8444-444444444444',
            $body['acknowledgement']
        ));
    };
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations.json'] = dbt_response(200, [
        'publication_revocations' => [],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);

    $started = ForumPublisher::start();
    expect(is_array($started) && $started['status'] === 'queued');
    ForumPublisher::run_batch();
    ForumPublisher::run_batch();

    $post = get_post(100);
    expect($post instanceof WP_Post && $post->post_status === 'publish' && $post->post_type === 'post');
    expect($post->post_author === 9 && $post->post_name === 'discourse-topic-42');
    expect($post->post_content === '<h2>Policy</h2><p>Forum-owned content.</p>');
    expect(get_post_meta(100, '_discussionbridge_forum_resource_id', true) === '44444444-4444-4444-8444-444444444444');
    expect($GLOBALS['dbt']['object_terms'][100]['category'] === [4]);
    expect(json_decode((string) get_post_meta(100, '_discussionbridge_forum_source_author', true), true) === [
        'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil', 'username' => 'phil',
    ]);
    $GLOBALS['dbt']['queried_id'] = 100;
    $rendered = Presentation::append_mapped_discussion('<p>Native content</p>');
    expect(str_contains($rendered, 'discourse-comments') && str_contains($rendered, '/t/forum-policy-guide/42'));
    expect(str_contains($rendered, 'Originally published in Discourse by'));
    expect(str_contains($rendered, 'https://bridge.example/u/phil'));
    update_post_meta(100, '_discussionbridge_forum_status', 'pending');
    $pending_rendered = Presentation::append_mapped_discussion('<p>Native content</p>');
    expect(str_contains($pending_rendered, 'discourse-comments'), 'pending update removed forum presentation');
    update_post_meta(100, '_discussionbridge_forum_status', 'healthy');
    $state = ForumPublisher::state();
    expect($state['status'] === 'complete' && $state['created'] === 1 && $state['failed'] === 0);

    $publication = [
        'destination_state' => 'healthy',
        'acknowledged_publication_revision' => str_repeat('p', 64),
        'publication_program' => 'forum_sync',
        'resource_id' => '44444444-4444-4444-8444-444444444444',
        'external_id' => 'wordpress:post:100',
        'canonical_url' => get_permalink(100),
    ];
    $current_item = $item;
    $current_item['publication'] = $publication;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$current_item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $detail_fetches = 0;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42.json'] = static function (string $url, array $args) use (&$detail_fetches, &$current_item): array {
        unset($url, $args);
        $detail_fetches++;
        return dbt_response(200, [
            'eligible' => true,
            'source_topic' => $current_item + ['content_html' => '<h2>Policy</h2><p>Forum-owned content.</p>'],
        ]);
    };
    ForumPublisher::start();
    ForumPublisher::run_batch();
    expect($detail_fetches === 0, 'unchanged operator scan fetched full source content');
    expect(ForumPublisher::state()['unchanged'] === 1);
    ForumPublisher::run_batch();

    $post->post_content = '<p>Manual drift</p>';
    update_post_meta(100, '_discussionbridge_forum_canonical_url', '');
    update_post_meta(100, '_discussionbridge_forum_source_profile_url', '');
    $publication['destination_state'] = 'pending';
    $current_item['publication'] = $publication;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$current_item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $update_resolve_attempts = 0;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42/resolve.json'] = static function (string $url, array $args) use (&$update_resolve_attempts): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        $update_resolve_attempts++;
        return dbt_response(200, forum_resolve_result(
            $body['publication'],
            $update_resolve_attempts === 1
                ? '33333333-3333-4333-8333-333333333333'
                : '44444444-4444-4444-8444-444444444444',
            'resolved'
        ));
    };
    $update_ack_attempts = 0;
    $GLOBALS['dbt']['responses'][$ack_url] = static function (string $url, array $args) use (&$update_ack_attempts): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(($body['acknowledgement']['outcome'] ?? '') === 'updated');
        $update_ack_attempts++;
        return $update_ack_attempts === 1
            ? dbt_response(503, ['outcome' => 'rejected', 'reason' => 'temporarily_unavailable'])
            : dbt_response(200, forum_acknowledgement_result(
                '44444444-4444-4444-8444-444444444444',
                $body['acknowledgement']
            ));
    };
    ForumPublisher::start();
    expect(ForumPublisher::state()['status'] === 'queued', 'operator sync did not queue the next scan');
    ForumPublisher::run_batch();
    expect(get_post(100)?->post_content === '<p>Manual drift</p>', 'wrong resolve resource was not rolled back');
    expect($update_ack_attempts === 0, 'wrong resolve resource reached acknowledgement');
    ForumPublisher::run_batch();
    expect(get_post(100)?->post_content === '<p>Manual drift</p>', 'failed update acknowledgement was not rolled back');
    ForumPublisher::run_batch();
    expect(get_post(100)?->post_content === '<h2>Policy</h2><p>Forum-owned content.</p>');
    expect(get_post_meta(100, '_discussionbridge_forum_canonical_url', true) === get_permalink(100));
    expect(get_post_meta(100, '_discussionbridge_forum_source_profile_url', true) === 'https://bridge.example/u/phil');
    expect(ForumPublisher::state()['updated'] === 1, 'native drift was not repaired and acknowledged');
});

test('automatic polling claims and acknowledges one exact incremental publication lease', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'complete';
    $state['initial_backfill_complete'] = true;
    update_option(ForumPublisher::STATE_OPTION, $state, false);

    $lease = str_repeat('a', 64);
    $claim_count = 0;
    $claim_url = 'https://bridge.example/discussion-bridge/v1/publication-work/claim.json';
    $GLOBALS['dbt']['responses'][$claim_url] = static function () use (&$claim_count, $lease): array {
        $claim_count++;
        return dbt_response(200, [
            'publication_work' => $claim_count === 1 ? [
                'topic_id' => 42,
                'resource_id' => null,
                'action' => 'publish',
                'reason' => 'new_publication',
                'lease_token' => $lease,
                'attempt_count' => 1,
            ] : null,
        ]);
    };
    $destination = [
        'state' => 'ready',
        'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:post',
        'destination_terms' => [],
        'presentation_mode' => 'full',
        'authorship_policy' => 'fixed',
        'destination_author_id' => 'user:9',
        'slug_policy' => 'topic_id',
    ];
    $source = [
        'topic_id' => 42,
        'topic_url' => 'https://bridge.example/t/forum-policy-guide/42',
        'title' => 'Forum policy guide',
        'content_html' => '<h2>Policy</h2><p>Incremental forum content.</p>',
        'author' => [
            'username' => 'phil',
            'name' => 'Phil',
            'profile_url' => 'https://bridge.example/u/phil',
        ],
        'source_revision' => 'post:99:version:4',
        'publication_revision' => str_repeat('q', 64),
        'destination' => $destination,
        'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42.json'] =
        dbt_response(200, ['eligible' => true, 'source_topic' => $source]);
    $resource_id = '99999999-9999-4999-8999-999999999999';
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/42/resolve.json'] =
        static function (string $url, array $args) use ($resource_id): array {
            unset($url);
            $body = json_decode((string) $args['body'], true);
            return dbt_response(201, forum_resolve_result($body['publication'], $resource_id));
        };
    $GLOBALS['dbt']['responses'][
        'https://bridge.example/discussion-bridge/v1/bridge-records/' . $resource_id . '/acknowledgement.json'
    ] = static function (string $url, array $args) use ($lease, $resource_id): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(($body['acknowledgement']['lease_token'] ?? '') === $lease, 'claim lease was not acknowledged');
        return dbt_response(200, forum_acknowledgement_result($resource_id, $body['acknowledgement']));
    };

    ForumPublisher::poll();

    expect($claim_count === 2, 'incremental worker did not drain the available queue');
    expect(get_post(100)?->post_content === '<h2>Policy</h2><p>Incremental forum content.</p>');
    $state = ForumPublisher::state();
    expect($state['incremental_processed'] === 1);
    expect($state['last_incremental_error'] === null);
});

test('automatic polling processes at most eight publication claims per run', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'complete';
    $state['initial_backfill_complete'] = true;
    update_option(ForumPublisher::STATE_OPTION, $state, false);

    $destination = [
        'state' => 'ready',
        'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:post',
        'destination_terms' => [],
        'presentation_mode' => 'full',
        'authorship_policy' => 'fixed',
        'destination_author_id' => 'user:9',
        'slug_policy' => 'topic_id',
    ];
    $claim_count = 0;
    $claim_url = 'https://bridge.example/discussion-bridge/v1/publication-work/claim.json';
    $GLOBALS['dbt']['responses'][$claim_url] = static function () use (&$claim_count): array {
        $claim_count++;
        return dbt_response(200, ['publication_work' => [
            'topic_id' => 100 + $claim_count,
            'resource_id' => null,
            'action' => 'publish',
            'reason' => 'new_publication',
            'lease_token' => str_repeat((string) $claim_count, 64),
            'attempt_count' => 1,
        ]]);
    };
    for ($index = 1; $index <= 8; $index++) {
        $topic_id = 100 + $index;
        $revision = str_repeat(dechex($index), 64);
        $resource_id = sprintf('99999999-9999-4999-8999-%012d', $index);
        $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/' . $topic_id . '.json'] =
            dbt_response(200, ['eligible' => true, 'source_topic' => [
                'topic_id' => $topic_id,
                'topic_url' => 'https://bridge.example/t/topic-' . $topic_id . '/' . $topic_id,
                'title' => 'Topic ' . $topic_id,
                'content_html' => '<p>Bounded work item.</p>',
                'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
                'source_revision' => 'post:' . $topic_id . ':version:1',
                'publication_revision' => $revision,
                'destination' => $destination,
                'publication' => null,
            ]]);
        $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/' . $topic_id . '/resolve.json'] =
            static function (string $url, array $args) use ($resource_id): array {
                unset($url);
                $body = json_decode((string) $args['body'], true);
                return dbt_response(201, forum_resolve_result($body['publication'], $resource_id));
            };
        $GLOBALS['dbt']['responses'][
            'https://bridge.example/discussion-bridge/v1/bridge-records/' . $resource_id . '/acknowledgement.json'
        ] = static function (string $url, array $args) use ($resource_id): array {
            unset($url);
            $body = json_decode((string) $args['body'], true);
            return dbt_response(200, forum_acknowledgement_result($resource_id, $body['acknowledgement']));
        };
    }

    ForumPublisher::poll();

    expect($claim_count === 8, 'incremental polling exceeded its safe eight-item receiver budget');
    expect(ForumPublisher::state()['incremental_processed'] === 8);
});

test('automatic polling gracefully defers a plain-text receiver rate limit', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'complete';
    $state['initial_backfill_complete'] = true;
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/publication-work/claim.json'] =
        dbt_response(429, 'rate limited', 'text/plain');

    ForumPublisher::poll();

    $state = ForumPublisher::state();
    expect($state['incremental_processed'] === 0);
    expect($state['last_incremental_error'] === null, 'an unleased rate limit became operator attention');
    expect(wp_next_scheduled(ForumPublisher::POLL_HOOK) !== false, 'the next bounded poll was not scheduled');
});

test('automatic polling refreshes a stale adapter catalog once and retries its claim', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'complete';
    $state['initial_backfill_complete'] = true;
    update_option(ForumPublisher::STATE_OPTION, $state, false);

    $claim_url = 'https://bridge.example/discussion-bridge/v1/publication-work/claim.json';
    $claim_count = 0;
    $GLOBALS['dbt']['responses'][$claim_url] = static function () use (&$claim_count): array {
        $claim_count++;
        return $claim_count === 1
            ? dbt_response(401, ['outcome' => 'rejected', 'reason' => 'unauthorized'])
            : dbt_response(200, ['publication_work' => null]);
    };
    $catalog_url = 'https://bridge.example/discussion-bridge/v1/platform-catalog.json';
    $catalog_gets = 0;
    $catalog_puts = 0;
    $GLOBALS['dbt']['responses'][$catalog_url] = static function (string $url, array $args) use (&$catalog_gets, &$catalog_puts): array {
        unset($url);
        if (($args['method'] ?? '') === 'GET') {
            $catalog_gets++;
            return dbt_response(200, [
                'catalog_revision' => str_repeat('c', 64),
                'destination_mapping_state' => 'current',
            ]);
        }
        $catalog_puts++;
        expect(($args['headers']['X-DiscussionBridge-Adapter-Version'] ?? '') === '0.2.0-alpha.40');
        $body = json_decode((string) $args['body'], true);
        expect(($body['expected_catalog_revision'] ?? '') === str_repeat('c', 64));
        expect(($body['catalog']['platform'] ?? '') === 'wordpress');
        return dbt_response(200, [
            'outcome' => 'accepted',
            'catalog_revision' => str_repeat('d', 64),
            'destination_mapping_state' => 'current',
        ]);
    };

    ForumPublisher::poll();

    expect($claim_count === 2, 'stale-catalog claim was not retried exactly once');
    expect($catalog_gets === 1 && $catalog_puts === 1, 'catalog was not refreshed exactly once');
    expect(ForumPublisher::state()['last_incremental_error'] === null);
});

test('automatic polling reports an incremental delivery failure with its exact lease', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'complete';
    $state['initial_backfill_complete'] = true;
    update_option(ForumPublisher::STATE_OPTION, $state, false);

    $lease = str_repeat('b', 64);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/publication-work/claim.json'] =
        dbt_response(200, ['publication_work' => [
            'topic_id' => 51,
            'resource_id' => null,
            'action' => 'publish',
            'reason' => 'source_changed',
            'lease_token' => $lease,
            'attempt_count' => 1,
        ]]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/51.json'] =
        dbt_response(503, ['outcome' => 'rejected', 'reason' => 'temporarily_unavailable']);
    $failure_url = 'https://bridge.example/discussion-bridge/v1/publication-work/failure.json';
    $GLOBALS['dbt']['responses'][$failure_url] = static function (string $url, array $args) use ($lease): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        $failure = $body['publication_work_failure'] ?? [];
        expect(($failure['lease_token'] ?? '') === $lease, 'failure did not identify its lease');
        expect(($failure['error_code'] ?? '') === 'discussionbridge_temporarily_unavailable');
        return dbt_response(200, ['outcome' => 'recorded']);
    };

    ForumPublisher::poll();

    expect(ForumPublisher::state()['last_incremental_error'] === 'discussionbridge_temporarily_unavailable');
});

test('forum synchronization adopts the exact legacy materialized post instead of creating a duplicate', function (): void {
    dbt_reset();
    $resource_id = '88888888-8888-4888-8888-888888888888';
    $post = new WP_Post(77, 'publish', 'post', 'Legacy title', '<p>Legacy body</p>', 7, 'legacy-topic');
    $GLOBALS['dbt']['posts'][77] = $post;
    $GLOBALS['dbt']['meta'][77]['_discussionbridge_resource_id'] = $resource_id;
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64),
        'destination_mapping_state' => 'current',
    ]);
    $destination = [
        'state' => 'ready', 'reasons' => [], 'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64), 'destination_container_id' => 'post_type:post',
        'destination_terms' => [], 'presentation_mode' => 'native',
        'authorship_policy' => 'service_author', 'destination_author_id' => 'user:7',
        'slug_policy' => 'platform_default',
    ];
    $publication = [
        'publication_program' => 'legacy', 'resource_id' => $resource_id,
        'external_id' => 'wordpress:post:77', 'canonical_url' => get_permalink(77),
        'destination_state' => 'pending',
    ];
    $item = [
        'topic_id' => 81, 'topic_url' => 'https://bridge.example/t/adopt-legacy/81',
        'title' => 'Adopt legacy',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:181:version:1', 'publication_revision' => str_repeat('p', 64),
        'destination' => $destination, 'publication' => $publication,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item], 'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/81.json'] = static function (string $url, array $args) use (&$item): array {
        unset($url, $args);
        return dbt_response(200, [
            'eligible' => true,
            'source_topic' => $item + ['content_html' => '<p>Current forum content.</p>'],
        ]);
    };
    $resolve_attempts = 0;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/81/resolve.json'] = static function (string $url, array $args) use (&$item, &$resolve_attempts, $resource_id): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        $resolve_attempts++;
        $item['publication']['publication_program'] = 'forum_sync_pending';
        if ($resolve_attempts === 1) {
            return dbt_response(200, ['outcome' => 'resolved', 'resource_id' => $resource_id]);
        }
        return dbt_response(200, forum_resolve_result($body['publication'], $resource_id, 'resolved'));
    };
    $ack_attempts = 0;
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/bridge-records/' . $resource_id . '/acknowledgement.json'] = static function (string $url, array $args) use (&$ack_attempts, &$item, $resource_id): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        $ack_attempts++;
        if ($ack_attempts === 1) {
            $item['publication']['publication_program'] = 'forum_sync';
            $item['publication']['destination_state'] = 'healthy';
            $item['publication']['acknowledged_publication_revision'] = $body['acknowledgement']['publication_revision'];
            return dbt_response(200, ['outcome' => 'acknowledged']);
        }
        return dbt_response(200, forum_acknowledgement_result($resource_id, $body['acknowledgement']));
    };

    ForumPublisher::run_batch();

    expect(get_post(77)?->post_content === '<p>Legacy body</p>', 'failed adoption was not rolled back');
    expect(get_post_meta(77, '_discussionbridge_forum_connection_id', true) === '', 'invalid resolve left connection metadata');
    expect(
        get_post_meta(77, '_discussionbridge_forum_adoption_resource_id', true) === $resource_id,
        'invalid resolve did not preserve the recovery marker'
    );
    ForumPublisher::run_batch();

    expect(get_post(77)?->post_content === '<p>Legacy body</p>', 'invalid acknowledgement was not rolled back');
    expect(get_post_meta(77, '_discussionbridge_forum_connection_id', true) === '', 'invalid acknowledgement left connection metadata');
    expect(
        get_post_meta(77, '_discussionbridge_forum_adoption_resource_id', true) === $resource_id,
        'adoption marker was not retained: ' . json_encode([
            'marker' => get_post_meta(77, '_discussionbridge_forum_adoption_resource_id', true),
            'state' => ForumPublisher::state(),
        ])
    );
    ForumPublisher::run_batch();

    expect(count($GLOBALS['dbt']['posts']) === 1, 'adoption retry created another post');
    expect(get_post(77)?->post_content === '<p>Current forum content.</p>', 'adoption retry did not publish current content');
    expect(get_post_meta(77, '_discussionbridge_forum_resource_id', true) === $resource_id, 'resource identity was not retained');
    expect(get_post_meta(77, '_discussionbridge_forum_topic_id', true) === '81', 'topic identity was not retained');
    expect(get_post_meta(77, '_discussionbridge_forum_adoption_resource_id', true) === '', 'adoption marker was not cleared');
});

test('forum synchronization drafts and acknowledges an established publication that becomes unmappable', function (): void {
    dbt_reset();
    $resource_id = '99999999-9999-4999-8999-999999999999';
    $post = new WP_Post(78, 'publish', 'post', 'Mapped title', '<p>Mapped body</p>', 7, 'mapped-topic');
    $GLOBALS['dbt']['posts'][78] = $post;
    $GLOBALS['dbt']['meta'][78]['_discussionbridge_forum_connection_id'] = Settings::connection_id();
    $GLOBALS['dbt']['meta'][78]['_discussionbridge_forum_topic_id'] = '82';
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64), 'destination_mapping_state' => 'current',
    ]);
    $destination = [
        'state' => 'attention', 'reasons' => ['category_unmapped'],
        'catalog_revision' => str_repeat('c', 64), 'mapping_revision' => str_repeat('m', 64),
    ];
    $item = [
        'topic_id' => 82, 'topic_url' => 'https://bridge.example/t/unmapped/82', 'title' => 'Unmapped',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:182:version:2', 'publication_revision' => str_repeat('u', 64),
        'destination' => $destination,
        'publication' => [
            'resource_id' => $resource_id, 'external_id' => 'wordpress:post:78',
            'canonical_url' => get_permalink(78), 'destination_state' => 'healthy',
            'publication_program' => 'forum_sync',
        ],
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item], 'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $ack_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $resource_id . '/acknowledgement.json';
    $hold_acks = 0;
    $GLOBALS['dbt']['responses'][$ack_url] = static function (string $url, array $args) use (&$hold_acks, $resource_id): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(($body['acknowledgement']['outcome'] ?? '') === 'held');
        $hold_acks++;
        return dbt_response(200, forum_acknowledgement_result($resource_id, $body['acknowledgement']));
    };

    ForumPublisher::run_batch();

    expect(get_post(78)?->post_status === 'draft');
    expect(get_post_meta(78, '_discussionbridge_forum_status', true) === 'held');
    expect(ForumPublisher::state()['held'] === 1);

    $item['publication']['acknowledged_publication_revision'] = str_repeat('u', 64);
    $item['publication']['last_delivery_outcome'] = 'held';
    $item['publication']['destination_state'] = 'held';
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item], 'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    ForumPublisher::run_batch();
    expect($hold_acks === 1, 'an exact held publication was acknowledged again');
});

test('forum synchronization leaves an unmappable legacy publication under the legacy owner', function (): void {
    dbt_reset();
    $resource_id = 'cccccccc-cccc-4ccc-8ccc-cccccccccccc';
    $post = new WP_Post(80, 'publish', 'post', 'Legacy owned', '<p>Legacy body</p>', 7, 'legacy-owned');
    $GLOBALS['dbt']['posts'][80] = $post;
    $GLOBALS['dbt']['meta'][80]['_discussionbridge_resource_id'] = $resource_id;
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64), 'destination_mapping_state' => 'current',
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [[
            'topic_id' => 86, 'topic_url' => 'https://bridge.example/t/legacy-owned/86',
            'title' => 'Legacy owned',
            'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
            'source_revision' => 'post:186:version:1', 'publication_revision' => str_repeat('l', 64),
            'destination' => [
                'state' => 'attention', 'reasons' => ['category_unmapped'],
                'catalog_revision' => str_repeat('c', 64), 'mapping_revision' => str_repeat('m', 64),
            ],
            'publication' => [
                'publication_program' => 'legacy', 'resource_id' => $resource_id,
                'external_id' => 'wordpress:post:80', 'canonical_url' => get_permalink(80),
            ],
        ]],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);

    ForumPublisher::run_batch();

    expect(get_post(80)?->post_status === 'publish');
    expect(count($GLOBALS['dbt']['requests']) === 2, 'legacy hold attempted receiver acknowledgement');
});

test('forum synchronization unpublishes a revoked native publication without source content', function (): void {
    dbt_reset();
    $post = new WP_Post(77, 'publish', 'page', 'Revoked', '<p>Old</p>', 7, 'revoked');
    $GLOBALS['dbt']['posts'][77] = $post;
    $GLOBALS['dbt']['meta'][77]['_discussionbridge_forum_connection_id'] = Settings::connection_id();
    $GLOBALS['dbt']['meta'][77]['_discussionbridge_forum_topic_id'] = '42';
    $GLOBALS['dbt']['meta'][77]['_discussionbridge_forum_canonical_url'] = get_permalink(77);
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['phase'] = 'revocations';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64),
        'destination_mapping_state' => 'current',
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations.json'] = dbt_response(200, [
        'publication_revocations' => [[
            'resource_id' => '55555555-5555-4555-8555-555555555555',
            'topic_id' => 42,
            'reason' => 'topic_private',
            'publication_revision' => str_repeat('r', 64),
        ]],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $ack_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/55555555-5555-4555-8555-555555555555/acknowledgement.json';
    $ack_attempts = 0;
    $GLOBALS['dbt']['responses'][$ack_url] = static function (string $url, array $args) use (&$ack_attempts): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        expect(!isset($body['acknowledgement']['source_revision']), 'revocation leaked source content identity');
        expect(($body['acknowledgement']['outcome'] ?? '') === 'unpublished');
        $ack_attempts++;
        return $ack_attempts === 1
            ? dbt_response(503, ['outcome' => 'rejected', 'reason' => 'temporarily_unavailable'])
            : dbt_response(200, forum_acknowledgement_result(
                '55555555-5555-4555-8555-555555555555',
                $body['acknowledgement']
            ));
    };
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations/55555555-5555-4555-8555-555555555555.json'] = dbt_response(200, [
        'revoked' => true,
        'publication_revocation' => [
            'resource_id' => '55555555-5555-4555-8555-555555555555',
            'topic_id' => 42,
            'reason' => 'topic_private',
            'publication_revision' => str_repeat('r', 64),
        ],
    ]);

    ForumPublisher::run_batch();
    expect(get_post(77)?->post_status === 'publish', 'failed revocation acknowledgement was not rolled back');
    expect(count(ForumPublisher::state()['revocation_failures']) === 1);
    ForumPublisher::run_batch();
    ForumPublisher::run_batch();

    expect(get_post(77)?->post_status === 'draft');
    expect(ForumPublisher::state()['unpublished'] === 1);
    expect(ForumPublisher::state()['revocation_failures'] === []);

    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['phase'] = 'revocations';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations.json'] = dbt_response(200, [
        'publication_revocations' => [[
            'resource_id' => '55555555-5555-4555-8555-555555555555',
            'topic_id' => 42,
            'reason' => 'topic_private',
            'publication_revision' => str_repeat('r', 64),
            'acknowledged_publication_revision' => str_repeat('r', 64),
            'last_delivery_outcome' => 'unpublished',
        ]],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    ForumPublisher::run_batch();
    expect($ack_attempts === 2, 'an exact acknowledged revocation was sent again');
});

test('forum synchronization cancels a stale revocation retry when the topic is eligible again', function (): void {
    dbt_reset();
    $resource_id = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
    $post = new WP_Post(79, 'publish', 'page', 'Restored', '<p>Still current</p>', 7, 'restored');
    $GLOBALS['dbt']['posts'][79] = $post;
    $GLOBALS['dbt']['meta'][79]['_discussionbridge_forum_connection_id'] = Settings::connection_id();
    $GLOBALS['dbt']['meta'][79]['_discussionbridge_forum_topic_id'] = '83';
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['phase'] = 'revocations';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64), 'destination_mapping_state' => 'current',
    ]);
    $item = [
        'resource_id' => $resource_id, 'topic_id' => 83, 'reason' => 'topic_private',
        'publication_revision' => str_repeat('r', 64),
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations.json'] = dbt_response(200, [
        'publication_revocations' => [$item], 'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/bridge-records/' . $resource_id . '/acknowledgement.json'] = dbt_response(503, [
        'outcome' => 'rejected', 'reason' => 'temporarily_unavailable',
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-revocations/' . $resource_id . '.json'] = dbt_response(200, [
        'revoked' => false, 'publication_revocation' => null,
    ]);

    ForumPublisher::run_batch();
    expect(get_post(79)?->post_status === 'publish');
    expect(count(ForumPublisher::state()['revocation_failures']) === 1);
    ForumPublisher::run_batch();

    expect(get_post(79)?->post_status === 'publish');
    expect(ForumPublisher::state()['revocation_failures'] === []);
    expect(ForumPublisher::state()['resume_scan_after_failures'] === true);
});

test('forum synchronization cancels a stale topic retry when the topic is no longer eligible', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['phase'] = 'topic_retries';
    $state['topic_failures'] = [[
        'topic_id' => 84, 'attempts' => 1, 'code' => 'discussionbridge_transport_failed', 'final' => false,
    ]];
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64), 'destination_mapping_state' => 'current',
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/84.json'] = dbt_response(200, [
        'eligible' => false, 'reason' => 'category_excluded',
    ]);

    ForumPublisher::run_batch();

    expect(ForumPublisher::state()['topic_failures'] === []);
    expect(ForumPublisher::state()['resume_scan_after_failures'] === true);
    expect(ForumPublisher::state()['status'] === 'queued');
});

test('forum synchronization reuses its pending draft after a resolve retry', function (): void {
    dbt_reset();
    $catalog_url = 'https://bridge.example/discussion-bridge/v1/platform-catalog.json';
    $GLOBALS['dbt']['responses'][$catalog_url] = static function (string $url, array $args): array {
        unset($url);
        return dbt_response(200, ($args['method'] ?? '') === 'PUT' ? [
            'catalog_revision' => str_repeat('c', 64),
            'destination_mapping_state' => 'current',
        ] : [
            'destination_mapping_revision' => str_repeat('m', 64),
            'destination_mapping_state' => 'current',
        ]);
    };
    $destination = [
        'state' => 'ready', 'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:post',
        'destination_terms' => [],
        'presentation_mode' => 'native',
        'authorship_policy' => 'service_author',
        'destination_author_id' => 'user:7',
        'slug_policy' => 'platform_default',
    ];
    $item = [
        'topic_id' => 52,
        'topic_url' => 'https://bridge.example/t/retry-safely/52',
        'title' => 'Retry safely',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:109:version:1',
        'publication_revision' => str_repeat('p', 64),
        'destination' => $destination,
        'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/52.json'] = dbt_response(200, [
        'source_topic' => $item + ['content_html' => '<p>One native post.</p>'],
    ]);
    $resolve_url = 'https://bridge.example/discussion-bridge/v1/source-topics/52/resolve.json';
    $GLOBALS['dbt']['responses'][$resolve_url] = dbt_response(503, ['outcome' => 'rejected', 'reason' => 'temporarily_unavailable']);

    ForumPublisher::start();
    ForumPublisher::run_batch();
    expect(get_post(100)?->post_status === 'draft');
    expect(get_post_meta(100, '_discussionbridge_forum_topic_id', true) === '52');
    expect(ForumPublisher::state()['status'] === 'queued');
    expect(ForumPublisher::state()['topic_failures'][0]['topic_id'] === 52);
    expect(ForumPublisher::state()['retry_events'] === 1);

    $GLOBALS['dbt']['responses'][$resolve_url] = static function (string $url, array $args): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        return dbt_response(201, forum_resolve_result(
            $body['publication'],
            '66666666-6666-4666-8666-666666666666'
        ));
    };
    $ack_url = 'https://bridge.example/discussion-bridge/v1/bridge-records/66666666-6666-4666-8666-666666666666/acknowledgement.json';
    $GLOBALS['dbt']['responses'][$ack_url] = static function (string $url, array $args): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        return dbt_response(200, forum_acknowledgement_result(
            '66666666-6666-4666-8666-666666666666',
            $body['acknowledgement']
        ));
    };
    ForumPublisher::run_batch();

    expect(get_post(100)?->post_status === 'publish');
    expect(get_post(101) === null, 'retry created a duplicate WordPress post');
    expect(get_post_meta(100, '_discussionbridge_forum_resource_id', true) === '66666666-6666-4666-8666-666666666666');
    expect(ForumPublisher::state()['topic_failures'] === []);
    expect(ForumPublisher::state()['processed'] === 1 && ForumPublisher::state()['failed'] === 0);
});

test('forum synchronization holds a published post when URL-shaping mapping changes', function (): void {
    dbt_reset();
    $post = new WP_Post(88, 'publish', 'post', 'Stable URL', '<p>Existing</p>', 7, 'stable-url');
    $GLOBALS['dbt']['posts'][88] = $post;
    $GLOBALS['dbt']['meta'][88]['_discussionbridge_forum_connection_id'] = Settings::connection_id();
    $GLOBALS['dbt']['meta'][88]['_discussionbridge_forum_topic_id'] = '61';
    $GLOBALS['dbt']['meta'][88]['_discussionbridge_forum_source_revision'] = 'post:121:version:1';
    $GLOBALS['dbt']['meta'][88]['_discussionbridge_forum_destination'] = wp_json_encode([
        'destination_container_id' => 'post_type:post',
        'slug_policy' => 'source_title',
    ]);
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64),
        'destination_mapping_state' => 'current',
    ]);
    $destination = [
        'state' => 'ready', 'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:page',
        'destination_terms' => [],
        'presentation_mode' => 'native',
        'authorship_policy' => 'service_author',
        'destination_author_id' => 'user:7',
        'slug_policy' => 'topic_id',
    ];
    $item = [
        'topic_id' => 61,
        'topic_url' => 'https://bridge.example/t/changed-destination/61',
        'title' => 'Changed destination',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:121:version:2',
        'publication_revision' => str_repeat('q', 64),
        'destination' => $destination,
        'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/61.json'] = dbt_response(200, [
        'source_topic' => $item + ['content_html' => '<p>Changed</p>'],
    ]);

    ForumPublisher::run_batch();

    expect(get_post(88)?->post_type === 'post' && get_post(88)?->post_name === 'stable-url');
    expect(ForumPublisher::state()['topic_failures'][0]['code'] === 'discussionbridge_native_url_migration_required');
    expect(count($GLOBALS['dbt']['requests']) === 3, 'URL migration hold made a resolve request');
});

test('forum synchronization never leaves a new post public after acknowledgement fails', function (): void {
    dbt_reset();
    $catalog_url = 'https://bridge.example/discussion-bridge/v1/platform-catalog.json';
    $GLOBALS['dbt']['responses'][$catalog_url] = static function (string $url, array $args): array {
        unset($url);
        return dbt_response(200, ($args['method'] ?? '') === 'PUT' ? [
            'catalog_revision' => str_repeat('c', 64),
            'destination_mapping_state' => 'current',
        ] : [
            'destination_mapping_revision' => str_repeat('m', 64),
            'destination_mapping_state' => 'current',
        ]);
    };
    $destination = [
        'state' => 'ready', 'reasons' => [],
        'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64),
        'destination_container_id' => 'post_type:post',
        'destination_terms' => [],
        'presentation_mode' => 'simple',
        'authorship_policy' => 'service_author',
        'destination_author_id' => 'user:7',
        'slug_policy' => 'topic_id',
    ];
    $item = [
        'topic_id' => 72,
        'topic_url' => 'https://bridge.example/t/acknowledgement-failure/72',
        'title' => 'Acknowledgement failure',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:144:version:1',
        'publication_revision' => str_repeat('z', 64),
        'destination' => $destination,
        'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item],
        'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/72.json'] = dbt_response(200, [
        'source_topic' => $item + ['content_html' => '<p>Prepared, not exposed.</p>'],
    ]);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/72/resolve.json'] = static function (string $url, array $args): array {
        unset($url);
        $body = json_decode((string) $args['body'], true);
        return dbt_response(201, forum_resolve_result(
            $body['publication'],
            '77777777-7777-4777-8777-777777777777'
        ));
    };
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/bridge-records/77777777-7777-4777-8777-777777777777/acknowledgement.json'] = dbt_response(503, [
        'outcome' => 'rejected', 'reason' => 'temporarily_unavailable',
    ]);

    ForumPublisher::start();
    ForumPublisher::run_batch();

    expect(get_post(100)?->post_status === 'draft');
    expect(get_post_meta(100, '_discussionbridge_forum_status', true) === 'pending');
    expect(ForumPublisher::state()['topic_failures'][0]['topic_id'] === 72);
});

test('forum synchronization rejects publication identity drift between feed and detail', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'destination_mapping_revision' => str_repeat('m', 64), 'destination_mapping_state' => 'current',
    ]);
    $destination = [
        'state' => 'ready', 'reasons' => [], 'catalog_revision' => str_repeat('c', 64),
        'mapping_revision' => str_repeat('m', 64), 'destination_container_id' => 'post_type:post',
        'destination_terms' => [], 'presentation_mode' => 'native',
        'authorship_policy' => 'service_author', 'destination_author_id' => 'user:7',
        'slug_policy' => 'platform_default',
    ];
    $item = [
        'topic_id' => 85, 'topic_url' => 'https://bridge.example/t/drift/85', 'title' => 'Drift',
        'author' => ['username' => 'phil', 'name' => 'Phil', 'profile_url' => 'https://bridge.example/u/phil'],
        'source_revision' => 'post:185:version:1', 'publication_revision' => str_repeat('d', 64),
        'destination' => $destination, 'publication' => null,
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics.json'] = dbt_response(200, [
        'source_topics' => [$item], 'pagination' => ['complete' => true, 'next_cursor' => null],
    ]);
    $detail = $item;
    $detail['publication'] = [
        'publication_program' => 'forum_sync', 'resource_id' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
        'external_id' => 'wordpress:post:90', 'canonical_url' => 'https://wordpress.example/drift/',
        'destination_state' => 'healthy',
    ];
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/source-topics/85.json'] = dbt_response(200, [
        'eligible' => true, 'source_topic' => $detail + ['content_html' => '<p>Changed between reads.</p>'],
    ]);

    ForumPublisher::run_batch();

    expect($GLOBALS['dbt']['posts'] === []);
    expect(ForumPublisher::state()['topic_failures'][0]['code'] === 'discussionbridge_source_topic_changed');
});

test('forum synchronization uses a recoverable lease and ignores stale scheduled generations', function (): void {
    dbt_reset();
    update_option('discussionbridge_forum_sync_lock', [
        'token' => 'busy', 'run_id' => 'current', 'expires_at' => time() + 300,
    ], false);
    expect_error(ForumPublisher::start(), 'discussionbridge_forum_sync_running');
    update_option('discussionbridge_forum_sync_lock', [
        'token' => 'stale', 'run_id' => 'old', 'expires_at' => time() - 1,
    ], false);
    $GLOBALS['dbt']['responses']['https://bridge.example/discussion-bridge/v1/platform-catalog.json'] = dbt_response(200, [
        'catalog_revision' => str_repeat('c', 64),
        'destination_mapping_state' => 'current',
    ]);
    $state = ForumPublisher::start();
    expect(is_array($state) && $state['status'] === 'queued');
    $request_count = count($GLOBALS['dbt']['requests']);
    ForumPublisher::run_batch('obsolete-run-id');
    expect(count($GLOBALS['dbt']['requests']) === $request_count, 'a stale scheduled generation performed work');
});

test('forum synchronization reschedules a consumed batch event when its lease is busy', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['run_id'] = 'current-run-id';
    update_option(ForumPublisher::STATE_OPTION, $state, false);
    update_option('discussionbridge_forum_sync_lock', [
        'token' => 'busy', 'run_id' => 'start', 'expires_at' => time() + 300,
    ], false);

    ForumPublisher::run_batch('current-run-id');

    expect(
        wp_next_scheduled(ForumPublisher::SYNC_HOOK, ['current-run-id']) !== false,
        'a consumed batch event was not replaced after a lease collision'
    );
});

test('forum synchronization poll restores a missing initial batch event', function (): void {
    dbt_reset();
    $state = ForumPublisher::initial_state();
    $state['status'] = 'queued';
    $state['run_id'] = 'recoverable-run-id';
    update_option(ForumPublisher::STATE_OPTION, $state, false);

    ForumPublisher::poll();

    expect(
        wp_next_scheduled(ForumPublisher::SYNC_HOOK, ['recoverable-run-id']) !== false,
        'the safety poll did not restore the missing initial batch event'
    );
    expect(
        wp_next_scheduled(ForumPublisher::POLL_HOOK) !== false,
        'the safety poll did not retain its own follow-up event'
    );
});

// Test-only protected secret source. It is never an option or rendered value.
define('DISCUSSIONBRIDGE_CONNECTION_SECRET', str_repeat('s', 43));

$passed = 0;
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($tests) . " tests passed\n");
