<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Settings
{
    public const SERVER_URL_OPTION = 'discussionbridge_server_url';
    public const CONNECTION_ID_OPTION = 'discussionbridge_connection_id';
    public const SITE_ID_OPTION = 'discussionbridge_site_id';
    public const LANE_OPTION = 'discussionbridge_lane';
    public const POST_TYPES_OPTION = 'discussionbridge_post_types';

    public static function register(): void
    {
        register_setting('discussionbridge', self::SERVER_URL_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_server_url'],
            'default' => '',
        ]);
        register_setting('discussionbridge', self::CONNECTION_ID_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_connection_id'],
            'default' => '',
        ]);
        register_setting('discussionbridge', self::LANE_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_lane'],
            'default' => '',
        ]);
        register_setting('discussionbridge', self::POST_TYPES_OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize_post_types'],
            'default' => ['post'],
        ]);
    }

    public static function server_url(): string
    {
        if (defined('DISCUSSIONBRIDGE_SERVER_URL')) {
            return self::sanitize_server_url((string) constant('DISCUSSIONBRIDGE_SERVER_URL'));
        }
        return (string) get_option(self::SERVER_URL_OPTION, '');
    }

    public static function connection_id(): string
    {
        if (defined('DISCUSSIONBRIDGE_CONNECTION_ID')) {
            return self::sanitize_connection_id((string) constant('DISCUSSIONBRIDGE_CONNECTION_ID'));
        }
        return (string) get_option(self::CONNECTION_ID_OPTION, '');
    }

    public static function connection_secret(): string
    {
        if (defined('DISCUSSIONBRIDGE_CONNECTION_SECRET')) {
            return self::validated_secret((string) constant('DISCUSSIONBRIDGE_CONNECTION_SECRET'));
        }
        if (!defined('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE')) {
            return '';
        }
        $path = (string) constant('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE');
        $real = realpath($path);
        $webroot = realpath(ABSPATH);
        if ($real === false || !is_file($real) || !is_readable($real) || filesize($real) > 512) {
            return '';
        }
        if ($webroot !== false && str_starts_with($real, rtrim($webroot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
            return '';
        }
        $secret = file_get_contents($real);
        return is_string($secret) ? self::validated_secret($secret) : '';
    }

    public static function site_id(): string
    {
        $site_id = (string) get_option(self::SITE_ID_OPTION, '');
        if (!wp_is_uuid($site_id)) {
            $site_id = wp_generate_uuid4();
            update_option(self::SITE_ID_OPTION, $site_id, false);
        }
        return $site_id;
    }

    public static function lane(): string
    {
        return (string) get_option(self::LANE_OPTION, '');
    }

    /** @return list<string> */
    public static function post_types(): array
    {
        $value = get_option(self::POST_TYPES_OPTION, ['post']);
        return self::sanitize_post_types($value);
    }

    public static function ready(): bool
    {
        return self::server_url() !== ''
            && preg_match('/^dbc_[a-f0-9]{24}$/', self::connection_id()) === 1
            && strlen(self::connection_secret()) >= 32;
    }

    public static function sanitize_server_url(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }
        $parts = wp_parse_url($value);
        $valid = is_array($parts)
            && ($parts['scheme'] ?? '') === 'https'
            && !empty($parts['host'])
            && empty($parts['user'])
            && empty($parts['pass'])
            && empty($parts['query'])
            && empty($parts['fragment'])
            && (($parts['path'] ?? '') === '' || ($parts['path'] ?? '') === '/');
        if (!$valid) {
            add_settings_error(self::SERVER_URL_OPTION, 'invalid_server_url', 'DiscussionBridge server URL must be an HTTPS origin without a path, query, fragment, or credentials.');
            return '';
        }
        $host = strtolower((string) $parts['host']);
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            add_settings_error(self::SERVER_URL_OPTION, 'invalid_server_host', 'DiscussionBridge server URL must use a DNS hostname.');
            return '';
        }
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return 'https://' . $host . $port;
    }

    public static function sanitize_connection_id(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^dbc_[a-f0-9]{24}$/', $value) === 1) {
            return $value;
        }
        add_settings_error(self::CONNECTION_ID_OPTION, 'invalid_connection_id', 'DiscussionBridge connection ID is invalid.');
        return '';
    }

    public static function sanitize_lane(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || (strlen($value) <= 64 && preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]*$/', $value) === 1)) {
            return $value;
        }
        add_settings_error(self::LANE_OPTION, 'invalid_lane', 'DiscussionBridge lane is invalid.');
        return '';
    }

    /** @return list<string> */
    public static function sanitize_post_types(mixed $value): array
    {
        $values = is_array($value) ? $value : [];
        $allowed = get_post_types(['public' => true], 'names');
        $clean = [];
        foreach ($values as $post_type) {
            $post_type = sanitize_key((string) $post_type);
            if ($post_type !== 'attachment' && isset($allowed[$post_type])) {
                $clean[] = $post_type;
            }
        }
        return array_values(array_unique($clean ?: ['post']));
    }

    private static function validated_secret(string $value): string
    {
        $value = trim($value);
        if (strlen($value) < 32 || strlen($value) > 256 || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            return '';
        }
        return $value;
    }
}
