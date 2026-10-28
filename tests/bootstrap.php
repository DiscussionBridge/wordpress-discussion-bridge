<?php

declare(strict_types=1);

class WP_Error {
    public function __construct(private string $code, private string $message = '') {}
    public function get_error_code(): string { return $this->code; }
    public function get_error_message(): string { return $this->message; }
}
class WP_Post {
    public int $ID = 1; public string $post_status = 'publish'; public string $post_type = 'post'; public string $post_name = 'native-source';
    public string $post_title = 'Native title'; public string $post_content = '<p>Native article</p>'; public int $post_author = 7;
    public string $post_date_gmt = '2026-10-14 03:04:05'; public string $post_modified_gmt = '0000-00-00 00:00:00';
}
class WP_User { public int $ID = 7; public string $display_name = 'Native Author'; }
class NativeTestDb {
    public string $posts = 'wp_posts'; public string $last_error = '';
    public function __construct(public string $connection) {}
    public function prepare(string $query, string ...$values): string {
        foreach ($values as $value) $query = preg_replace('/%s/', "'" . str_replace("'", "''", $value) . "'", $query, 1);
        return $query;
    }
    public function get_var(string $query): string|null {
        if ($query === 'SELECT CONNECTION_ID()') return $this->connection;
        preg_match("/\('([^']+)'/", $query, $match); $name = $match[1] ?? '';
        if (str_contains($query, 'IS_USED_LOCK')) return ($GLOBALS['native']['locks'][$name] ?? null) === $this->connection ? '1' : '0';
        if (str_contains($query, 'GET_LOCK')) {
            if (isset($GLOBALS['native']['locks'][$name]) && $GLOBALS['native']['locks'][$name] !== $this->connection) return '0';
            $GLOBALS['native']['locks'][$name] = $this->connection; return '1';
        }
        if (str_contains($query, 'RELEASE_LOCK')) {
            if (($GLOBALS['native']['locks'][$name] ?? null) !== $this->connection) return '0';
            unset($GLOBALS['native']['locks'][$name]); return '1';
        }
        throw new RuntimeException('Unexpected native test query');
    }
    public function get_row(string $query): object|null {
        preg_match("/ID = '([0-9]+)'/", $query, $match); $post = $GLOBALS['native']['posts'][(int) ($match[1] ?? 0)] ?? null;
        return $post === null ? null : (object) get_object_vars($post);
    }
}
function native_reset(): void {
    $GLOBALS['native'] = ['locks' => [], 'posts' => [1 => new WP_Post()], 'meta' => [1 => ['owned' => 'current']], 'options' => [],
        'hooks' => [], 'fail_option' => false, 'owned' => [1 => true], 'cached_post' => null];
    $GLOBALS['wpdb'] = new NativeTestDb('101');
}
function is_wp_error(mixed $value): bool { return $value instanceof WP_Error; }
function home_url(string $suffix = ''): string { return 'https://wordpress.example' . $suffix; }
function get_option(string $key, mixed $default = false): mixed { return $GLOBALS['native']['options'][$key] ?? $default; }
function update_option(string $key, mixed $value, bool $autoload = true): bool { unset($autoload); if ($GLOBALS['native']['fail_option']) return false; $GLOBALS['native']['options'][$key] = $value; return true; }
function wp_cache_delete(mixed $id, string $group): bool { unset($id, $group); return true; }
function wp_salt(string $scheme): string { return 'native-isolated-fixture-' . $scheme; }
function clean_post_cache(int $id): void { unset($id); $GLOBALS['native']['cached_post'] = null; }
function get_post(int $id): WP_Post|null { return $GLOBALS['native']['cached_post'] ?? $GLOBALS['native']['posts'][$id] ?? null; }
function wp_is_post_revision(int $id): bool { return $id === 99; }
function wp_is_post_autosave(int $id): bool { return $id === 98; }
function get_permalink(WP_Post $post): string { return home_url('/' . $post->post_name . '/'); }
function get_the_title(WP_Post $post): string { return $post->post_title; }
function get_user_by(string $field, int $id): WP_User|false { unset($field); return $id === 7 ? new WP_User() : false; }
function wp_json_encode(mixed $value): string|false { return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); }
function parse_blocks(string $content): array { return [['blockName' => null, 'innerHTML' => $content]]; }
function serialize_blocks(array $blocks): string { return implode('', array_column($blocks, 'innerHTML')); }
function do_blocks(string $content): string { return $content; }
function wp_kses_post(string $content): string { return preg_replace('/<script\b[^>]*>.*?<\/script>/is', '', $content) ?? ''; }
function wp_strip_all_tags(string $value): string { return strip_tags($value); }
function esc_html(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_attr(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function esc_url(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function add_filter(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { $GLOBALS['native']['hooks'][] = [$hook, $callback, $priority, $args]; }
function add_action(string $hook, callable|array $callback, int $priority = 10, int $args = 1): void { add_filter($hook, $callback, $priority, $args); }

require_once __DIR__ . '/native-preparation-api.php';
require_once __DIR__ . '/../src/NativeLock.php';
require_once __DIR__ . '/../src/NativeWriter.php';
require_once __DIR__ . '/../src/ProtectedJournal.php';
require_once __DIR__ . '/../src/NativeContent.php';
require_once __DIR__ . '/../src/NativeSourceSnapshot.php';
require_once __DIR__ . '/../src/NativeDeletion.php';
require_once __DIR__ . '/../src/TableOfContents.php';
native_reset();
