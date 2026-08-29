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
    private function request(string $method, string $path, ?array $payload = null): array|WP_Error
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
        $args = [
            'method' => $method,
            'timeout' => self::TIMEOUT_SECONDS,
            'redirection' => 0,
            'reject_unsafe_urls' => true,
            'limit_response_size' => self::MAX_RESPONSE_BYTES,
            'headers' => [
                'Accept' => 'application/json',
                'X-DiscussionBridge-Connection' => Settings::connection_id(),
                'X-DiscussionBridge-Secret' => Settings::connection_secret(),
            ],
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

