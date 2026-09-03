<?php

declare(strict_types=1);

const ABSPATH = '/srv/www/wordpress/';
const DISCUSSIONBRIDGE_WORDPRESS_FILE = __DIR__ . '/../wordpress-discussion-bridge.php';
const DISCUSSIONBRIDGE_WORDPRESS_VERSION = '0.1.0-alpha.16';
const MINUTE_IN_SECONDS = 60;

final class WP_Error
{
    public function __construct(private string $code, private string $message = '', private mixed $data = null) {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
    public function get_error_data(): mixed { return $this->data; }
}

final class WP_Post
{
    public function __construct(
        public int $ID,
        public string $post_status = 'draft',
        public string $post_type = 'post',
        public string $post_title = '',
        public string $post_content = '',
        public int $post_author = 0,
        public string $post_name = ''
    ) {}
}

final class WP_User
{
    public function __construct(
        public int $ID,
        public string $user_login,
        public string $display_name,
        public array $capabilities = []
    ) {}
}

$GLOBALS['dbt'] = [];

function dbt_reset(): void
{
    $GLOBALS['dbt'] = [
        'options' => [
            'discussionbridge_server_url' => 'https://bridge.example',
            'discussionbridge_connection_id' => 'dbc_aaaaaaaaaaaaaaaaaaaaaaaa',
            'discussionbridge_site_id' => '11111111-1111-4111-8111-111111111111',
            'discussionbridge_lane' => 'wordpress',
            'discussionbridge_post_types' => ['post'],
            'discussionbridge_comments_mode' => 'fullInteractive',
            'discussionbridge_service_author' => 'bridge-service',
        ],
        'users' => [
            7 => new WP_User(7, 'bridge-service', 'DiscussionBridge Service', ['publish_posts' => true]),
            9 => new WP_User(9, 'source-author', 'Source Author', ['publish_posts' => true]),
        ],
        'posts' => [], 'meta' => [], 'scheduled' => [], 'requests' => [], 'responses' => [],
        'transients' => [], 'actions' => [], 'filters' => [], 'styles' => [], 'scripts' => [],
        'settings_errors' => [], 'inserted' => [], 'deleted' => [], 'next_post_id' => 100,
        'queried_id' => 0, 'is_admin' => false, 'is_singular' => true, 'in_loop' => true,
        'main_query' => true, 'uuid_counter' => 1, 'wp_update_callback' => null,
    ];
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['dbt']['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = true): bool { $GLOBALS['dbt']['options'][$key] = $value; return true; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = true): bool { if (array_key_exists($key, $GLOBALS['dbt']['options'])) return false; $GLOBALS['dbt']['options'][$key] = $value; return true; }
function register_setting(...$args): void {}
function add_settings_error(string $setting, string $code, string $message): void { $GLOBALS['dbt']['settings_errors'][] = [$setting, $code, $message]; }
function sanitize_user(string $value, bool $strict = false): string { unset($strict); return strtolower(preg_replace('/[^a-zA-Z0-9_.@-]/', '', $value) ?? ''); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function sanitize_title(string $value): string { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? ''), '-'); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_is_uuid(mixed $value): bool { return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1; }
function wp_generate_uuid4(): string { return sprintf('00000000-0000-4000-8000-%012d', $GLOBALS['dbt']['uuid_counter']++); }
function get_user_by(string $field, mixed $value): WP_User|false { foreach ($GLOBALS['dbt']['users'] as $user) { if (($field === 'login' && $user->user_login === $value) || ($field === 'id' && $user->ID === (int) $value)) return $user; } return false; }
function user_can(WP_User $user, string $capability): bool { return !empty($user->capabilities[$capability]); }
function get_author_posts_url(int $id): string { return 'https://wordpress.example/author/' . ($GLOBALS['dbt']['users'][$id]->user_login ?? 'unknown') . '/'; }
function home_url(string $path = ''): string { return 'https://wordpress.example' . $path; }
function get_post(int $id): WP_Post|null { return $GLOBALS['dbt']['posts'][$id] ?? null; }
function get_post_meta(int $id, string $key, bool $single = false): mixed { unset($single); return $GLOBALS['dbt']['meta'][$id][$key] ?? ''; }
function update_post_meta(int $id, string $key, mixed $value): int { $GLOBALS['dbt']['meta'][$id][$key] = $value; return 1; }
function delete_post_meta(int $id, string $key): bool { unset($GLOBALS['dbt']['meta'][$id][$key]); return true; }
function wp_is_post_revision(int $id): bool { return false; }
function wp_is_post_autosave(int $id): bool { return false; }
function wp_next_scheduled(string $hook, array $args = []): int|false { foreach ($GLOBALS['dbt']['scheduled'] as $item) if ($item[1] === $hook && $item[2] === $args) return $item[0]; return false; }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool { $GLOBALS['dbt']['scheduled'][] = [$timestamp, $hook, $args]; return true; }
function get_permalink(WP_Post|int $post): string { $id = $post instanceof WP_Post ? $post->ID : $post; $slug = $GLOBALS['dbt']['posts'][$id]->post_name ?: 'post-' . $id; return 'https://wordpress.example/' . $slug . '/'; }
function get_the_title(WP_Post|int $post): string { $id = $post instanceof WP_Post ? $post->ID : $post; return $GLOBALS['dbt']['posts'][$id]->post_title ?? ''; }
function parse_blocks(string $content): array { return [['blockName' => null, 'innerHTML' => $content, 'innerBlocks' => []]]; }
function serialize_blocks(array $blocks): string { return implode('', array_map(fn($b) => (string) ($b['innerHTML'] ?? ''), $blocks)); }
function do_blocks(string $content): string { return $content; }
function wp_kses_post(string $html): string { return preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? ''; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function wp_safe_remote_request(string $url, array $args): array|WP_Error { $GLOBALS['dbt']['requests'][] = [$url, $args]; $response = $GLOBALS['dbt']['responses'][$url] ?? null; return is_callable($response) ? $response($url, $args) : ($response ?? new WP_Error('missing_mock')); }
function wp_safe_remote_get(string $url, array $args): array|WP_Error { return wp_safe_remote_request($url, $args); }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_header(array $response, string $name): string { return (string) ($response['headers'][strtolower($name)] ?? ''); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function dbt_response(int $status, mixed $body, string $content_type = 'application/json'): array { return ['response' => ['code' => $status], 'headers' => ['content-type' => $content_type], 'body' => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES)]; }
function get_posts(array $args): array { if (($args['fields'] ?? '') === 'ids' && isset($args['meta_key'])) { $ids = []; foreach ($GLOBALS['dbt']['meta'] as $id => $meta) if (($meta[$args['meta_key']] ?? null) === ($args['meta_value'] ?? null)) $ids[] = $id; return array_slice($ids, 0, (int) ($args['posts_per_page'] ?? 5)); } return array_values($GLOBALS['dbt']['posts']); }
function url_to_postid(string $url): int { foreach ($GLOBALS['dbt']['posts'] as $post) if (get_permalink($post) === $url) return $post->ID; return 0; }
function wp_insert_post(array $data, bool $wp_error = false): int|WP_Error { unset($wp_error); $id = (int) ($data['ID'] ?: $GLOBALS['dbt']['next_post_id']++); $post = $GLOBALS['dbt']['posts'][$id] ?? new WP_Post($id); foreach (['post_type','post_status','post_name','post_title','post_content','post_author'] as $key) if (array_key_exists($key, $data)) $post->{$key} = $data[$key]; $GLOBALS['dbt']['posts'][$id] = $post; $GLOBALS['dbt']['inserted'][] = $data; return $id; }
function wp_update_post(array $data): int|WP_Error { $callback = $GLOBALS['dbt']['wp_update_callback']; return is_callable($callback) ? $callback($data) : wp_insert_post($data, true); }
function wp_delete_post(int $id, bool $force = false): WP_Post|false { unset($force); $post = $GLOBALS['dbt']['posts'][$id] ?? false; unset($GLOBALS['dbt']['posts'][$id], $GLOBALS['dbt']['meta'][$id]); $GLOBALS['dbt']['deleted'][] = $id; return $post; }
function clean_post_cache(int $id): void {}
function untrailingslashit(string $value): string { return rtrim($value, '/'); }
function get_transient(string $key): mixed { return $GLOBALS['dbt']['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { unset($ttl); $GLOBALS['dbt']['transients'][$key] = $value; return true; }
function apply_filters(string $hook, mixed $value, ...$args): mixed { unset($hook, $args); return $value; }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES); }
function esc_html__(string $value, string $domain = ''): string { unset($domain); return $value; }
function esc_attr__(string $value, string $domain = ''): string { unset($domain); return $value; }
function __(string $value, string $domain = ''): string { unset($domain); return $value; }
function _n(string $single, string $plural, int $number, string $domain = ''): string { unset($domain); return $number === 1 ? $single : $plural; }
function shortcode_atts(array $pairs, array $atts, string $shortcode = ''): array { unset($shortcode); return array_merge($pairs, $atts); }
function plugins_url(string $path, string $plugin): string { unset($plugin); return 'https://wordpress.example/wp-content/plugins/discussionbridge/' . $path; }
function plugin_dir_path(string $plugin): string { unset($plugin); return dirname(__DIR__) . '/'; }
function wp_enqueue_style(string $handle, ...$args): void { $GLOBALS['dbt']['styles'][] = $handle; }
function wp_enqueue_script(string $handle, ...$args): void { $GLOBALS['dbt']['scripts'][] = $handle; }
function wp_register_script(string $handle, ...$args): void { $GLOBALS['dbt']['scripts'][] = $handle; }
function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool { $GLOBALS['dbt']['inline'][$handle][] = [$position, $data]; return true; }
function register_block_type(string $path, array $args): void { $GLOBALS['dbt']['block'] = [$path, $args]; }
function is_admin(): bool { return $GLOBALS['dbt']['is_admin']; }
function is_singular(array|string $types = ''): bool { unset($types); return $GLOBALS['dbt']['is_singular']; }
function get_queried_object_id(): int { return $GLOBALS['dbt']['queried_id']; }
function in_the_loop(): bool { return $GLOBALS['dbt']['in_loop']; }
function is_main_query(): bool { return $GLOBALS['dbt']['main_query']; }
function add_action(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { $GLOBALS['dbt']['actions'][] = [$hook, $callback, $priority, $args]; }
function add_filter(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { $GLOBALS['dbt']['filters'][] = [$hook, $callback, $priority, $args]; }
function add_shortcode(string $tag, callable|array $callback): void { $GLOBALS['dbt']['shortcodes'][$tag] = $callback; }
function get_post_types(array $args = [], string $output = 'names'): array { unset($args, $output); return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']; }

require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/Client.php';
require_once __DIR__ . '/../src/WpDiscourseGuard.php';
require_once __DIR__ . '/../src/PublishedContent.php';
require_once __DIR__ . '/../src/SourceAuthors.php';
require_once __DIR__ . '/../src/TableOfContents.php';
require_once __DIR__ . '/../src/Publisher.php';
require_once __DIR__ . '/../src/Materializer.php';
require_once __DIR__ . '/../src/Presentation.php';
require_once __DIR__ . '/../src/Plugin.php';

dbt_reset();
