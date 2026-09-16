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
    dbt_reset(); configured_post();
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
    $totals = Materializer::sync();
    expect($totals['failed'] === 1, 'inconsistent pagination was accepted');
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
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($tests) . " tests passed\n");
