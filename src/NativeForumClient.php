<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

/** Existing anonymous native Discourse topic/settings integration; no Bridge credentials or alternate protocol. */
final class NativeForumClient
{
    private const MAX_METADATA_BYTES = 2097152;
    private const TIMEOUT_SECONDS = 10;
    public function public_topic(int $topic_id): array|WP_Error
    {
        if ($topic_id <= 0) {
            return new WP_Error('discussionbridge_invalid_topic_id', 'The Discourse topic ID is invalid.');
        }
        return $this->request('GET', '/t/' . $topic_id . '.json', null, false, self::MAX_METADATA_BYTES);
    }

    /** @param list<int> $post_ids
     *  @return array<string, mixed>|WP_Error
     */
    public function public_topic_posts(int $topic_id, array $post_ids): array|WP_Error
    {
        if ($topic_id <= 0 || $post_ids === [] || count($post_ids) > 20) {
            return new WP_Error('discussionbridge_invalid_topic_posts', 'The Discourse topic post request is invalid.');
        }
        foreach ($post_ids as $post_id) {
            if (!is_int($post_id) || $post_id <= 0) {
                return new WP_Error('discussionbridge_invalid_topic_posts', 'The Discourse topic post request is invalid.');
            }
        }
        $query = http_build_query(['post_ids' => array_values(array_unique($post_ids))]);
        return $this->request('GET', '/t/' . $topic_id . '/posts.json?' . $query, null, false, self::MAX_METADATA_BYTES);
    }

    public function public_powered_by_discourse(): bool|WP_Error
    {
        $settings = $this->public_site_settings();
        if (is_wp_error($settings)) {
            return $settings;
        }
        if (!is_bool($settings['enable_powered_by_discourse'] ?? null)) {
            return new WP_Error('discussionbridge_invalid_branding_setting', 'Discourse branding setting is invalid.');
        }
        return $settings['enable_powered_by_discourse'];
    }

    public function public_forum_title(): string|WP_Error
    {
        $settings = $this->public_site_settings();
        if (is_wp_error($settings)) {
            return $settings;
        }
        $title = $settings['title'] ?? null;
        if (!Settings::valid_forum_name($title)) {
            return new WP_Error('discussionbridge_invalid_forum_title', 'The native Discourse forum title is unavailable or invalid.');
        }
        return $title;
    }

    /** @return array<string, mixed>|WP_Error */
    private function public_site_settings(): array|WP_Error
    {
        $response = wp_safe_remote_get(Settings::server_url() . '/', [
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => 512 * 1024,
            'headers' => [
                'Accept' => 'text/html',
                'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/140.0.0.0 Safari/537.36',
            ],
        ]);
        if (is_wp_error($response)) {
            return new WP_Error('discussionbridge_branding_transport_failed', 'Discourse branding request failed.');
        }
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $body = (string) wp_remote_retrieve_body($response);
        if ((int) wp_remote_retrieve_response_code($response) !== 200 || !str_starts_with($content_type, 'text/html') || strlen($body) > 512 * 1024
            || preg_match('/<script[^>]+id=["\']data-preloaded["\'][^>]*>(.*?)<\/script>/is', $body, $match) !== 1) {
            return new WP_Error('discussionbridge_invalid_branding_response', 'Discourse branding response is invalid.');
        }
        $outer = json_decode($match[1], true, 64, JSON_BIGINT_AS_STRING);
        $settings = is_array($outer) && is_string($outer['siteSettings'] ?? null)
            ? json_decode($outer['siteSettings'], true, 64, JSON_BIGINT_AS_STRING)
            : null;
        if (!is_array($settings)) {
            return new WP_Error('discussionbridge_invalid_branding_setting', 'Discourse branding setting is invalid.');
        }
        return $settings;
    }

    private function request(string $method, string $path, mixed $payload = null, bool $authenticate = false, int $maximum = self::MAX_METADATA_BYTES): array|WP_Error
    {
        if ($method !== 'GET' || $payload !== null || $authenticate || Settings::server_url() === '') return new WP_Error('discussionbridge_native_forum_configuration');
        $response = wp_safe_remote_get(Settings::server_url() . $path, ['timeout' => self::TIMEOUT_SECONDS, 'redirection' => 0,
            'decompress' => false, 'limit_response_size' => $maximum + 1, 'cookies' => [],
            'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity']]);
        if (is_wp_error($response)) return new WP_Error('discussionbridge_native_forum_transport');
        $raw = wp_remote_retrieve_body($response); $encoding = wp_remote_retrieve_header($response, 'content-encoding');
        $media = wp_remote_retrieve_header($response, 'content-type');
        if (wp_remote_retrieve_response_code($response) !== 200 || !is_string($encoding) || !is_string($media)
            || $encoding !== '' && strtolower(trim($encoding)) !== 'identity' || strlen($raw) > $maximum
            || strtolower(trim(explode(';', $media, 2)[0])) !== 'application/json') return new WP_Error('discussionbridge_native_forum_response');
        try { CmsArtifacts::codec()->parseJson($raw, $maximum, false); }
        catch (\RuntimeException) { return new WP_Error('discussionbridge_native_forum_response'); }
        $tree = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        return is_array($tree) ? $tree : new WP_Error('discussionbridge_native_forum_response');
    }
}