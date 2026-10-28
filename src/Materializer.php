<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

/** Native import identity/query helpers; the single typed coordinator owns all writes. */
final class Materializer
{
    public const IMPORTED_META = '_discussionbridge_imported_from_discourse';

    public static function source_time_query_clauses(array $clauses, \WP_Query $query): array
    {
        if (is_admin() || $query->get('discussionbridge_source_modified') !== true) return $clauses;
        global $wpdb;
        $clauses['join'] = ($clauses['join'] ?? '') . " LEFT JOIN (SELECT post_id, MAX(CAST(meta_value AS DECIMAL(23,9))) AS source_modified FROM {$wpdb->postmeta} WHERE meta_key = '_discussionbridge_source_updated_at_timestamp' GROUP BY post_id) AS discussionbridge_source_time ON discussionbridge_source_time.post_id = {$wpdb->posts}.ID";
        $clauses['orderby'] = "COALESCE(discussionbridge_source_time.source_modified, TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', {$wpdb->posts}.post_modified_gmt)) DESC, {$wpdb->posts}.ID DESC";
        return $clauses;
    }
}
