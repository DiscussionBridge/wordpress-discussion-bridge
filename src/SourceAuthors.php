<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Post;

final class SourceAuthors
{
    /** @return array{source_authors: list<array{id: string, name: string, profile_url?: string}>, primary_source_author_id: string} */
    public static function for_post(WP_Post $post): array
    {
        $user = get_user_by('id', $post->post_author);
        if (!$user instanceof \WP_User) {
            return [];
        }

        $id = 'wordpress-user:' . Settings::site_id() . ':' . $user->ID;
        $name = trim(wp_strip_all_tags((string) $user->display_name));
        if ($name === '' || strlen($name) > 200) {
            return [];
        }

        $author = ['id' => $id, 'name' => $name];
        $profile_url = get_author_posts_url($user->ID);
        if (is_string($profile_url) && self::same_site_profile_url($profile_url)) {
            $author['profile_url'] = $profile_url;
        }

        return [
            'source_authors' => [$author],
            'primary_source_author_id' => $id,
        ];
    }

    private static function same_site_profile_url(string $value): bool
    {
        $profile = wp_parse_url($value);
        $site = wp_parse_url(home_url('/'));
        return is_array($profile)
            && is_array($site)
            && ($profile['scheme'] ?? '') === 'https'
            && strtolower((string) ($profile['host'] ?? '')) === strtolower((string) ($site['host'] ?? ''))
            && (int) ($profile['port'] ?? 443) === (int) ($site['port'] ?? 443)
            && empty($profile['user'])
            && empty($profile['pass'])
            && empty($profile['query'])
            && empty($profile['fragment'])
            && str_starts_with((string) ($profile['path'] ?? ''), '/');
    }
}
