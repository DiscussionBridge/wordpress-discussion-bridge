<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Component-private To source receipt; never a From mapping/lease receipt or shared wire type. */
final class CmsSourceOwnership
{
    public const META = '_discussionbridge_to_source_receipt';
    public const PROFILE = 'wordpress-source-article-1';

    public static function digest(stdClass $receipt): string { return hash('sha256', CmsArtifacts::codec()->semanticIdentity($receipt)); }

    public static function bind(int $id, stdClass $record, stdClass $receipt, stdClass $source): bool
    {
        if (!self::complete($source, $record)) return false;
        $tree = (object) ['profile' => self::PROFILE, 'site_id' => Settings::site_id(), 'native_id' => (string) $id,
            'resource_id' => $receipt->resource_id, 'topic_id' => $receipt->topic_id, 'external_id' => $record->external_id,
            'canonical_url' => $record->canonical_url, 'source_revision' => $receipt->source_revision,
            'source_content_sha256' => $record->source_content_sha256, 'source_content_bytes' => $record->source_content_bytes,
            'snapshot_sha256' => self::digest($source)];
        $value = ['receipt' => $tree, 'signature' => hash_hmac('sha256', CmsArtifacts::codec()->semanticIdentity($tree), wp_salt('auth'))];
        update_post_meta($id, self::META, wp_slash($value)); wp_cache_delete($id, 'post_meta');
        return get_post_meta($id, self::META, true) == $value;
    }

    public static function metadata(int $id): ?array
    {
        wp_cache_delete($id, 'post_meta'); $value = get_post_meta($id, self::META, true);
        if (!is_array($value) || !($value['receipt'] ?? null) instanceof stdClass || !is_string($value['signature'] ?? null)) return null;
        $receipt = $value['receipt'];
        return $receipt->profile === self::PROFILE && $receipt->site_id === Settings::site_id() && $receipt->native_id === (string) $id
            && hash_equals(hash_hmac('sha256', CmsArtifacts::codec()->semanticIdentity($receipt), wp_salt('auth')), $value['signature']) ? $value : null;
    }

    public static function owned(int $id): bool
    {
        // Losing/tampering with a current receipt cannot turn its known owned source into a pass-through.
        if (metadata_exists('post', $id, self::META)) return true;
        $state = (new CmsJournal($id))->read();
        return $state instanceof stdClass && ($state->receipt ?? null) !== null && ($state->current_native_source ?? null) instanceof stdClass
            && is_string($state->current_native_source->prepared_article ?? null);
    }

    public static function complete(stdClass $source, stdClass $record): bool
    {
        return is_string($source->prepared_article ?? null) && ($source->prepared_bytes ?? null) === strlen($source->prepared_article)
            && ($source->prepared_sha256 ?? null) === hash('sha256', $source->prepared_article)
            && $source->prepared_bytes === $record->source_content_bytes && $source->prepared_sha256 === $record->source_content_sha256
            && is_string($source->article ?? null) && is_string($source->article_url ?? null)
            && ($source->native_row ?? null) instanceof stdClass && ($source->semantic_source ?? null) instanceof stdClass;
    }

    /** Observe persisted native state only: never replay rendering callbacks as a proof probe. */
    public static function observe(int $id, stdClass $state, bool $for_update = false): stdClass|WP_Error
    {
        global $wpdb;
        $source = $state->current_native_source ?? null; $record = $state->current_record ?? null;
        $metadata = self::metadata($id);
        if (!$source instanceof stdClass || !$record instanceof stdClass || !self::complete($source, $record) || $metadata === null
            || $metadata['receipt']->snapshot_sha256 !== self::digest($source) || $metadata['receipt']->source_revision !== $state->receipt->source_revision
            || $metadata['receipt']->resource_id !== $state->receipt->resource_id || $metadata['receipt']->topic_id !== $state->receipt->topic_id) return self::failed('complete_receipt_unavailable');
        clean_post_cache($id); $post = get_post($id);
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s' . ($for_update ? ' FOR UPDATE' : ''), (string) $id));
        $terms = $wpdb->get_col($wpdb->prepare('SELECT term_taxonomy_id FROM `' . str_replace('`', '``', $wpdb->term_relationships)
            . '` WHERE object_id = %s ORDER BY term_taxonomy_id ASC' . ($for_update ? ' FOR UPDATE' : ''), (string) $id));
        if (!$post instanceof \WP_Post || !is_object($row) || !is_array($terms) || $wpdb->last_error !== '' || $post->post_status !== 'publish'
            || get_post_meta($id, Publisher::ENABLED_META, true) !== '1' || get_post_meta($id, Materializer::IMPORTED_META, true) === '1'
            || !in_array($post->post_type, Settings::post_types(), true) || get_permalink($post) !== $record->canonical_url) return self::failed('native_changed');
        $columns = get_object_vars($row); ksort($columns, SORT_STRING);
        foreach ($columns as $field => $value) if (!property_exists($post, $field) || (string) $post->$field !== (string) $value) return self::failed('native_changed');
        if ($columns != get_object_vars($source->native_row) || $post->post_content !== $source->article) return self::failed('native_changed');
        return (object) ['profile' => self::PROFILE, 'site_id' => Settings::site_id(), 'home_url' => home_url('/'),
            'native_id' => (string) $id, 'ownership' => $metadata['receipt'], 'native_row' => (object) $columns,
            'terms' => array_map('strval', $terms), 'artifacts' => $state->artifacts];
    }

    private static function failed(string $reason): WP_Error { return new WP_Error('discussionbridge_source_ownership_' . $reason, 'The complete current native To ownership receipt is unavailable or changed. Preserve its source and URL reservations.'); }
}
