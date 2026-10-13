<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Client
{
    public const PUBLICATION_CONTRACT = '0.3.0-alpha.1';
    private const MAX_RESPONSE_BYTES = 65536;
    private const MAX_METADATA_BYTES = 2097152;
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
    public function record(string $resource_id, bool $publication = false): array|WP_Error
    {
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error('discussionbridge_invalid_resource_id', 'The DiscussionBridge resource ID is invalid.');
        }
        return $this->request(
            'GET',
            '/discussion-bridge/v1/bridge-records/' . rawurlencode($resource_id) . '.json'
                . ($publication ? '?publication_contract=' . self::PUBLICATION_CONTRACT : ''),
            null, true, $publication ? self::MAX_METADATA_BYTES : self::MAX_RESPONSE_BYTES,
        );
    }

    /** @return array<string, mixed>|WP_Error */
    public function records(int $page = 1, ?string $snapshot = null, bool $publication = false): array|WP_Error
    {
        if ($page < 1 || $page > 10000) {
            return new WP_Error('discussionbridge_invalid_page', 'The DiscussionBridge record page is invalid.');
        }
        if ($snapshot !== null && ($snapshot === '' || strlen($snapshot) > 8192)) {
            return new WP_Error('discussionbridge_invalid_snapshot', 'The DiscussionBridge record snapshot is invalid.');
        }
        $query = ['page' => $page];
        if ($publication) {
            $query['publication_contract'] = self::PUBLICATION_CONTRACT;
        }
        if ($snapshot !== null) {
            $query['snapshot'] = $snapshot;
        }
        return $this->request('GET', '/discussion-bridge/v1/bridge-records.json?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986), null, true,
            $publication ? self::MAX_METADATA_BYTES : self::MAX_RESPONSE_BYTES);
    }

    /** @return array<string,mixed>|WP_Error */
    public function publication_content(array $record): array|WP_Error
    {
        $selected_identity = self::publication_identity($record);
        $selected_post_id = $record['source']['post_id'] ?? null;
        if ($selected_identity === null) {
            return new WP_Error('discussionbridge_invalid_content_identity', 'The selected source identity is invalid.');
        }
        for ($attempt = 0; $attempt < 3; $attempt++) {
            if (($record['source']['status'] ?? null) === 'withdrawn') {
                return $record;
            }
            $descriptor = $record['source']['content'] ?? null;
            if ($descriptor === null && is_string($record['content_html'] ?? null)) {
                return $record;
            }
            $id = $record['resource_id'] ?? null;
            $edition = $record['source']['edition'] ?? null;
            $bytes = $descriptor['bytes'] ?? null;
            $digest = $descriptor['sha256'] ?? null;
            $expected_path = is_string($id) ? '/discussion-bridge/v1/bridge-records/' . rawurlencode($id) . '/content.json' : '';
            if (!is_string($id) || !wp_is_uuid($id) || !self::valid_digest($edition)
                || !is_array($descriptor) || !is_int($bytes) || $bytes <= 0
                || !self::valid_digest($digest) || ($descriptor['path'] ?? null) !== $expected_path
                || ($descriptor['chunk_bytes'] ?? null) !== 32768) {
                return new WP_Error('discussionbridge_invalid_content_descriptor', 'The source content descriptor is invalid.');
            }
            $offset = 0;
            $html = '';
            $hash = hash_init('sha256');
            $changed = false;
            while ($offset < $bytes) {
                $chunk = $this->request('GET', $expected_path . '?' . http_build_query(['edition' => $edition, 'offset' => $offset], '', '&', PHP_QUERY_RFC3986));
                if (is_wp_error($chunk)) {
                    $data = $chunk->get_error_data();
                    if (is_array($data) && ($data['status'] ?? null) === 409) {
                        $changed = true;
                        break;
                    }
                    return $chunk;
                }
                $encoded = $chunk['chunk_base64'] ?? null;
                $decoded = is_string($encoded) ? base64_decode($encoded, true) : false;
                $length = is_string($decoded) ? strlen($decoded) : 0;
                $next = $offset + $length;
                if (($chunk['resource_id'] ?? null) !== $id || ($chunk['edition'] ?? null) !== $edition
                    || ($chunk['offset'] ?? null) !== $offset || ($chunk['total_bytes'] ?? null) !== $bytes
                    || ($chunk['content_sha256'] ?? null) !== $digest || !is_string($decoded)
                    || base64_encode($decoded) !== $encoded || $length < 1 || $length > 32768 || $next > $bytes
                    || !array_key_exists('next_offset', $chunk)
                    || $chunk['next_offset'] !== ($next === $bytes ? null : $next)) {
                    return new WP_Error('discussionbridge_invalid_content_chunk', 'The source content chunk is inconsistent.');
                }
                hash_update($hash, $decoded);
                $html .= $decoded;
                $offset = $next;
            }
            if ($changed) {
                $fresh = $this->record($id, true);
                if (is_wp_error($fresh)) {
                    return $fresh;
                }
                if (!is_array($fresh['bridge_record'] ?? null)
                    || self::publication_identity($fresh['bridge_record']) !== $selected_identity
                    || (($fresh['bridge_record']['source']['status'] ?? null) !== 'withdrawn'
                        && ($fresh['bridge_record']['source']['post_id'] ?? null) !== $selected_post_id)) {
                    return new WP_Error('discussionbridge_invalid_content_refresh', 'The refreshed source identity is invalid.');
                }
                $record = $fresh['bridge_record'];
                continue;
            }
            if (!hash_equals($digest, hash_final($hash)) || strlen($html) !== $bytes || preg_match('//u', $html) !== 1) {
                return new WP_Error('discussionbridge_invalid_content_digest', 'The complete source content did not verify.');
            }
            $record['content_html'] = $html;
            return $record;
        }
        return new WP_Error('discussionbridge_source_changed', 'The source changed during each bounded retrieval attempt.');
    }

    /** Mutable title/slug/edition/grants cannot replace the selected source or binding. */
    private static function publication_identity(array $record): ?array
    {
        $id = $record['resource_id'] ?? null;
        $topic = $record['topic_id'] ?? null;
        if (($record['direction'] ?? null) !== 'from_discourse' || !is_string($id) || !wp_is_uuid($id)
            || !is_int($topic) || $topic <= 0 || !is_string($record['topic_url'] ?? null)
            || !Settings::topic_url_matches($record['topic_url'], $topic) || !is_array($record['bindings'] ?? null)) {
            return null;
        }
        $source = $record['source'] ?? null;
        if (!is_array($source)) {
            return null;
        }
        $withdrawn = ($source['status'] ?? null) === 'withdrawn';
        if (!$withdrawn && (($source['platform'] ?? null) !== 'discourse' || ($source['origin'] ?? null) !== Settings::server_url()
            || ($source['topic_id'] ?? null) !== $topic || ($source['topic_url'] ?? null) !== $record['topic_url'])) {
            return null;
        }
        $bindings = [];
        foreach ($record['bindings'] as $binding) {
            if (!is_array($binding) || !is_string($binding['role'] ?? null) || !is_string($binding['state'] ?? null)
                || !is_string($binding['canonical_url'] ?? null)) {
                return null;
            }
            $bindings[] = [$binding['role'], $binding['state'], $binding['canonical_url']];
        }
        sort($bindings);
        return [strtolower($id), 'from_discourse', 'discourse', Settings::server_url(), $topic, $bindings];
    }

    private static function valid_digest(mixed $value): bool
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    /** @return array<string, mixed>|WP_Error */
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
    private function request(string $method, string $path, ?array $payload = null, bool $authenticate = true, int $maximum_response_bytes = self::MAX_RESPONSE_BYTES): array|WP_Error
    {
        if (!Settings::ready()) {
            return new WP_Error('discussionbridge_not_configured', 'DiscussionBridge is not configured.');
        }

        $body = null;
        if ($payload !== null) {
            $body = wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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
            'limit_response_size' => $maximum_response_bytes + 1,
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
        if (trim(explode(';', $content_type, 2)[0]) !== 'application/json' || strlen($response_body) > $maximum_response_bytes) {
            return new WP_Error('discussionbridge_invalid_response', 'DiscussionBridge returned an invalid response.');
        }
        $data = json_decode($response_body, true, 64, JSON_BIGINT_AS_STRING);
        if (!is_array($data) || json_last_error() !== JSON_ERROR_NONE) {
            return new WP_Error('discussionbridge_invalid_json', 'DiscussionBridge returned invalid JSON.');
        }

        if ($status >= 200 && $status < 300) {
            return $data;
        }
        $outcome = Settings::diagnostic_reason(isset($data['outcome']) && is_string($data['outcome']) ? $data['outcome'] : 'rejected');
        $reason = Settings::diagnostic_reason(isset($data['reason']) && is_string($data['reason']) ? $data['reason'] : 'request_failed');
        return new WP_Error('discussionbridge_' . sanitize_key($outcome), 'DiscussionBridge did not accept the request.', [
            'status' => $status,
            'outcome' => $outcome,
            'reason' => $reason,
        ]);
    }
}
