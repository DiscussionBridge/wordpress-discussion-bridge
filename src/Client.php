<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Client
{
    private const DEFAULT_RESPONSE_BYTES = 65536;
    private const DETAIL_RESPONSE_BYTES = 384 * 1024;
    private const FEED_RESPONSE_BYTES = 1024 * 1024;
    private const CATALOG_STATUS_RESPONSE_BYTES = 1024 * 1024;
    private const MAX_REQUEST_BYTES = 262144;
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
            '/discussion-bridge/v1/bridge-records/' . rawurlencode($resource_id) . '.json',
            null,
            true,
            self::DETAIL_RESPONSE_BYTES
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
        return $this->request(
            'GET',
            '/discussion-bridge/v1/bridge-records.json?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986),
            null,
            true,
            self::FEED_RESPONSE_BYTES
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function update_platform_catalog(array $catalog, ?string $expected_catalog_revision = null): array|WP_Error
    {
        $payload = ['catalog' => $catalog];
        if ($expected_catalog_revision !== null) {
            $payload['expected_catalog_revision'] = $expected_catalog_revision;
        }
        return $this->request('PUT', '/discussion-bridge/v1/platform-catalog.json', $payload);
    }

    /** @return array<string, mixed>|WP_Error */
    public function platform_catalog_status(): array|WP_Error
    {
        return $this->request(
            'GET',
            '/discussion-bridge/v1/platform-catalog.json',
            null,
            true,
            self::CATALOG_STATUS_RESPONSE_BYTES
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function source_topics(?string $cursor = null): array|WP_Error
    {
        $query = $this->cursor_query($cursor);
        return is_wp_error($query)
            ? $query
            : $this->request('GET', '/discussion-bridge/v1/source-topics.json' . $query, null, true, self::FEED_RESPONSE_BYTES);
    }

    /** @return array<string, mixed>|WP_Error */
    public function source_topic(int $topic_id): array|WP_Error
    {
        if ($topic_id <= 0) {
            return new WP_Error('discussionbridge_invalid_topic_id', 'The Discourse topic ID is invalid.');
        }
        return $this->request(
            'GET',
            '/discussion-bridge/v1/source-topics/' . $topic_id . '.json',
            null,
            true,
            self::DETAIL_RESPONSE_BYTES
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function source_revocations(?string $cursor = null): array|WP_Error
    {
        $query = $this->cursor_query($cursor);
        return is_wp_error($query)
            ? $query
            : $this->request('GET', '/discussion-bridge/v1/source-revocations.json' . $query, null, true, self::FEED_RESPONSE_BYTES);
    }

    /** @return array<string, mixed>|WP_Error */
    public function source_revocation(string $resource_id): array|WP_Error
    {
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error('discussionbridge_invalid_resource_id');
        }
        return $this->request('GET', '/discussion-bridge/v1/source-revocations/' . $resource_id . '.json');
    }

    /** @return array<string, mixed>|WP_Error */
    public function resolve_source_topic(int $topic_id, array $publication): array|WP_Error
    {
        if ($topic_id <= 0) {
            return new WP_Error('discussionbridge_invalid_topic_id', 'The Discourse topic ID is invalid.');
        }
        return $this->request(
            'POST',
            '/discussion-bridge/v1/source-topics/' . $topic_id . '/resolve.json',
            ['publication' => $publication]
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function acknowledge_publication(string $resource_id, array $acknowledgement): array|WP_Error
    {
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error('discussionbridge_invalid_resource_id', 'The DiscussionBridge resource ID is invalid.');
        }
        return $this->request(
            'PUT',
            '/discussion-bridge/v1/bridge-records/' . rawurlencode($resource_id) . '/acknowledgement.json',
            ['acknowledgement' => $acknowledgement]
        );
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
    private function request(
        string $method,
        string $path,
        ?array $payload = null,
        bool $authenticate = true,
        int $maximum_response_bytes = self::DEFAULT_RESPONSE_BYTES
    ): array|WP_Error
    {
        if (!Settings::ready()) {
            return new WP_Error('discussionbridge_not_configured', 'DiscussionBridge is not configured.');
        }

        $body = null;
        if ($payload !== null) {
            $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES);
            if (!is_string($body) || strlen($body) > self::MAX_REQUEST_BYTES) {
                return new WP_Error('discussionbridge_request_too_large', 'DiscussionBridge request is too large.');
            }
        }

        $url = Settings::server_url() . $path;
        $headers = ['Accept' => 'application/json'];
        if ($authenticate) {
            $headers['X-DiscussionBridge-Connection'] = Settings::connection_id();
            $headers['X-DiscussionBridge-Secret'] = Settings::connection_secret();
            $headers['X-DiscussionBridge-Adapter'] = 'wordpress-discussion-bridge';
            $headers['X-DiscussionBridge-Adapter-Version'] = DISCUSSIONBRIDGE_WORDPRESS_VERSION;
        }
        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => $maximum_response_bytes,
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
        if (!str_starts_with($content_type, 'application/json') || strlen($response_body) > $maximum_response_bytes) {
            return new WP_Error('discussionbridge_invalid_response', 'DiscussionBridge returned an invalid response.');
        }
        $data = json_decode($response_body, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('discussionbridge_invalid_json', 'DiscussionBridge returned invalid JSON.');
        }

        if ($status >= 200 && $status < 300) {
            return $data;
        }
        $reason = isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : 'request_failed';
        $safe_reason = sanitize_key($reason);
        $safe_reason = $safe_reason !== '' ? $safe_reason : 'request_failed';
        $outcome = isset($data['outcome']) && is_string($data['outcome']) ? $data['outcome'] : 'rejected';
        return new WP_Error('discussionbridge_' . $safe_reason, 'DiscussionBridge did not accept the request.', [
            'status' => $status,
            'outcome' => $outcome,
            'reason' => $reason,
        ]);
    }

    private function cursor_query(?string $cursor): string|WP_Error
    {
        if ($cursor === null) {
            return '';
        }
        if ($cursor === '' || strlen($cursor) > 8192 || preg_match('/[\x00-\x20\x7f]/', $cursor) === 1) {
            return new WP_Error('discussionbridge_invalid_cursor', 'The DiscussionBridge source cursor is invalid.');
        }
        return '?' . http_build_query(['cursor' => $cursor], '', '&', PHP_QUERY_RFC3986);
    }
}
