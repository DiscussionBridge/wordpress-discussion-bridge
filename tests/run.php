<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use DiscussionBridge\WordPress\Materializer;
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
    expect(($first['headers']['X-DiscussionBridge-Secret'] ?? '') === str_repeat('s', 40));
    expect(!str_contains($first['body'], str_repeat('s', 40)), 'secret leaked into request body');
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
    expect(Materializer::materialize(valid_record()) === 'unchanged');
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

test('block and shortcode render sanitized credential-free From Discourse content', function (): void {
    dbt_reset(); $record = valid_record();
    $url = 'https://bridge.example/discussion-bridge/v1/bridge-records/' . $record['resource_id'] . '.json';
    $GLOBALS['dbt']['responses'][$url] = dbt_response(200, ['bridge_record' => $record]);
    $block = Presentation::render_block(['resourceId' => $record['resource_id'], 'commentsMode' => 'none']);
    $shortcode = Presentation::shortcode(['resource_id' => $record['resource_id'], 'comments' => 'none']);
    expect($block === $shortcode && str_contains($block, 'Safe body'));
    expect(!str_contains($block, '<script>') && !str_contains($block, str_repeat('s', 40)));
    expect(str_contains((string) json_encode($GLOBALS['dbt']['requests']), str_repeat('s', 40)), 'authenticated request omitted protected header');
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

test('activation and boot are idempotent and do not expose a secret option', function (): void {
    dbt_reset(); unset($GLOBALS['dbt']['options']['discussionbridge_site_id']);
    Plugin::activate(); $first = $GLOBALS['dbt']['options']['discussionbridge_site_id']; Plugin::activate();
    expect($GLOBALS['dbt']['options']['discussionbridge_site_id'] === $first);
    Plugin::boot();
    expect(count($GLOBALS['dbt']['actions']) >= 8 && count($GLOBALS['dbt']['filters']) >= 3);
    foreach (array_keys($GLOBALS['dbt']['options']) as $key) expect(!str_contains($key, 'secret'));
});

// Test-only protected secret source. It is never an option or rendered value.
define('DISCUSSIONBRIDGE_CONNECTION_SECRET', str_repeat('s', 40));

$passed = 0;
foreach ($tests as [$name, $body]) {
    try { $body(); $passed++; fwrite(STDOUT, "ok - {$name}\n"); }
    catch (Throwable $error) { fwrite(STDERR, "not ok - {$name}: {$error->getMessage()}\n"); exit(1); }
}
fwrite(STDOUT, "{$passed}/" . count($tests) . " tests passed\n");
