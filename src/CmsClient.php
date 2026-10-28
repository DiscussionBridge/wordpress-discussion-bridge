<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use DiscussionBridge\CmsV4\Codec;
use DiscussionBridge\CmsV4\NoContent;
use DiscussionBridge\CmsV4\UnsupportedBehaviorException;
use DiscussionBridge\CmsV4\ValidationException;
use stdClass;
use RuntimeException;
use WP_Error;

require_once __DIR__ . '/CmsArtifacts.php';

/** Strict current typed transport; native directions retain their exact route scopes. */
final class CmsClient
{
    private Codec $codec;
    private array $configuration;

    public function __construct(Codec|null $codec = null, private string $direction = 'from')
    {
        $this->codec = $codec ?? CmsArtifacts::codec();
        $this->configuration = self::configuration();
    }

    public function codec(): Codec { return $this->codec; }

    /** Named route inputs stay typed stdClass/list trees until validation and encoding. */
    public function request(string $route_id, array $options = []): mixed
    {
        if (!in_array($this->direction, ['from', 'to'], true) || !Settings::ready()
            || $this->configuration !== self::configuration()) {
            return new WP_Error('discussionbridge_cms_configuration', 'A configured current CMS connection is required.');
        }
        try {
            $route = $this->codec->validateRoute($route_id, $options);
            if ($this->direction === 'from' && in_array($route_id, ['V4-02', 'V4-05', 'V4-28', 'V4-29', 'V4-30', 'V4-31'], true)
                || $this->direction === 'to' && !in_array($route_id, ['V4-01', 'V4-02', 'V4-03', 'V4-04', 'V4-05', 'V4-28', 'V4-29', 'V4-30', 'V4-31'], true)) {
                return new WP_Error('discussionbridge_cms_direction', 'This route is outside the explicitly selected native direction.');
            }
            $path = $options['path'] ?? new stdClass();
            $path = is_array($path) ? (object) $path : $path;
            $relative = preg_replace_callback('/\{([^}]+)\}/', static fn(array $match): string => rawurlencode($path->{$match[1]}), $route['path']);
            $query = $options['query'] ?? new stdClass();
            $query_string = http_build_query(get_object_vars($query), '', '&', PHP_QUERY_RFC3986);
            $server = $this->configuration['server'];
            $this->codec->validate('Url', $server);
            $origin = parse_url($server);
            if (($origin['path'] ?? '/') !== '/' || isset($origin['query'])) {
                return new WP_Error('discussionbridge_cms_configuration', 'The CMS service must be configured as an HTTPS origin.');
            }
            $url = rtrim($server, '/') . $relative . ($query_string === '' ? '' : '?' . $query_string);
            $this->codec->validate('Url', $url);
            $maximum = $route['response'] === 'ContentChunk' ? 65536 : 262144;
            $args = ['method' => $route['method'], 'timeout' => 10, 'redirection' => 0, 'decompress' => false,
                'limit_response_size' => $maximum + 1,
                'headers' => ['Accept' => 'application/json', 'Accept-Encoding' => 'identity',
                    'X-DiscussionBridge-Connection' => $this->configuration['connection'],
                    'X-DiscussionBridge-Secret' => $this->configuration['secret']]];
            if ($route['method'] === 'POST') {
                $args['body'] = $this->codec->encodeJson($options['body']);
                if (strlen($args['body']) > 65536) throw new ValidationException('body_too_large', '$', 413);
                $args['headers']['Content-Type'] = 'application/json; charset=UTF-8';
            }
        } catch (ValidationException $error) {
            return new WP_Error('discussionbridge_cms_invalid_request', 'The current CMS request is invalid.', ['code' => $error->validationCode, 'path' => $error->path]);
        } catch (UnsupportedBehaviorException) {
            return new WP_Error('discussionbridge_cms_unsupported_validation', 'The configured current CMS identity requires unsupported native validation.');
        } catch (RuntimeException) {
            return new WP_Error('discussionbridge_cms_configuration', 'The current CMS route configuration is unavailable.');
        }
        if ($this->configuration !== self::configuration()) return new WP_Error('discussionbridge_cms_configuration', 'The current connection changed before dispatch; preserve its request.');
        $response = wp_safe_remote_request($url, $args);
        if (is_wp_error($response)) return new WP_Error('discussionbridge_cms_transport', 'Current CMS transport did not return a response.', ['code' => 'transport_timeout']);
        $status = wp_remote_retrieve_response_code($response);
        $raw = wp_remote_retrieve_body($response);
        $encoding = wp_remote_retrieve_header($response, 'content-encoding');
        $media = wp_remote_retrieve_header($response, 'content-type');
        if (!is_string($encoding) || !is_string($media)) return new WP_Error('discussionbridge_cms_invalid_response', 'Current CMS response headers are ambiguous.');
        $encoding = strtolower(trim($encoding)); $media = strtolower(trim($media));
        if ($status >= 300 && $status < 400 || $encoding !== '' && $encoding !== 'identity'
            || strlen($raw) > $maximum || ($status !== 204 && preg_match('/\Aapplication\/json(?:\s*;\s*charset\s*=\s*(?:utf-8|"utf-8"))?\s*\z/iD', $media) !== 1)) {
            return new WP_Error('discussionbridge_cms_invalid_response', 'Current CMS transport returned an invalid response.');
        }
        try {
            $decoded = $this->codec->decodeResponse($route_id, $raw, $status);
        } catch (ValidationException) {
            return new WP_Error('discussionbridge_cms_invalid_response', 'Current CMS transport returned an invalid typed response.');
        } catch (UnsupportedBehaviorException) {
            return new WP_Error('discussionbridge_cms_unsupported_validation', 'Current CMS response requires unsupported native validation.');
        }
        if ($decoded === NoContent::VALUE) return $decoded;
        if ($route_id === 'V4-02' && $decoded->outcome !== 'rejected') {
            $expected_status = match ($decoded->outcome) { 'created' => 201, 'resolved', 'updated' => 200, 'reconciliation_required' => 409, default => 0 };
            if ($expected_status !== $status) return new WP_Error('discussionbridge_cms_invalid_response', 'Current source resolve returned an invalid outcome/status pair.');
        }
        if (!in_array($status, $route['success_http'], true)) {
            if ($route_id === 'V4-02' && $decoded->outcome === 'reconciliation_required') return new WP_Error('discussionbridge_cms_reconciliation', 'The receiver requires source-binding reconciliation.',
                ['status' => $status, 'code' => $decoded->reason, 'retryable' => false, 'retry_after_seconds' => null]);
            $data = ['status' => $status, 'code' => $decoded->code, 'retryable' => $decoded->retryable, 'retry_after_seconds' => $decoded->retry_after_seconds];
            if ($route_id === 'V4-02' && $status === 422 && $decoded->code === 'invalid_field'
                && $decoded->retryable === false && $decoded->retry_after_seconds === null) {
                $data['invalid_field_rejection'] = (object) ['route' => $route_id, 'http_status' => $status, 'error' => $decoded];
            }
            // Only a strictly decoded declared response is a definitive preservation rejection.
            if (in_array($route_id, ['V4-02', 'V4-29'], true) && $status === 422
                && $decoded->code === 'content_preservation_unavailable' && $decoded->retryable === false && $decoded->retry_after_seconds === null) {
                $data['preservation_rejection'] = (object) ['route' => $route_id, 'http_status' => $status, 'error' => $decoded];
            }
            return new WP_Error('discussionbridge_cms_rejected', 'Current CMS did not authorize this request.', $data);
        }
        return $decoded;
    }

    private static function configuration(): array
    {
        return ['server' => Settings::server_url(), 'connection' => Settings::connection_id(), 'secret' => Settings::connection_secret(),
            'site' => Settings::site_id(), 'origin' => home_url('/'), 'lane' => Settings::lane(), 'types' => Settings::post_types()];
    }
}
