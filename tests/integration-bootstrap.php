<?php

declare(strict_types=1);

if (!extension_loaded('sqlite3') || !class_exists('SQLite3')) {
    throw new RuntimeException('WordPress integration fixtures require the SQLite3 extension. Enable sqlite3 before running tests/integration.php; no array-based database fallback is supported.');
}

const ABSPATH = '/srv/www/wordpress/';
const DISCUSSIONBRIDGE_WORDPRESS_FILE = __DIR__ . '/../wordpress-discussion-bridge.php';
const DISCUSSIONBRIDGE_WORDPRESS_VERSION = '0.4.0-alpha.1';
const MINUTE_IN_SECONDS = 60;

require_once __DIR__ . '/native-preparation-api.php';

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
        public string $post_name = '',
        public string $post_date_gmt = '',
        public string $post_modified_gmt = ''
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

final class WP_Term
{
    public function __construct(public int $term_id, public int $term_taxonomy_id, public string $taxonomy, public string $name) {}
}

final class WP_Query
{
    public function __construct(private array $args = []) {}
    public function get(string $key): mixed { return $this->args[$key] ?? null; }
}

final class WP_REST_Request
{
    public function __construct(private array $params) {}
    public function get_param(string $key): mixed { return $this->params[$key] ?? null; }
}

/** Author fixture for WordPress's prepared postmeta census; SQLite execution is required. */
final class DBT_WPDB
{
    public string $postmeta = 'wp_postmeta';
    public string $posts = 'wp_posts';
    public string $options = 'wp_options';
    public string $term_relationships = 'wp_term_relationships';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    private ?array $transaction = null;
    private bool $savepoint = false;

    public function get_row(string $query): ?stdClass
    {
        $this->last_error = '';
        $callback = $GLOBALS['dbt']['native_row_callback'] ?? null; if (is_callable($callback)) $callback($query);
        if (preg_match("/WHERE ID = '([0-9]+)'/", $query, $match)) {
            $native = $GLOBALS['dbt']['posts'][(int) $match[1]] ?? null; return $native ? (object) get_object_vars($native) : null;
        }
        throw new RuntimeException('Unexpected native row query');
    }

    public function query(string $query): int|false
    {
        if ($query === 'START TRANSACTION') { $this->transaction = unserialize(serialize(array_intersect_key($GLOBALS['dbt'], array_flip(['posts', 'meta', 'options', 'post_terms'])))); return 1; }
        if (str_starts_with($query, 'SAVEPOINT ')) { $this->savepoint = true; return 1; }
        if (str_starts_with($query, 'RELEASE SAVEPOINT ')) { $ok = $this->savepoint; $this->savepoint = false; return $ok ? 1 : false; }
        if (str_starts_with($query, 'ROLLBACK TO SAVEPOINT ')) return $this->savepoint ? 1 : false;
        if ($query === 'ROLLBACK') { if ($this->transaction !== null) foreach ($this->transaction as $key => $value) $GLOBALS['dbt'][$key] = $value; $this->transaction = null; $this->savepoint = false; return 1; }
        if ($query === 'COMMIT') { $this->transaction = null; $this->savepoint = false; return 1; }
        throw new RuntimeException('Unexpected native transaction query');
    }

    public function get_var(string $query): mixed
    {
        $callback = $GLOBALS['dbt']['native_query_callback'] ?? null; if (is_callable($callback)) $callback($query);
        if (str_contains($query, 'information_schema.TABLES')) return $GLOBALS['dbt']['engine'];
        if ($query === 'SELECT CONNECTION_ID()') return (string) $GLOBALS['dbt']['connection_id'];
        if (str_contains($query, 'GET_LOCK(')) {
            if ($GLOBALS['dbt']['native_lock'] === 'other') return '0';
            $GLOBALS['dbt']['native_lock'] = 'worker'; return '1';
        }
        if (str_contains($query, 'IS_USED_LOCK(')) return $GLOBALS['dbt']['native_lock'] === 'worker' ? '1' : '0';
        if (str_contains($query, 'RELEASE_LOCK(')) { if ($GLOBALS['dbt']['native_lock'] === 'worker') $GLOBALS['dbt']['native_lock'] = null; return '1'; }
        throw new RuntimeException('Unexpected native worker query');
    }

    public function prepare(string $query, string ...$arguments): string
    {
        $position = 0;
        return preg_replace_callback('/%s/', static function () use (&$position, $arguments): string {
            return "'" . str_replace("'", "''", $arguments[$position++]) . "'";
        }, $query);
    }

    public function get_col(string $query): mixed
    {
        $this->last_error = '';
        $GLOBALS['dbt']['census_queries'][] = $query;
        if (str_starts_with($query, 'SELECT option_name FROM ')) {
            preg_match("/BINARY option_name > BINARY '([^']*)'/", $query, $match);
            $keys = array_values(array_filter(array_keys($GLOBALS['dbt']['options']), fn($key) => str_starts_with($key, 'discussionbridge_native_') && strcmp($key, $match[1]) > 0));
            sort($keys, SORT_STRING); return array_slice($keys, 0, 1);
        }
        if (str_contains($query, 'SELECT term_taxonomy_id FROM')) {
            preg_match("/object_id = '([0-9]+)'/", $query, $match);
            $ids = $GLOBALS['dbt']['post_terms'][(int) $match[1]] ?? []; sort($ids, SORT_NUMERIC); return array_map('strval', $ids);
        }
        $override = $GLOBALS['dbt']['census_override'];
        if (is_callable($override)) return $override($this, $query);
        $database = new SQLite3(':memory:');
        try {
            $database->enableExceptions(true);
            $database->exec("CREATE TABLE {$this->postmeta} (post_id INTEGER, meta_key TEXT COLLATE NOCASE, meta_value TEXT COLLATE NOCASE)");
            $insert = $database->prepare("INSERT INTO {$this->postmeta} VALUES (:id, :key, :value)");
            foreach ($GLOBALS['dbt']['meta'] as $id => $metadata) foreach ($metadata as $key => $value) {
                $insert->bindValue(':id', $id, SQLITE3_INTEGER); $insert->bindValue(':key', $key, SQLITE3_TEXT);
                $insert->bindValue(':value', is_scalar($value) ? (string) $value : json_encode($value), SQLITE3_TEXT);
                $result = $insert->execute(); $result->finalize(); $insert->reset();
            }
            foreach ($GLOBALS['dbt']['extra_postmeta_rows'] as [$id, $key, $value]) {
                $insert->bindValue(':id', $id, SQLITE3_INTEGER); $insert->bindValue(':key', $key, SQLITE3_TEXT);
                $insert->bindValue(':value', $value, SQLITE3_TEXT);
                $result = $insert->execute(); $result->finalize(); $insert->reset();
            }
            $rows = $database->query($query); $claims = [];
            while ($row = $rows->fetchArray(SQLITE3_NUM)) $claims[] = (string) $row[0];
            $rows->finalize();
            return $claims;
        } finally { $database->close(); }
    }
}

$GLOBALS['dbt'] = [];

function dbt_reset(): void
{
    if (class_exists(DiscussionBridge\WordPress\CmsNativeFence::class) && isset($GLOBALS['wpdb'])) DiscussionBridge\WordPress\CmsNativeFence::cancel();
    // Each fixture represents a separate native request; request-local enqueue state does not survive it.
    if (class_exists(DiscussionBridge\WordPress\Presentation::class)) {
        (new ReflectionProperty(DiscussionBridge\WordPress\Presentation::class, 'embed_enqueued'))->setValue(null, false);
        (new ReflectionProperty(DiscussionBridge\WordPress\Presentation::class, 'embed_sequence'))->setValue(null, 0);
    }
    $GLOBALS['wpdb'] = new DBT_WPDB();
    $GLOBALS['dbt'] = [
        'options' => [
            'discussionbridge_server_url' => 'https://bridge.example',
            'discussionbridge_connection_id' => 'dbc_aaaaaaaaaaaaaaaaaaaaaaaa',
            'discussionbridge_site_id' => '11111111-1111-4111-8111-111111111111',
            'discussionbridge_lane' => 'wordpress',
            'discussionbridge_post_types' => ['post'],
            'discussionbridge_comments_mode' => 'interactive',
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
        'permalink_prefix' => '', 'meta_write_callback' => null, 'wp_insert_callback' => null,
        'census_queries' => [], 'census_override' => null, 'extra_postmeta_rows' => [],
        'native_lock' => null,
        'option_write_callback' => null,
        'terms' => [], 'native_taxonomies' => ['category' => ['post'], 'post_tag' => ['post']],
        'post_terms' => [], 'engine' => 'InnoDB', 'wp_before_sql_callback' => null,
        'connection_id' => 11,
        'native_cache_enabled' => false, 'post_cache' => [], 'meta_cache' => [], 'cache_invalidations' => [],
    ];
}

function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['dbt']['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = true): bool { $callback = $GLOBALS['dbt']['option_write_callback']; if (is_callable($callback) && $callback($key, $value) === false) return false; $GLOBALS['dbt']['options'][$key] = $value; return true; }
function add_option(string $key, mixed $value, string $deprecated = '', bool $autoload = true): bool { if (array_key_exists($key, $GLOBALS['dbt']['options'])) return false; $GLOBALS['dbt']['options'][$key] = $value; return true; }
function delete_option(string $key): bool { unset($GLOBALS['dbt']['options'][$key]); return true; }
function wp_cache_delete(string|int $key, string $group = ''): bool { if ($group === 'post_meta') unset($GLOBALS['dbt']['meta_cache'][(int) $key]); $GLOBALS['dbt']['cache_invalidations'][] = [$key, $group]; return true; }
function register_setting(...$args): void {}
function add_settings_error(string $setting, string $code, string $message): void { $GLOBALS['dbt']['settings_errors'][] = [$setting, $code, $message]; }
function wp_salt(string $scheme = 'auth'): string { return 'discussionbridge-test-' . $scheme . '-salt'; }
function wp_unslash(mixed $value): mixed { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function wp_slash(mixed $value): mixed { return is_array($value) ? array_map('wp_slash', $value) : (is_string($value) ? addslashes($value) : $value); }
function sanitize_text_field(string $value): string { return trim($value); }
function wp_verify_nonce(string $nonce, string $action): bool { unset($nonce, $action); return $GLOBALS['dbt']['nonce_valid'] ?? true; }
function current_user_can(string $capability, mixed ...$args): bool { unset($args); return $GLOBALS['dbt']['capabilities'][$capability] ?? true; }
function sanitize_user(string $value, bool $strict = false): string { unset($strict); return strtolower(preg_replace('/[^a-zA-Z0-9_.@-]/', '', $value) ?? ''); }
function sanitize_key(string $value): string { return strtolower(preg_replace('/[^a-z0-9_\-]/', '', $value) ?? ''); }
function sanitize_title(string $value): string { return trim(strtolower(preg_replace('/[^a-z0-9]+/i', '-', $value) ?? ''), '-'); }
function wp_parse_url(string $url, int $component = -1): mixed { return parse_url($url, $component); }
function wp_is_uuid(mixed $value): bool { return is_string($value) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value) === 1; }
function wp_generate_uuid4(): string { return sprintf('00000000-0000-4000-8000-%012d', $GLOBALS['dbt']['uuid_counter']++); }
function get_user_by(string $field, mixed $value): WP_User|false { foreach ($GLOBALS['dbt']['users'] as $user) { if (($field === 'login' && $user->user_login === $value) || ($field === 'id' && $user->ID === (int) $value)) return $user; } return false; }
function user_can(WP_User $user, string $capability): bool { return !empty($user->capabilities[$capability]); }
function get_author_posts_url(int $id): string { return $GLOBALS['dbt']['author_posts_url'] ?? ('https://wordpress.example/author/' . ($GLOBALS['dbt']['users'][$id]->user_login ?? 'unknown') . '/'); }
function home_url(string $path = ''): string { return ($GLOBALS['dbt']['native_origin'] ?? 'https://wordpress.example') . $path; }
function get_post(int $id): WP_Post|null { if (!$GLOBALS['dbt']['native_cache_enabled']) return $GLOBALS['dbt']['posts'][$id] ?? null; if (!array_key_exists($id, $GLOBALS['dbt']['post_cache'])) $GLOBALS['dbt']['post_cache'][$id] = isset($GLOBALS['dbt']['posts'][$id]) ? clone $GLOBALS['dbt']['posts'][$id] : null; return $GLOBALS['dbt']['post_cache'][$id]; }
function get_post_meta(int $id, string $key, bool $single = false): mixed { unset($single); if (!$GLOBALS['dbt']['native_cache_enabled']) return $GLOBALS['dbt']['meta'][$id][$key] ?? ''; if (!isset($GLOBALS['dbt']['meta_cache'][$id])) $GLOBALS['dbt']['meta_cache'][$id] = $GLOBALS['dbt']['meta'][$id] ?? []; return $GLOBALS['dbt']['meta_cache'][$id][$key] ?? ''; }
function metadata_exists(string $type, int $id, string $key): bool { return $type === 'post' && array_key_exists($key, $GLOBALS['dbt']['meta'][$id] ?? []); }
function update_post_meta(int $id, string $key, mixed $value): int|false { $callback = $GLOBALS['dbt']['meta_write_callback']; if (is_callable($callback) && $callback($id, $key, $value) === false) return false; $value = wp_unslash($value); if (metadata_exists('post', $id, $key) && $GLOBALS['dbt']['meta'][$id][$key] === $value) return false; $GLOBALS['dbt']['meta'][$id][$key] = $value; return 1; }
function delete_post_meta(int $id, string $key): bool { unset($GLOBALS['dbt']['meta'][$id][$key]); return true; }
function wp_is_post_revision(int $id): bool { return false; }
function wp_is_post_autosave(int $id): bool { return false; }
function wp_next_scheduled(string $hook, array $args = []): int|false { $times = []; foreach ($GLOBALS['dbt']['scheduled'] as $item) if ($item[1] === $hook && $item[2] === $args) $times[] = $item[0]; return $times === [] ? false : min($times); }
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool { $callback = $GLOBALS['dbt']['before_schedule'] ?? null; if (is_callable($callback)) $callback($timestamp, $hook, $args); if ($GLOBALS['dbt']['schedule_failure'] ?? false) return false; $GLOBALS['dbt']['scheduled'][] = [$timestamp, $hook, $args]; return true; }
function wp_clear_scheduled_hook(string $hook, array $args = []): int|false { $callback = $GLOBALS['dbt']['before_clear'] ?? null; if (is_callable($callback)) $callback($hook, $args); if ($GLOBALS['dbt']['clear_failure'] ?? false) return false; $before = count($GLOBALS['dbt']['scheduled']); $GLOBALS['dbt']['scheduled'] = array_values(array_filter($GLOBALS['dbt']['scheduled'], fn($item) => $item[1] !== $hook || $item[2] !== $args)); return $before - count($GLOBALS['dbt']['scheduled']); }
function get_permalink(WP_Post|int $post): string { $id = $post instanceof WP_Post ? $post->ID : $post; $slug = $GLOBALS['dbt']['posts'][$id]->post_name ?: 'post-' . $id; return home_url('/' . $GLOBALS['dbt']['permalink_prefix'] . $slug . '/'); }
function get_the_title(WP_Post|int $post): string { $id = $post instanceof WP_Post ? $post->ID : $post; return $GLOBALS['dbt']['posts'][$id]->post_title ?? ''; }
function parse_blocks(string $content): array { return [['blockName' => null, 'innerHTML' => $content, 'innerBlocks' => []]]; }
function serialize_blocks(array $blocks): string { return implode('', array_map(fn($b) => (string) ($b['innerHTML'] ?? ''), $blocks)); }
function do_blocks(string $content): string { return isset($GLOBALS['dbt']['native_render_callback']) ? ($GLOBALS['dbt']['native_render_callback'])($content) : $content; }
function wp_kses_post(string $html): string { $html = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $html) ?? ''; return isset($GLOBALS['dbt']['native_kses_callback']) ? ($GLOBALS['dbt']['native_kses_callback'])($html) : $html; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function wp_json_encode(mixed $value, int $flags = 0): string|false { return json_encode($value, $flags); }
function maybe_serialize(mixed $value): string { return is_array($value) || is_object($value) ? serialize($value) : (string) $value; }
function wp_safe_remote_request(string $url, array $args): array|WP_Error { $GLOBALS['dbt']['requests'][] = [$url, $args]; $response = $GLOBALS['dbt']['responses'][$url] ?? null; return is_callable($response) ? $response($url, $args) : ($response ?? new WP_Error('missing_mock')); }
function wp_safe_remote_get(string $url, array $args): array|WP_Error { $args['method'] = 'GET'; return wp_safe_remote_request($url, $args); }
function wp_remote_retrieve_response_code(array $response): int { return (int) ($response['response']['code'] ?? 0); }
function wp_remote_retrieve_header(array $response, string $name): string { return (string) ($response['headers'][strtolower($name)] ?? ''); }
function wp_remote_retrieve_body(array $response): string { return (string) ($response['body'] ?? ''); }
function dbt_response(int $status, mixed $body, string $content_type = 'application/json'): array { return ['response' => ['code' => $status], 'headers' => ['content-type' => $content_type], 'body' => is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_SLASHES)]; }
function get_posts(array $args): array {
    $posts = array_filter($GLOBALS['dbt']['posts'], static function (WP_Post $post) use ($args): bool {
        if (isset($args['post_type']) && !in_array($post->post_type, (array) $args['post_type'], true)) return false;
        if (isset($args['post_status']) && !in_array($post->post_status, (array) $args['post_status'], true)) return false;
        return !isset($args['meta_key']) || get_post_meta($post->ID, $args['meta_key'], true) === ($args['meta_value'] ?? null);
    });
    $results = ($args['fields'] ?? '') === 'ids' ? array_map(static fn(WP_Post $post): int => $post->ID, array_values($posts)) : array_values($posts);
    return array_slice($results, 0, (int) ($args['posts_per_page'] ?? 5));
}
function url_to_postid(string $url): int { foreach ($GLOBALS['dbt']['posts'] as $post) if (get_permalink($post) === $url) return $post->ID; return 0; }
function wp_insert_post(array $data, bool $wp_error = false): int|WP_Error {
    if (class_exists(DiscussionBridge\WordPress\CmsNativeFence::class) && DiscussionBridge\WordPress\CmsNativeFence::reject_unversioned(false, $data)) return $wp_error ? new WP_Error('empty_content') : 0;
    $id = (int) (($data['ID'] ?? 0) ?: $GLOBALS['dbt']['next_post_id']++);
    $before = $GLOBALS['dbt']['wp_before_sql_callback']; if (is_callable($before)) $before($id, $data);
    $original = $data;
    $prepared = array_intersect_key($data, array_flip(['post_type','post_status','post_name','post_title','post_content','post_author']));
    $final = $GLOBALS['dbt']['native_final_data_callback'] ?? null; if (is_callable($final)) $prepared = $final($prepared, $original);
    if (class_exists(DiscussionBridge\WordPress\CmsNativeFence::class)) $prepared = DiscussionBridge\WordPress\CmsNativeFence::capture_data($prepared, $data, $original);
    $data = array_replace($data, $prepared);
    if (isset($GLOBALS['dbt']['posts'][$id]) && class_exists(DiscussionBridge\WordPress\CmsNativeFence::class)) DiscussionBridge\WordPress\CmsNativeFence::before_update($id);
    $post = $GLOBALS['dbt']['posts'][$id] ?? new WP_Post($id); foreach (['post_type','post_status','post_name','post_title','post_content','post_author'] as $key) if (array_key_exists($key, $data)) $post->{$key} = wp_unslash($data[$key]);
    foreach (['post_date_gmt', 'post_modified_gmt'] as $key) if (array_key_exists($key, $data)) $post->$key = wp_unslash($data[$key]);
    $GLOBALS['dbt']['posts'][$id] = $post; $GLOBALS['dbt']['inserted'][] = $data;
    foreach ($data['meta_input'] ?? [] as $key => $value) update_post_meta($id, $key, $value);
    clean_post_cache($id);
    $callback = $GLOBALS['dbt']['wp_insert_callback']; $result = is_callable($callback) ? $callback($id, $data) : $id;
    if (class_exists(DiscussionBridge\WordPress\CmsNativeFence::class)) DiscussionBridge\WordPress\CmsNativeFence::after_insert($id);
    return $result;
}
function wp_update_post(array $data, bool $wp_error = false): int|WP_Error { $callback = $GLOBALS['dbt']['wp_update_callback']; if (is_callable($callback)) return $callback($data); if (($GLOBALS['dbt']['native_update_merge'] ?? false) && isset($GLOBALS['dbt']['posts'][(int) ($data['ID'] ?? 0)])) { $data = array_replace(get_object_vars($GLOBALS['dbt']['posts'][(int) $data['ID']]), $data); if (isset($GLOBALS['dbt']['native_modified_time'])) $data['post_modified_gmt'] = $GLOBALS['dbt']['native_modified_time']; } return wp_insert_post($data, $wp_error); }
function get_sample_permalink(int $id, ?string $title = null, ?string $name = null): array { unset($title); return $GLOBALS['dbt']['sample_permalink'] ?? [home_url('/' . $GLOBALS['dbt']['permalink_prefix'] . '%postname%/'), $name ?? $GLOBALS['dbt']['posts'][$id]->post_name]; }
function get_stylesheet_directory(): string { return __DIR__ . '/fixtures/source-article-theme'; }
function get_template_directory(): string { return get_stylesheet_directory(); }
function get_stylesheet(): string { return 'source-article-model-theme'; }
function get_template(): string { return get_stylesheet(); }
function is_user_logged_in(): bool { return $GLOBALS['dbt']['logged_in'] ?? false; }
function is_preview(): bool { return $GLOBALS['dbt']['preview'] ?? false; }
function wp_delete_post(int $id, bool $force = false): WP_Post|false { unset($force); $post = $GLOBALS['dbt']['posts'][$id] ?? false; unset($GLOBALS['dbt']['posts'][$id], $GLOBALS['dbt']['meta'][$id]); $GLOBALS['dbt']['deleted'][] = $id; return $post; }
function clean_post_cache(int $id): void { unset($GLOBALS['dbt']['post_cache'][$id], $GLOBALS['dbt']['meta_cache'][$id]); $GLOBALS['dbt']['cache_invalidations'][] = [$id, 'posts']; }
function untrailingslashit(string $value): string { return rtrim($value, '/'); }
function get_transient(string $key): mixed { return $GLOBALS['dbt']['transients'][$key] ?? false; }
function set_transient(string $key, mixed $value, int $ttl): bool { unset($ttl); $GLOBALS['dbt']['transients'][$key] = $value; return true; }
function delete_transient(string $key): bool { unset($GLOBALS['dbt']['transients'][$key]); return true; }
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
function wp_register_script(string $handle, ...$args): void { $GLOBALS['dbt']['registered_scripts'][] = $handle; }
function wp_add_inline_script(string $handle, string $data, string $position = 'after'): bool { $GLOBALS['dbt']['inline'][$handle][] = [$position, $data]; return true; }
function register_block_type(string $path, array $args): void { $GLOBALS['dbt']['block'] = [$path, $args]; }
function is_admin(): bool { return $GLOBALS['dbt']['is_admin']; }
function is_singular(array|string $types = ''): bool { unset($types); return $GLOBALS['dbt']['is_singular']; }
function get_queried_object_id(): int { return $GLOBALS['dbt']['queried_id']; }
function in_the_loop(): bool { return $GLOBALS['dbt']['in_loop']; }
function is_main_query(): bool { return $GLOBALS['dbt']['main_query']; }
function add_action(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { $GLOBALS['dbt']['actions'][] = [$hook, $callback, $priority, $args]; }
function add_filter(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { $GLOBALS['dbt']['filters'][] = [$hook, $callback, $priority, $args]; }
function remove_filter(string $hook, callable|array $callback, int $priority = 10): bool { foreach ($GLOBALS['dbt']['filters'] as $index => $filter) if ($filter[0] === $hook && $filter[1] === $callback && $filter[2] === $priority) { unset($GLOBALS['dbt']['filters'][$index]); return true; } return false; }
function setup_postdata(WP_Post $post): bool { return true; }
function current_time(string $type): string { return date('Y-m-d H:i:s'); }
function user_trailingslashit(string $path, string $type = ''): string { return rtrim($path, '/') . '/'; }
function wp_unique_post_slug(string $slug, int $id, string $status, string $type, int $parent): string { $candidate = $slug; $suffix = 2; while (array_filter($GLOBALS['dbt']['posts'], fn($p) => $p->ID !== $id && $p->post_type === $type && $p->post_name === $candidate)) $candidate = $slug . '-' . $suffix++; return $candidate; }
function add_shortcode(string $tag, callable|array $callback): void { $GLOBALS['dbt']['shortcodes'][$tag] = $callback; }
function get_post_types(array $args = [], string $output = 'names'): array { unset($args, $output); return ['post' => 'post', 'page' => 'page', 'attachment' => 'attachment']; }
function get_post_type_object(string $post_type): ?stdClass { return isset(get_post_types()[$post_type]) ? (object) ['cap' => (object) ['publish_posts' => $post_type === 'page' ? 'publish_pages' : 'publish_posts']] : null; }
function get_users(array $args = []): array { return array_values($GLOBALS['dbt']['users']); }
function get_object_taxonomies(string $type, string $output = 'names'): array { return array_keys(array_filter($GLOBALS['dbt']['native_taxonomies'], fn($types) => in_array($type, $types, true))); }
function get_terms(array $args): array { return array_values(array_filter($GLOBALS['dbt']['terms'], fn($term) => in_array($term->taxonomy, $args['taxonomy'], true))); }
function get_term_by(string $field, int|string $value, string $taxonomy = ''): WP_Term|false { foreach ($GLOBALS['dbt']['terms'] as $term) if ($field === 'term_taxonomy_id' && $term->term_taxonomy_id === (int) $value && ($taxonomy === '' || $taxonomy === $term->taxonomy)) return $term; return false; }
function get_term(int $id, string $taxonomy = ''): WP_Term|false { foreach ($GLOBALS['dbt']['terms'] as $term) if ($term->term_id === $id && ($taxonomy === '' || $term->taxonomy === $taxonomy)) return $term; return false; }
function clean_term_cache(array $ids, string $taxonomy = ''): void { foreach ($ids as $id) $GLOBALS['dbt']['cache_invalidations'][] = [$id, 'terms']; }
function taxonomy_exists(string $taxonomy): bool { return isset($GLOBALS['dbt']['native_taxonomies'][$taxonomy]); }
function is_object_in_taxonomy(string $type, string $taxonomy): bool { return in_array($type, $GLOBALS['dbt']['native_taxonomies'][$taxonomy] ?? [], true); }
function wp_set_object_terms(int $id, array $terms, string $taxonomy, bool $append = false): array|WP_Error { $ids = []; foreach ($terms as $term_id) { $matches = array_filter($GLOBALS['dbt']['terms'], fn($term) => $term->term_id === $term_id && $term->taxonomy === $taxonomy); if (!$matches) return new WP_Error('term_unavailable'); $ids[] = array_values($matches)[0]->term_taxonomy_id; } $keep = array_filter($GLOBALS['dbt']['post_terms'][$id] ?? [], function ($ttid) use ($taxonomy): bool { foreach ($GLOBALS['dbt']['terms'] as $term) if ($term->term_taxonomy_id === $ttid) return $term->taxonomy !== $taxonomy; return true; }); $GLOBALS['dbt']['post_terms'][$id] = array_merge($keep, $ids); return $ids; }
function get_post_stati(array $args = [], string $output = 'names'): array { unset($args, $output); return array_combine(['publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft', 'inherit'], ['publish', 'draft', 'private', 'pending', 'future', 'trash', 'auto-draft', 'inherit']); }

spl_autoload_register(static function (string $class): void {
    $prefix = 'DiscussionBridge' . chr(92) . 'WordPress' . chr(92);
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (str_contains($name, chr(92))) return;
    $path = __DIR__ . '/../src/' . $name . '.php';
    if (is_file($path)) require_once $path;
});
require_once __DIR__ . '/../src/CmsArtifacts.php';
dbt_reset();
