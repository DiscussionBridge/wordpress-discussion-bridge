<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Plan only native layouts whose canonical URL does not require reserving a native identity. */
final class CmsPermalink
{
    public static function plan(stdClass $source, string $post_type): array|WP_Error
    {
        global $wp_rewrite;
        $slug = sanitize_title($source->title);
        if ($slug === '') return self::unavailable();
        $slug = wp_unique_post_slug($slug, 0, 'publish', $post_type, 0);
        if ($slug === '') return self::unavailable();
        if ($post_type === 'post') $structure = (string) get_option('permalink_structure', '');
        elseif ($post_type === 'page') $structure = (string) $wp_rewrite->get_page_permastruct();
        else $structure = (string) $wp_rewrite->get_extra_permastruct($post_type);
        $token = $post_type === 'post' ? '%postname%' : ($post_type === 'page' ? '%pagename%' : '%' . $post_type . '%');
        if ($structure === '' || !str_contains($structure, $token)) return self::unavailable();
        $parts = explode(' ', current_time('mysql'));
        $date = explode('-', $parts[0]); $clock = explode(':', $parts[1]);
        $replacements = [$token => $slug, '%year%' => $date[0], '%monthnum%' => $date[1], '%day%' => $date[2],
            '%hour%' => $clock[0], '%minute%' => $clock[1], '%second%' => $clock[2]];
        if (str_contains(strtr($structure, array_fill_keys(array_keys($replacements), '')), '%')) return self::unavailable();
        $path = strtr($structure, $replacements);
        if (str_contains($path, '?') || str_contains($path, '#')) return self::unavailable();
        $url = home_url(user_trailingslashit('/' . ltrim($path, '/'), 'single'));
        if (url_to_postid($url) > 0) return self::unavailable();
        return ['slug' => $slug, 'canonical_url' => $url];
    }

    public static function actual_draft(int $post_id): string|WP_Error
    {
        if (!function_exists('get_sample_permalink')) require_once ABSPATH . 'wp-admin/includes/post.php';
        $sample = get_sample_permalink($post_id);
        if (!is_array($sample) || count($sample) !== 2 || !is_string($sample[0]) || !is_string($sample[1])) return self::unavailable();
        if (preg_match('/%(?![a-fA-F0-9]{2})/', str_replace(['%postname%', '%pagename%'], '', $sample[0])) === 1) return self::unavailable();
        $url = str_replace(['%postname%', '%pagename%'], $sample[1], $sample[0]);
        if ($url === '' || preg_match('/%(?![a-fA-F0-9]{2})/', $url) === 1 || parse_url($url, PHP_URL_QUERY) !== null) return self::unavailable();
        return $url;
    }

    private static function unavailable(): WP_Error
    {
        return new WP_Error('discussionbridge_cms_permalink_configuration', 'The configured native permalink cannot be safely planned and confirmed before publication. Reconcile its layout without reserving an unleased post.');
    }
}
