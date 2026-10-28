<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;
use WP_Post;

/** Actual native observations only; the exact V4 SDK will form and validate wire records. */
final class NativeSourceSnapshot
{
    public static function capture(int $id, ?\stdClass $retained_source = null): array|WP_Error
    {
        global $wpdb;
        clean_post_cache($id); $post = get_post($id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || wp_is_post_revision($id) || wp_is_post_autosave($id)) return self::error();
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s', (string) $id));
        if (!is_object($row) || $wpdb->last_error !== '') return self::error();
        foreach (get_object_vars($row) as $field => $value) if (!property_exists($post, $field) || (string) $post->$field !== (string) $value) return self::error();
        // Capture the original article and its native article URL BEFORE executing native preparation filters.
        $original = $post->post_content; $article_url = get_permalink($post); $captured = clone $post;
        if (!is_string($article_url) || $article_url === '') return self::error();
        $preparation_url = $retained_source !== null && ($retained_source->article ?? null) === $original
            ? ($retained_source->article_url ?? null) : $article_url;
        if (!is_string($preparation_url) || $preparation_url === '') return self::error();
        $html = NativeContent::complete($captured, $preparation_url); if (is_wp_error($html)) return $html;
        $native_author = get_user_by('id', $captured->post_author);
        $author = $native_author instanceof \WP_User ? ['native_id' => (string) $native_author->ID, 'name' => trim(wp_strip_all_tags((string) $native_author->display_name))] : null;
        $columns = get_object_vars($row); ksort($columns, SORT_STRING);
        $after = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s', (string) $id));
        if (!is_object($after) || $wpdb->last_error !== '' || get_object_vars($after) != get_object_vars($row) || get_permalink($captured) !== $article_url) return self::error();
        return ['post_id' => (string) $id, 'post_type' => $captured->post_type, 'slug' => $captured->post_name, 'url' => $article_url,
            'original_article' => $original, 'source_article_url' => $preparation_url,
            'title' => get_the_title($captured), 'complete_html' => $html, 'complete_bytes' => strlen($html), 'complete_sha256' => hash('sha256', $html),
            'created_at' => self::native_date($captured->post_date_gmt ?? null), 'updated_at' => self::native_date($captured->post_modified_gmt ?? null),
            'author' => $author, 'row' => $columns, 'row_sha256' => hash('sha256', (string) wp_json_encode($columns))];
    }

    public static function native_date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') return null;
        $instant = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $instant !== false && $instant->format('Y-m-d H:i:s') === $value ? $instant->format('Y-m-d\TH:i:s.000\Z') : null;
    }

    private static function error(): WP_Error { return new WP_Error('discussionbridge_native_source', 'The actual published native source is unavailable or changed; preserve its existing binding.'); }
}
