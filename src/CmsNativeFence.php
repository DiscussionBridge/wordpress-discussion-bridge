<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

/** Native editor, REST and worker saves share a persisted-revision compare under the native lock. */
final class CmsNativeFence
{
    public const INPUT = 'discussionbridge_native_revision';
    private static ?array $expected = null;
    private static bool $held = false;
    private static bool $release_lock = false;
    private static bool $rest = false;
    private static bool $external_transaction = false;
    private static ?array $connection = null;
    private static ?object $database = null;
    private static ?array $sql_expectation = null;
    private static ?array $intended_row = null;
    private static ?array $body_expectation = null;
    private static ?array $source_expectation = null;
    private const SAVEPOINT = 'discussionbridge_native_owned';

    public static function lock_name(): string { return 'discussionbridge:' . substr(hash('sha256', home_url('/')), 0, 40); }

    private static function table(string $name): string { return '`' . str_replace('`', '``', $name) . '`'; }

    private static function owned(int $id): bool
    {
        wp_cache_delete($id, 'post_meta');
        return is_array(get_post_meta($id, CmsPresentation::META, true)) || CmsSourceOwnership::owned($id);
    }

    public static function revision(int $id, bool $for_update = false, ?\WP_Post $displayed = null, bool $observe_only = false): string|WP_Error
    {
        global $wpdb;
        $unavailable = static fn(string $reason): WP_Error => $observe_only
            ? new WP_Error('discussionbridge_cms_' . $reason, 'Native state could not be observed.') : self::attention($id, $reason);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table($wpdb->posts) . ' WHERE ID = %s' . ($for_update ? ' FOR UPDATE' : ''), (string) $id));
        if (!is_object($row) || (string) $row->ID !== (string) $id || $wpdb->last_error !== '') return $unavailable('native_revision_unavailable');
        if ($displayed !== null) foreach (get_object_vars($row) as $field => $value) {
            if (!property_exists($displayed, $field) || (string) $displayed->$field !== (string) $value) return $unavailable('stale_native_view');
        }
        wp_cache_delete($id, 'post_meta');
        $state = get_post_meta($id, CmsPresentation::META, true);
        if (!is_array($state)) $state = CmsSourceOwnership::metadata($id);
        if (!is_array($state)) return $unavailable('native_receipt_unavailable');
        unset($state['managed_revision']);
        $terms = $wpdb->get_col($wpdb->prepare('SELECT term_taxonomy_id FROM ' . self::table($wpdb->term_relationships)
            . ' WHERE object_id = %s ORDER BY term_taxonomy_id ASC' . ($for_update ? ' FOR UPDATE' : ''), (string) $id));
        if (!is_array($terms) || $wpdb->last_error !== '') return $unavailable('native_taxonomy_revision_unavailable');
        $columns = get_object_vars($row); ksort($columns, SORT_STRING);
        return hash('sha256', (string) wp_json_encode([$columns, $state, array_map('strval', $terms)], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    public static function token(int $id, ?\WP_Post $displayed = null): string|WP_Error
    {
        $displayed ??= get_post($id);
        if (!$displayed instanceof \WP_Post) return self::attention($id, 'native_revision_unavailable');
        $revision = self::revision($id, false, $displayed);
        return is_wp_error($revision) ? $revision : $revision . '.' . hash_hmac('sha256', $id . "\0" . $revision, wp_salt('auth'));
    }

    private static function expected_revision(int $id, mixed $token): ?string
    {
        if (!is_string($token) || preg_match('/\A([a-f0-9]{64})\.([a-f0-9]{64})\z/D', $token, $match) !== 1
            || !hash_equals(hash_hmac('sha256', $id . "\0" . $match[1], wp_salt('auth')), $match[2])) return null;
        return $match[1];
    }

    public static function transactional_tables(): bool
    {
        global $wpdb;
        $names = [$wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->term_relationships, $wpdb->term_taxonomy];
        foreach ($names as $name) {
            $engine = $wpdb->get_var($wpdb->prepare('SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s', $name));
            if (!is_string($engine) || strtolower($engine) !== 'innodb' || $wpdb->last_error !== '') return false;
        }
        return true;
    }

    public static function begin(bool $outer_transaction = false): bool
    {
        global $wpdb;
        if (self::$held) return true;
        if (!self::transactional_tables()) return false;
        $owns = (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name())) === '1';
        if (!$owns && (string) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', self::lock_name())) !== '1') return false;
        self::$release_lock = !$owns;
        if (!$outer_transaction && $wpdb->query('START TRANSACTION') === false) {
            if (self::$release_lock) $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
            self::$release_lock = false; return false;
        }
        self::$held = true; self::$external_transaction = $outer_transaction;
        self::$database = $wpdb;
        self::$connection = [spl_object_id($wpdb), $wpdb->posts, $wpdb->postmeta, $wpdb->options, $wpdb->term_relationships,
            (string) $wpdb->get_var('SELECT CONNECTION_ID()'), $wpdb->term_taxonomy];
        if (preg_match('/\A[1-9][0-9]*\z/D', self::$connection[5]) !== 1) { self::finish(false); return false; }
        if ($wpdb->query('SAVEPOINT ' . self::SAVEPOINT) === false) { self::finish(false); return false; }
        return true;
    }

    public static function current_connection(): bool
    {
        global $wpdb;
        return self::$held && self::$connection !== null && self::$connection === [spl_object_id($wpdb), $wpdb->posts, $wpdb->postmeta,
            $wpdb->options, $wpdb->term_relationships, (string) $wpdb->get_var('SELECT CONNECTION_ID()'), $wpdb->term_taxonomy]
            && (string) $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name())) === '1';
    }

    public static function commit_transaction(): bool
    {
        global $wpdb;
        return self::current_connection() && $wpdb->query('RELEASE SAVEPOINT ' . self::SAVEPOINT) !== false
            && $wpdb->query('COMMIT') !== false && self::current_connection();
    }

    /** Prove the original transaction still owns its savepoint before another protected write. */
    public static function storage_alive(): bool
    {
        global $wpdb;
        return self::current_connection() && $wpdb->query('RELEASE SAVEPOINT ' . self::SAVEPOINT) !== false
            && $wpdb->query('SAVEPOINT ' . self::SAVEPOINT) !== false && self::current_connection();
    }

    public static function rollback_transaction(): bool
    {
        global $wpdb;
        if (!self::current_connection() || $wpdb->query('ROLLBACK TO SAVEPOINT ' . self::SAVEPOINT) === false) return false;
        return $wpdb->query('ROLLBACK') !== false && self::current_connection();
    }

    public static function finish(bool $commit): bool
    {
        global $wpdb;
        $ok = true;
        if (self::$held && !self::$external_transaction) $ok = $commit ? self::commit_transaction() : self::rollback_transaction();
        $native_id = self::$expected[0] ?? self::$intended_row['id'] ?? null;
        if (self::$held && self::$release_lock && self::$database !== null && self::$connection !== null) {
            // Release only the original physical connection's lock, never a replacement wpdb writer.
            $database = self::$database;
            if ((string) $database->get_var('SELECT CONNECTION_ID()') === self::$connection[5]
                && (string) $database->get_var($database->prepare('SELECT IS_USED_LOCK(%s) = CONNECTION_ID()', self::lock_name())) === '1')
                $database->get_var($database->prepare('SELECT RELEASE_LOCK(%s)', self::lock_name()));
        }
        self::$held = false; self::$release_lock = false; self::$expected = null; self::$rest = false; self::$external_transaction = false;
        self::$connection = null; self::$database = null; self::$sql_expectation = null; self::$intended_row = null;
        if ((!$commit || !$ok) && is_int($native_id) && $native_id > 0) clean_post_cache($native_id);
        return $ok;
    }

    public static function with_expected(int $id, string $token, callable $operation): mixed
    {
        $prior = self::$expected; self::$expected = [$id, $token];
        try { return $operation(); }
        finally { self::$expected = $prior; }
    }

    /** Scope the complete body to this owned update or durable native creation operation only. */
    public static function with_body(int $id, ?string $creation_operation, string $body, callable $operation): mixed
    {
        $prior = self::$body_expectation;
        self::$body_expectation = ['id' => $id, 'operation' => $creation_operation, 'body' => $body];
        try { return $operation(); }
        finally { self::$body_expectation = $prior; }
    }

    public static function reject_unversioned(bool $empty, array $postarr): bool
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if ($id <= 0 || !self::owned($id)) return $empty;
        $token = self::$expected !== null && self::$expected[0] === $id ? self::$expected[1]
            : ($_POST[self::INPUT] ?? null);
        $expected = self::expected_revision($id, $token);
        if ($expected === null || !self::begin()) { self::attention($id, 'unversioned_or_busy_native_save'); return true; }
        $actual = self::revision($id, true);
        if (is_wp_error($actual) || !hash_equals($expected, $actual)) {
            if (!self::$external_transaction) self::finish(false); self::attention($id, 'stale_native_revision'); return true;
        }
        self::$expected = [$id, $token];
        return $empty;
    }

    public static function before_update(int $id): void
    {
        if (!self::owned($id) && (self::$expected === null || self::$expected[0] !== $id)) return;
        $expected = self::$expected !== null && self::$expected[0] === $id ? self::expected_revision($id, self::$expected[1]) : null;
        $actual = self::$held ? self::revision($id, true) : null;
        if ($expected === null || !is_string($actual) || !hash_equals($expected, $actual) || !self::current_connection()) {
            if (!self::$external_transaction) self::finish(false); self::attention($id, 'stale_native_revision');
            throw new \RuntimeException('The owned CMS post changed before native SQL. Reconcile the save.');
        }
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table($wpdb->posts) . ' WHERE ID = %s FOR UPDATE', (string) $id));
        $terms = $wpdb->get_col($wpdb->prepare('SELECT term_taxonomy_id FROM ' . self::table($wpdb->term_relationships) . ' WHERE object_id = %s ORDER BY term_taxonomy_id ASC FOR UPDATE', (string) $id));
        if (!is_object($row) || !is_array($terms) || $wpdb->last_error !== '') throw new \RuntimeException('The native SQL revision is unavailable.');
        $key = is_array(get_post_meta($id, CmsPresentation::META, true)) ? CmsPresentation::META : CmsSourceOwnership::META;
        self::$sql_expectation = ['id' => $id, 'row' => get_object_vars($row), 'receipt_key' => $key, 'receipt' => maybe_serialize(get_post_meta($id, $key, true)), 'terms' => array_map('strval', $terms)];
    }

    /** Conditional native UPDATE survives a driver reconnect retry; changed persisted state cannot be overwritten. */
    public static function sql_guard(string $query): string
    {
        global $wpdb;
        if (!self::$held || self::$connection === null) return $query;
        $tables = array_map([self::class, 'table'], array_slice(self::$connection, 1, 4));
        $tables[] = self::table(self::$connection[6]);
        $table_pattern = '(?:' . implode('|', array_map(static fn(string $table): string => '`?' . preg_quote(trim($table, '`'), '/') . '`?', $tables)) . ')';
        if (preg_match('/\A(?:INSERT(?:\s+IGNORE)?\s+INTO|UPDATE|DELETE\s+FROM|REPLACE\s+INTO)\s+(`?[^\s(]+`?)/i', $query, $target) !== 1) return $query;
        $target_table = self::table(trim($target[1], '`'));
        if (!in_array($target_table, $tables, true)) return $query;
        if (!self::storage_alive()) return '';
        $connection_id = self::$connection[5];
        if (preg_match('/\A[0-9]+\z/D', $connection_id) !== 1) return '';
        // This condition remains inside the SQL when wpdb retries after reconnecting.
        $connection_condition = 'CONNECTION_ID() = ' . $connection_id . ' AND ' . $wpdb->prepare('IS_USED_LOCK(%s)', self::lock_name()) . ' = ' . $connection_id;
        $expected = self::$sql_expectation;
        $conditions = [];
        $post_table = preg_quote($tables[0], '/');
        if ($expected !== null && preg_match('/\AUPDATE\s+' . $post_table . '\s+SET\s.+\sWHERE\s+`ID`\s*=\s*(?:\'([0-9]+)\'|([0-9]+))\s*\z/isD', $query, $match) === 1
            && (int) ($match[1] !== '' ? $match[1] : $match[2]) === $expected['id']) {
            foreach ($expected['row'] as $field => $value) $conditions[] = $value === null ? self::table($field) . ' IS NULL'
                : $wpdb->prepare('BINARY ' . self::table($field) . ' = BINARY %s', (string) $value);
            $conditions[] = $wpdb->prepare('EXISTS (SELECT 1 FROM ' . self::table($wpdb->postmeta)
            . ' db_cms_meta WHERE db_cms_meta.post_id = %s AND BINARY db_cms_meta.meta_key = BINARY %s AND BINARY db_cms_meta.meta_value = BINARY %s)',
            (string) $expected['id'], $expected['receipt_key'], $expected['receipt']);
            $conditions[] = $wpdb->prepare('(SELECT COUNT(*) FROM ' . self::table($wpdb->term_relationships) . ' db_cms_terms WHERE db_cms_terms.object_id = %s) = ', (string) $expected['id']) . count($expected['terms']);
            foreach ($expected['terms'] as $term) $conditions[] = $wpdb->prepare('EXISTS (SELECT 1 FROM ' . self::table($wpdb->term_relationships)
            . ' db_cms_term WHERE db_cms_term.object_id = %s AND db_cms_term.term_taxonomy_id = %s)', (string) $expected['id'], $term);
        }
        $conditions[] = $connection_condition;
        if (preg_match('/\A((?:UPDATE\s+' . $table_pattern . '\s+SET\s.+|DELETE\s+FROM\s+' . $table_pattern . '))\sWHERE\s+(.+)\z/isD', $query, $parts) === 1)
            return $parts[1] . ' WHERE (' . $parts[2] . ') AND (' . implode(' AND ', $conditions) . ')';
        // Core wpdb insert and native add_option use a single VALUES row; other forms stop safely.
        $literal = <<<'SQL'
(?:NULL|[-+]?[0-9]+(?:\.[0-9]+)?|'(?:\\.|''|[^'\\])*')
SQL;
        if (preg_match('/\A(INSERT(?:\s+IGNORE)?\s+INTO\s+' . $table_pattern . '\s*\([a-zA-Z0-9_`\s,]+\))\s+VALUES\s*\((' . $literal . '(?:\s*,\s*' . $literal . ')*)\)(\s+ON\s+DUPLICATE\s+KEY\s+UPDATE\s+.+)?\z/isD', $query, $parts) === 1)
            return $parts[1] . ' SELECT ' . $parts[2] . ' WHERE (' . $connection_condition . ')' . ($parts[3] ?? '');
        // Native sorted taxonomy assignments are the one supported multi-row core insert.
        $relationships = '`?' . preg_quote(trim($tables[3], '`'), '/') . '`?';
        if (preg_match('/\A(INSERT\s+INTO\s+' . $relationships . '\s*\(object_id,\s*term_taxonomy_id,\s*term_order\))\s+VALUES\s+((?:\([0-9]+,\s*[0-9]+,\s*[0-9]+\),?)+)\s+ON DUPLICATE KEY UPDATE term_order = VALUES\(term_order\)\z/D', $query, $parts) === 1) {
            preg_match_all('/\(([0-9]+,\s*[0-9]+,\s*[0-9]+)\)/', $parts[2], $rows);
            return $parts[1] . ' ' . implode(' UNION ALL ', array_map(static fn(string $row): string => 'SELECT ' . $row . ' WHERE (' . $connection_condition . ')', $rows[1]))
                . ' ON DUPLICATE KEY UPDATE term_order = VALUES(term_order)';
        }
        return '';
    }

    public static function capture_data(array $data, array $postarr, array $unsanitized_postarr = []): array
    {
        $id = (int) ($postarr['ID'] ?? 0);
        if (self::$source_expectation !== null && self::$source_expectation['id'] === $id) {
            $final = wp_unslash($data); $before = self::$source_expectation['row'];
            foreach ($final as $field => $value) {
                if (in_array($field, ['post_modified', 'post_modified_gmt'], true)) continue;
                $wanted = $field === 'post_name' ? self::$source_expectation['slug'] : ($before[$field] ?? null);
                if ($wanted === null || (string) $value !== (string) $wanted) throw new \RuntimeException('The native source URL operation changed a protected field before SQL.');
            }
        }
        $expected = self::$body_expectation;
        $original = wp_unslash($unsanitized_postarr);
        $creation = $original['meta_input']['_discussionbridge_cms_creation_operation'] ?? null;
        if ($expected !== null && (($expected['id'] > 0 && $expected['id'] === $id)
            || ($expected['id'] === 0 && $id === 0 && is_string($creation) && $creation === $expected['operation']))) {
            $prepared = wp_unslash($data['post_content'] ?? null);
            if (!is_string($prepared) || $prepared !== $expected['body']) {
                throw new CmsNativeBodyMismatch(['code' => 'native_final_body_changed', 'native_id' => $id > 0 ? (string) $id : null,
                    'expected_bytes' => strlen($expected['body']), 'expected_sha256' => hash('sha256', $expected['body']),
                    'prepared_bytes' => is_string($prepared) ? strlen($prepared) : null,
                    'prepared_sha256' => is_string($prepared) ? hash('sha256', $prepared) : null,
                    'function_loss' => is_string($prepared) ? NativeContent::function_loss($expected['body'], $prepared) : null,
                    'data_loss' => is_string($prepared) ? NativeContent::data_loss($expected['body'], $prepared) : null]);
            }
        }
        if (self::$held && self::$expected !== null && self::$expected[0] === $id) self::$intended_row = ['id' => $id, 'data' => wp_unslash($data)];
        return $data;
    }

    public static function after_insert(int $id = 0): void
    {
        if (!self::$held) return;
        if (!self::storage_alive()) {
            if (!self::$external_transaction) self::finish(false);
            self::attention($id, 'native_transaction_unknown');
            throw new \RuntimeException('The owned native save lost its transaction witness.');
        }
        if (self::$intended_row !== null && self::$intended_row['id'] === $id) {
            global $wpdb;
            $actual = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::table($wpdb->posts) . ' WHERE ID = %s', (string) $id));
            $matches = is_object($actual) && self::current_connection();
            foreach (self::$intended_row['data'] as $key => $value) if (!is_object($actual) || !property_exists($actual, $key) || (string) $actual->$key !== (string) $value) $matches = false;
            if (!$matches) {
                if (!self::$external_transaction) self::finish(false);
                self::attention($id, 'native_sql_not_committed');
                throw new \RuntimeException('The owned native save did not persist its expected row.');
            }
        }
        if (!self::$rest && !self::$external_transaction && !self::finish(true)) throw new \RuntimeException('The native owned save could not confirm its transaction.');
    }
    public static function cancel(): void { self::finish(false); }

    public static function with_source_fields(int $id, array $row, string $slug, callable $operation): mixed
    {
        $prior = self::$source_expectation; self::$source_expectation = ['id' => $id, 'row' => $row, 'slug' => $slug];
        try { return $operation(); } finally { self::$source_expectation = $prior; }
    }

    public static function register(): void
    {
        add_filter('wp_insert_post_empty_content', [self::class, 'reject_unversioned'], PHP_INT_MAX, 2);
        add_action('pre_post_update', [self::class, 'before_update'], PHP_INT_MAX, 1);
        add_filter('query', [self::class, 'sql_guard'], PHP_INT_MAX);
        add_filter('wp_insert_post_data', [self::class, 'capture_data'], PHP_INT_MAX, 3);
        add_action('wp_after_insert_post', [self::class, 'after_insert'], PHP_INT_MAX);
        add_action('shutdown', [self::class, 'cancel'], PHP_INT_MAX);
        add_action('add_meta_boxes', [self::class, 'add_editor_box']);
        add_action('admin_enqueue_scripts', [self::class, 'enqueue_editor']);
        add_action('admin_notices', [self::class, 'notice']);
        add_action('rest_api_init', [self::class, 'register_rest']);
    }

    public static function add_editor_box(): void
    {
        foreach (Settings::post_types() as $type) add_meta_box('discussionbridge-cms-revision', 'DiscussionBridge source revision', [self::class, 'editor_box'], $type, 'side');
    }

    public static function editor_box(\WP_Post $post): void
    {
        if (!self::owned($post->ID)) return;
        $token = self::token($post->ID, $post);
        echo '<p>A stale editor save is rejected. Reload the current native source revision before saving.</p>';
        if (is_string($token)) echo '<input type="hidden" name="' . self::INPUT . '" value="' . esc_attr($token) . '">';
    }

    public static function enqueue_editor(string $hook): void
    {
        global $post;
        if (!in_array($hook, ['post.php', 'post-new.php'], true) || !$post instanceof \WP_Post || !self::owned($post->ID)) return;
        $token = self::token($post->ID, $post); $type = get_post_type_object($post->post_type);
        if (!is_string($token) || !$type || !$type->show_in_rest) return;
        wp_enqueue_script('discussionbridge-cms-editor', plugins_url('resources/js/cms-native-editor.js', DISCUSSIONBRIDGE_WORDPRESS_FILE), ['wp-api-fetch'], DISCUSSIONBRIDGE_WORDPRESS_VERSION, true);
        wp_add_inline_script('discussionbridge-cms-editor', 'window.DiscussionBridgeCmsEditor=' . wp_json_encode([
            'path' => '/' . ($type->rest_namespace ?? 'wp/v2') . '/' . ($type->rest_base ?: $post->post_type) . '/' . $post->ID,
            'revision' => $token]), 'before');
    }

    public static function register_rest(): void
    {
        foreach (Settings::post_types() as $type) {
            register_rest_field($type, self::INPUT, ['get_callback' => static function (array $object): ?string {
                $id = (int) $object['id']; if (!self::owned($id)) return null;
                $token = self::token($id); return is_string($token) ? $token : null;
            }, 'schema' => ['type' => ['string', 'null'], 'context' => ['edit'], 'readonly' => false]]);
            add_filter('rest_pre_insert_' . $type, [self::class, 'rest_prepare'], PHP_INT_MAX, 2);
        }
        add_filter('rest_request_after_callbacks', [self::class, 'rest_finished'], PHP_INT_MAX, 3);
    }

    public static function rest_prepare(mixed $prepared, \WP_REST_Request $request): mixed
    {
        if (is_wp_error($prepared)) return $prepared;
        $id = (int) $request->get_param('id');
        if ($id <= 0 || !self::owned($id)) return $prepared;
        $token = $request->get_param(self::INPUT);
        if (self::expected_revision($id, $token) === null) return self::attention($id, 'unversioned_native_rest_save');
        self::$expected = [$id, $token]; self::$rest = true;
        if (self::reject_unversioned(false, ['ID' => $id])) return self::attention($id, 'stale_native_revision');
        return $prepared;
    }

    public static function rest_finished(mixed $response): mixed
    {
        if (self::$rest && !self::finish(!is_wp_error($response) && (!is_object($response) || !method_exists($response, 'get_status') || $response->get_status() < 400))) return new WP_Error('discussionbridge_cms_native_revision', 'The native owned save needs transaction reconciliation.', ['status' => 409]);
        return $response;
    }

    public static function notice(): void
    {
        $id = (int) ($_GET['post'] ?? 0);
        if ($id > 0 && get_option('discussionbridge_cms_native_attention_' . $id)) echo '<div class="notice notice-error"><p>The DiscussionBridge source post was not saved from a stale or unversioned request. Reload and reconcile the local/source revision before saving.</p></div>';
    }

    private static function attention(int $id, string $reason): WP_Error
    {
        if ($id > 0) update_option('discussionbridge_cms_native_attention_' . $id, $reason, false);
        return new WP_Error('discussionbridge_cms_native_revision', 'The owned CMS post requires its current native revision. Reload and reconcile before saving.', ['status' => 409]);
    }
}
