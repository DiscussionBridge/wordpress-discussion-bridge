<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Client
{
    private const MAX_RESPONSE_BYTES = 65536;
    private const TIMEOUT_SECONDS = 10;

    /** @return array<string, mixed>|WP_Error */
    public function resolve(array $bridge_record): array|WP_Error
    {
        return $this->request(
            'POST',
            '/discussion-bridge/v1/bridge-records/resolve.json',
            ['bridge_record' => $bridge_record]
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function record(string $resource_id): array|WP_Error
    {
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error('discussionbridge_invalid_resource_id', 'The DiscussionBridge resource ID is invalid.');
        }
        return $this->request(
            'GET',
            '/discussion-bridge/v1/bridge-records/' . rawurlencode($resource_id) . '.json'
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function records(int $page = 1, ?string $snapshot = null): array|WP_Error
    {
        if ($page < 1 || $page > 10000) {
            return new WP_Error('discussionbridge_invalid_page', 'The DiscussionBridge record page is invalid.');
        }
        if ($snapshot !== null && ($snapshot === '' || strlen($snapshot) > 8192)) {
            return new WP_Error('discussionbridge_invalid_snapshot', 'The DiscussionBridge record snapshot is invalid.');
        }
        $query = ['page' => $page];
        if ($snapshot !== null) {
            $query['snapshot'] = $snapshot;
        }
        return $this->request('GET', '/discussion-bridge/v1/bridge-records.json?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986));
    }

    /** @return array<string, mixed>|WP_Error */
    public function public_topic(int $topic_id): array|WP_Error
    {
        if ($topic_id <= 0) {
            return new WP_Error('discussionbridge_invalid_topic_id', 'The Discourse topic ID is invalid.');
        }
        return $this->request('GET', '/t/' . $topic_id . '.json', null, false);
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
        return $this->request('GET', '/t/' . $topic_id . '/posts.json?' . $query, null, false);
    }

    public function public_powered_by_discourse(): bool|WP_Error
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
        if (!is_array($settings) || !is_bool($settings['enable_powered_by_discourse'] ?? null)) {
            return new WP_Error('discussionbridge_invalid_branding_setting', 'Discourse branding setting is invalid.');
        }
        return $settings['enable_powered_by_discourse'];
    }

    /** @return array<string, mixed>|WP_Error */
    private function request(string $method, string $path, ?array $payload = null, bool $authenticate = true): array|WP_Error
    {
        if (!Settings::ready()) {
            return new WP_Error('discussionbridge_not_configured', 'DiscussionBridge is not configured.');
        }

        $body = null;
        if ($payload !== null) {
            $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($body) || strlen($body) > 65536) {
                return new WP_Error('discussionbridge_request_too_large', 'DiscussionBridge request is too large.');
            }
        }

        $url = Settings::server_url() . $path;
        $headers = ['Accept' => 'application/json'];
        if ($authenticate) {
            $headers['X-DiscussionBridge-Connection'] = Settings::connection_id();
            $headers['X-DiscussionBridge-Secret'] = Settings::connection_secret();
        }
        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => $headers,
        ];
        if ($body !== null) {
            $args['headers']['Content-Type'] = 'application/json';
            $args['body'] = $body;
        }

        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('discussionbridge_transport_failed', 'DiscussionBridge request failed.');
        }

        $status = (int) wp_remote_retrieve_response_code($response);
        $content_type = strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $response_body = (string) wp_remote_retrieve_body($response);
        if (!str_starts_with($content_type, 'application/json') || strlen($response_body) > self::MAX_RESPONSE_BYTES) {
            return new WP_Error('discussionbridge_invalid_response', 'DiscussionBridge returned an invalid response.');
        }
        $data = json_decode($response_body, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('discussionbridge_invalid_json', 'DiscussionBridge returned invalid JSON.');
        }

        if ($status >= 200 && $status < 300) {
            return $data;
        }
        $outcome = isset($data['outcome']) && is_string($data['outcome']) ? $data['outcome'] : 'rejected';
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : 'request_failed';
        return new WP_Error('discussionbridge_' . sanitize_key($outcome), 'DiscussionBridge did not accept the request.', [
            'status' => $status,
            'outcome' => $outcome,
            'reason' => $reason,
        ]);
    }
}
