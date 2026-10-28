<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;
use WP_Post;

/** Native positive journal discovery/requeue, never publication authority or absence-based withdrawal. */
final class CmsSourceRecovery
{
    public const EXCLUSION_META = '_discussionbridge_owner_exclusion';

    private static function storage(): ProtectedJournal { return new ProtectedJournal('cms-to-index:' . home_url('/') . ':' . Settings::site_id()); }
    private static function fresh(): stdClass { return (object) ['entries' => new stdClass(), 'discovery_cursor' => '', 'entry_cursor' => '']; }

    public static function register(int $id, string $option, callable $guard): bool
    {
        if (!$guard() || $option !== (new CmsJournal($id))->option_name()) return false;
        $storage = self::storage(); $index = $storage->read(); if (is_wp_error($index)) return false;
        $index ??= self::fresh();
        if (isset($index->entries->$option)) return $index->entries->$option->native_id === (string) $id && $guard();
        $index->entries->$option = (object) ['native_id' => (string) $id, 'option' => $option];
        return $storage->write($index, $guard);
    }

    /** Existing protected owner control records this fact before removing its enable metadata. */
    public static function exclusion_fact(int $id): array
    {
        $fact = ['native_id' => (string) $id, 'external_id' => 'post:' . Settings::site_id() . ':' . $id,
            'scope' => CmsSourcePublisher::scope($id), 'event_id' => wp_generate_uuid4(), 'reason' => 'policy_excluded'];
        $fact['signature'] = hash_hmac('sha256', (string) wp_json_encode($fact), wp_salt('auth'));
        return $fact;
    }

    public static function valid_exclusion(int $id, mixed $fact): bool
    {
        if (!is_array($fact) || !is_string($fact['signature'] ?? null)) return false;
        $signature = $fact['signature']; unset($fact['signature']);
        return array_keys($fact) === ['native_id', 'external_id', 'scope', 'event_id', 'reason']
            && $fact['native_id'] === (string) $id && $fact['external_id'] === 'post:' . Settings::site_id() . ':' . $id
            && $fact['scope'] === CmsSourcePublisher::scope($id) && wp_is_uuid($fact['event_id']) && $fact['reason'] === 'policy_excluded'
            && hash_equals(hash_hmac('sha256', (string) wp_json_encode($fact), wp_salt('auth')), $signature);
    }

    /** One positive sealed-option discovery and one indexed native observation per existing init invocation. */
    public static function step(callable $guard): bool|WP_Error
    {
        global $wpdb;
        if (!$guard()) return self::error();
        $storage = self::storage(); $index = $storage->read(); if (is_wp_error($index)) return $index;
        $index ??= self::fresh();
        $table = '`' . str_replace('`', '``', $wpdb->options) . '`';
        $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM ' . $table
            . ' WHERE option_name LIKE %s AND BINARY option_name > BINARY %s ORDER BY BINARY option_name ASC LIMIT 1',
            'discussionbridge\_native\_%', $index->discovery_cursor));
        if (!is_array($names) || $wpdb->last_error !== '' || count($names) > 1 || !$guard()) return self::error();
        if ($names === []) $index->discovery_cursor = '';
        else {
            $option = $names[0];
            if (!is_string($option) || strcmp($option, $index->discovery_cursor) <= 0) return self::error();
            $tree = ProtectedJournal::read_existing($option);
            if (is_wp_error($tree)) {
                $index->discovery_issues ??= new stdClass();
                $index->discovery_issues->$option = $tree->get_error_code();
            } elseif ($tree !== null && isset($tree->native_id, $tree->artifacts, $tree->scope)
                && is_string($tree->native_id) && preg_match('/\A[1-9][0-9]*\z/D', $tree->native_id) === 1 && (int) $tree->native_id > 0
                && (string) (int) $tree->native_id === $tree->native_id
                && $option === (new CmsJournal((int) $tree->native_id))->option_name()) {
                $index->entries->$option = (object) ['native_id' => $tree->native_id, 'option' => $option];
            }
            // A partial page/census is never evidence that any native source disappeared.
            $index->discovery_cursor = $option;
        }
        $keys = array_keys(get_object_vars($index->entries)); sort($keys, SORT_STRING);
        $selected = null; foreach ($keys as $key) if (strcmp($key, $index->entry_cursor) > 0) { $selected = $key; break; }
        if ($selected === null && $keys !== []) $selected = $keys[0];
        if (!$storage->write($index, $guard)) return self::error();
        if ($selected === null) return true;
        $entry = $index->entries->$selected; $index->entry_cursor = $selected;
        $journal = new CmsJournal((int) $entry->native_id); $state = $journal->read();
        if (is_wp_error($state)) return $state;
        if ($state !== null) {
            $result = self::observe((int) $entry->native_id, $state, $journal, $guard);
            if (is_wp_error($result)) {
                $entry->last_issue = $result->get_error_code();
            } else unset($entry->last_issue);
        }
        return $storage->write($index, $guard) ? true : self::error();
    }

    private static function observe(int $id, stdClass $state, CmsJournal $journal, callable $guard): bool|WP_Error
    {
        global $wpdb;
        if (!$guard() || $state->native_id !== (string) $id || !isset($state->artifacts) || !CmsArtifacts::matches($state->artifacts)
            || $state->scope !== CmsSourcePublisher::scope($id)) return self::error();
        if (isset($state->withdrawal->response)) return true;
        if (isset($state->withdrawal)) {
            if ($state->withdrawal->confirmed && ($state->withdrawal->accepted !== null
                || !isset($state->withdrawal->terminal) && $state->withdrawal->attempts < Publisher::MAX_AUTOMATIC_ATTEMPTS)) {
                if (!self::schedule($id)) return self::error();
            }
            return true; // Never turn an unconfirmed delete capture into confirmed absence.
        }
        if ($state->receipt !== null && (!isset($state->current_record) || $state->current_record->external_id !== 'post:' . Settings::site_id() . ':' . $id)) return self::error();
        clean_post_cache($id);
        $post = get_post($id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s', (string) $id));
        if ($wpdb->last_error !== '' || !$guard()) return self::error();
        if (!$post instanceof WP_Post || !is_object($row)) {
            $state->recovery_issue = (object) ['code' => 'native_missing_without_delete_capture', 'native_id' => (string) $id];
            $journal->write($state, $guard); return self::error();
        }
        foreach (get_object_vars($row) as $field => $value) if (!property_exists($post, $field) || (string) $post->$field !== (string) $value) return self::error();
        if (get_post_meta($id, Materializer::IMPORTED_META, true) === '1') return self::error();
        if ($state->receipt !== null && (get_post_meta($id, '_discussionbridge_resource_id', true) !== $state->receipt->resource_id
            || get_post_meta($id, '_discussionbridge_topic_id', true) !== $state->receipt->topic_id
            || get_post_meta($id, '_discussionbridge_canonical_url', true) !== $state->current_record->canonical_url)) return self::error();
        $reason = $post->post_status !== 'publish' ? 'source_unpublished'
            : (!in_array($post->post_type, Settings::post_types(), true) ? 'source_ineligible' : null);
        $fact = get_post_meta($id, self::EXCLUSION_META, true);
        if ($reason === null && get_post_meta($id, Publisher::ENABLED_META, true) !== '1' && self::valid_exclusion($id, $fact)) $reason = 'policy_excluded';
        if ($reason !== null) {
            $result = CmsSourceWithdrawal::capture($id, $reason, $post, true, '', $reason === 'policy_excluded' ? $fact : null);
            return is_wp_error($result) ? $result : $result === true;
        }
        if (CmsSourceMove::recoverable($state)) return self::schedule($id);
        if ($state->pending !== null && $state->pending->route === 'V4-05') self::schedule($id);
        // A new/pending published edition is not an authorization to replay02 from a lifecycle recovery scan.
        return true;
    }

    private static function schedule(int $id): bool
    {
        return wp_next_scheduled(Publisher::DELIVERY_HOOK, [$id]) !== false
            || wp_schedule_single_event(time() + 1, Publisher::DELIVERY_HOOK, [$id]) === true;
    }
    private static function error(): WP_Error { return new WP_Error('discussionbridge_cms_to_recovery', 'Preserve the owned native source journal and reconcile its lifecycle observation.'); }
}
