<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Same-post native slug operation; no routing/configuration rewrite or replacement publication. */
final class CmsSourceNativeMove
{
    private static ?int $writing = null;
    public static function writing(int $id): bool { return self::$writing === $id; }
    public static function plan(int $id, string $new_url, stdClass $before): stdClass|WP_Error
    {
        if (!function_exists('get_sample_permalink') && is_file(ABSPATH . 'wp-admin/includes/post.php')) require_once ABSPATH . 'wp-admin/includes/post.php';
        if (!function_exists('get_sample_permalink') || !function_exists('wp_unique_post_slug')) return self::failed('permalink_api');
        $parts = parse_url($new_url); $row = $before->native_row;
        if (!is_array($parts) || isset($parts['query'], $parts['fragment']) || isset($parts['query']) || isset($parts['fragment'])
            || CmsCoordinator::origin($new_url) !== CmsCoordinator::origin(get_permalink($id))) return self::failed('permalink_layout');
        $path = trim($parts['path'] ?? '', '/'); $slug = sanitize_title(rawurldecode(basename($path)));
        if ($slug === '' || $slug === $row->post_name) return self::failed('permalink_slug');
        $unique = wp_unique_post_slug($slug, $id, $row->post_status, $row->post_type, (int) ($row->post_parent ?? 0));
        $sample = get_sample_permalink($id, null, $slug);
        if ($unique !== $slug || !is_array($sample) || count($sample) < 2 || $sample[1] !== $slug
            || !str_contains($sample[0], '%postname%') && !str_contains($sample[0], '%pagename%')
            || str_replace(['%postname%', '%pagename%'], $slug, $sample[0]) !== $new_url) return self::failed('permalink_layout');
        $owner = url_to_postid($new_url); if ($owner !== 0 && $owner !== $id) return self::failed('permalink_collision');
        return (object) ['native_id' => (string) $id, 'old_url' => get_permalink($id), 'new_url' => $new_url, 'slug' => $slug, 'before' => $before];
    }

    public static function apply(int $id, stdClass $state, callable $save, callable $guard): bool|WP_Error
    {
        global $wpdb;
        $move = $state->source_move; $plan = $move->native_plan;
        if ($move->stage !== 'native_commit_pending' || !$guard() || !CmsNativeFence::transactional_tables()) return self::failed('storage');
        $actual = CmsSourceOwnership::observe($id, $state);
        if (is_wp_error($actual) || CmsSourceOwnership::digest($actual) !== CmsSourceOwnership::digest($plan->before)) return self::failed('changed');
        $replanned = self::plan($id, $plan->new_url, $actual);
        if (is_wp_error($replanned) || CmsSourceOwnership::digest($replanned) !== CmsSourceOwnership::digest($plan)) return self::failed('permalink_changed');
        $committed = false; $rollback = false; $failure = null;
        try {
            if ($wpdb->query('START TRANSACTION') === false || !CmsNativeFence::begin(true)) throw new \RuntimeException('transaction');
            $locked = CmsSourceOwnership::observe($id, $state, true);
            if (is_wp_error($locked) || CmsSourceOwnership::digest($locked) !== CmsSourceOwnership::digest($actual)) throw new \RuntimeException('changed');
            $token = CmsNativeFence::token($id); if (!is_string($token) || !$guard()) throw new \RuntimeException('version');
            $row = get_object_vars($actual->native_row);
            self::$writing = $id;
            $saved = CmsNativeFence::with_source_fields($id, $row, $plan->slug,
                fn() => CmsNativeFence::with_body($id, null, $row['post_content'],
                    fn() => CmsNativeFence::with_expected($id, $token,
                        fn() => wp_update_post(wp_slash(['ID' => $id, 'post_name' => $plan->slug]), true))));
            if ($saved !== $id || !$guard() || !CmsNativeFence::storage_alive()) throw new \RuntimeException('write');
            $after = self::row($id); if (is_wp_error($after)) throw new \RuntimeException('row');
            foreach ($row as $field => $value) {
                if (in_array($field, ['post_modified', 'post_modified_gmt'], true)) continue;
                if (!property_exists($after, $field) || (string) $after->$field !== (string) ($field === 'post_name' ? $plan->slug : $value)) throw new \RuntimeException('protected_field');
            }
            $terms = $wpdb->get_col($wpdb->prepare('SELECT term_taxonomy_id FROM `' . str_replace('`', '``', $wpdb->term_relationships) . '` WHERE object_id = %s ORDER BY term_taxonomy_id ASC FOR UPDATE', (string) $id));
            clean_post_cache($id);
            if (!is_array($terms) || array_map('strval', $terms) !== $actual->terms || get_permalink($id) !== $plan->new_url || !CmsNativeFence::storage_alive()) throw new \RuntimeException('permalink');
            $move->after_row = $after;
            $move->ancestry = (object) ['site_id' => Settings::site_id(), 'native_id' => (string) $id, 'move_id' => $move->receipt->move_id,
                'predecessor' => $state->receipt->source_revision, 'new_url' => $plan->new_url, 'after_row_sha256' => CmsSourceOwnership::digest($after)];
            $source = clone $state->current_native_source; $source->native_row = $after;
            $columns = get_object_vars($after); ksort($columns, SORT_STRING); $source->native_row_sha256 = hash('sha256', (string) wp_json_encode($columns));
            $source->semantic_source = clone $source->semantic_source;
            $source->semantic_source->source_updated_at = NativeSourceSnapshot::native_date($after->post_modified_gmt ?? null);
            $move->after_source = $source;
            $move->new_source_revision = CmsSourceEdition::revision(new CmsClient(null, 'to'), $source->semantic_source, $move->ancestry);
            $move->native_receipt = (object) ['profile' => CmsSourceOwnership::PROFILE, 'before' => $actual, 'after_row' => $after,
                'terms' => $actual->terms, 'ancestry' => $move->ancestry, 'new_source_revision' => $move->new_source_revision,
                'move_id' => $move->receipt->move_id, 'proof_nonce' => $move->receipt->proof_nonce,
                'prepared_sha256' => $source->prepared_sha256, 'prepared_bytes' => $source->prepared_bytes];
            $move->native_operation = 'applied'; $move->stage = 'native_applied';
            if (!$save() || !$guard() || !CmsNativeFence::commit_transaction()) throw new \RuntimeException('commit');
            $committed = true;
        } catch (\Throwable) { $failure = 'native_commit'; }
        finally {
            self::$writing = null;
            if (!$committed) $rollback = CmsNativeFence::rollback_transaction();
            CmsNativeFence::finish($committed); clean_post_cache($id); wp_cache_delete((new CmsJournal($id))->option_name(), 'options');
        }
        if (!$committed) return self::failed($failure ?? 'native_commit', ['rollback_proven' => $rollback, 'connection_retained' => $guard()]);
        return true;
    }

    public static function row(int $id): stdClass|WP_Error
    {
        global $wpdb; clean_post_cache($id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s', (string) $id));
        return $row instanceof stdClass && $wpdb->last_error === '' ? $row : self::failed('row');
    }

    private static function failed(string $reason, array $data = []): WP_Error { return new WP_Error('discussionbridge_source_native_move_' . $reason, 'The same-post native URL move cannot be established. Preserve both URL reservations and exact native operation.', $data); }
}
