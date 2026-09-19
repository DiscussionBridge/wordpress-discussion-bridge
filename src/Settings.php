<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Settings
{
    public const SERVER_URL_OPTION = 'discussionbridge_server_url';
    public const CONNECTION_ID_OPTION = 'discussionbridge_connection_id';
    public const CONNECTION_SECRET_OPTION = 'discussionbridge_connection_secret_encrypted';
    public const SITE_ID_OPTION = 'discussionbridge_site_id';
    public const LANE_OPTION = 'discussionbridge_lane';
    public const POST_TYPES_OPTION = 'discussionbridge_post_types';
    public const COMMENTS_MODE_OPTION = 'discussionbridge_comments_mode';
    public const SERVICE_AUTHOR_OPTION = 'discussionbridge_service_author';

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
        register_setting('discussionbridge', self::CONNECTION_SECRET_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_connection_secret'],
            'default' => '',
            'show_in_rest' => false,
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
        register_setting('discussionbridge', self::COMMENTS_MODE_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_comments_mode'],
            'default' => 'interactive',
        ]);
        register_setting('discussionbridge', self::SERVICE_AUTHOR_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_service_author'],
            'default' => '',
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
        if (defined('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE')) {
            return self::secret_from_file((string) constant('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE'));
        }

        return self::decrypt_secret((string) get_option(self::CONNECTION_SECRET_OPTION, ''));
    }

    public static function connection_secret_source(): string
    {
        if (defined('DISCUSSIONBRIDGE_CONNECTION_SECRET')) {
            return self::connection_secret() !== '' ? 'server_constant' : 'missing';
        }
        if (defined('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE')) {
            return self::connection_secret() !== '' ? 'server_file' : 'missing';
        }
        return self::connection_secret() !== '' ? 'wordpress_encrypted' : 'missing';
    }

    private static function secret_from_file(string $path): string
    {
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

    public static function comments_mode(): string
    {
        return self::sanitize_comments_mode(get_option(self::COMMENTS_MODE_OPTION, 'interactive'));
    }

    public static function service_author_username(): string
    {
        $value = defined('DISCUSSIONBRIDGE_SERVICE_AUTHOR')
            ? (string) constant('DISCUSSIONBRIDGE_SERVICE_AUTHOR')
            : (string) get_option(self::SERVICE_AUTHOR_OPTION, '');
        return self::sanitize_service_author($value);
    }

    public static function service_author_id(): int
    {
        $username = self::service_author_username();
        $user = $username !== '' ? get_user_by('login', $username) : false;
        return $user instanceof \WP_User && user_can($user, 'publish_posts') ? (int) $user->ID : 0;
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

    public static function topic_url_matches(string $value, int $topic_id): bool
    {
        if ($topic_id <= 0) {
            return false;
        }
        $parts = wp_parse_url($value);
        $server = wp_parse_url(self::server_url());
        if (!is_array($parts) || !is_array($server)
            || ($parts['scheme'] ?? '') !== 'https'
            || strtolower((string) ($parts['host'] ?? '')) !== strtolower((string) ($server['host'] ?? ''))
            || (int) ($parts['port'] ?? 443) !== (int) ($server['port'] ?? 443)
            || !empty($parts['user']) || !empty($parts['pass'])
            || !empty($parts['query']) || !empty($parts['fragment'])) {
            return false;
        }
        if (preg_match('#^/t/(?:[^/]+/)?([1-9][0-9]*)(?:/[1-9][0-9]*)?/?$#', (string) ($parts['path'] ?? ''), $matches) !== 1) {
            return false;
        }
        return (int) $matches[1] === $topic_id;
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

    public static function sanitize_connection_secret(mixed $value): string
    {
        $current = (string) get_option(self::CONNECTION_SECRET_OPTION, '');
        $submitted = trim((string) $value);
        if ($submitted === '') {
            return $current;
        }
        if (str_starts_with($submitted, 'v1:')) {
            if (self::decrypt_secret($submitted) !== '') {
                return $submitted;
            }
            add_settings_error(
                self::CONNECTION_SECRET_OPTION,
                'invalid_encrypted_connection_secret',
                'DiscussionBridge rejected an invalid encrypted connection-secret value.'
            );
            return $current;
        }
        $secret = self::validated_secret($submitted);
        if ($secret === '') {
            add_settings_error(
                self::CONNECTION_SECRET_OPTION,
                'invalid_connection_secret',
                'Paste only the 43-character DiscussionBridge connection secret.'
            );
            return $current;
        }
        $encrypted = self::encrypt_secret($secret);
        if ($encrypted === '') {
            add_settings_error(
                self::CONNECTION_SECRET_OPTION,
                'connection_secret_encryption_failed',
                'DiscussionBridge could not encrypt the connection secret on this WordPress installation.'
            );
            return $current;
        }
        return $encrypted;
    }

    public static function sanitize_lane(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '' || preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value) === 1) {
            return $value;
        }
        add_settings_error(
            self::LANE_OPTION,
            'invalid_lane',
            'DiscussionBridge advanced category route is invalid.'
        );
        return '';
    }

    public static function sanitize_comments_mode(mixed $value): string
    {
        $value = (string) $value;
        if ($value === 'fullInteractive') {
            return 'interactive';
        }
        if (in_array($value, ['none', 'simple', 'full', 'interactive'], true)) {
            return $value;
        }
        add_settings_error(self::COMMENTS_MODE_OPTION, 'invalid_comments_mode', 'DiscussionBridge discussion mode is invalid.');
        return 'interactive';
    }

    public static function sanitize_service_author(mixed $value): string
    {
        $username = sanitize_user(trim((string) $value), true);
        if ($username === '') {
            return '';
        }
        $user = get_user_by('login', $username);
        if (!$user instanceof \WP_User || !user_can($user, 'publish_posts')) {
            add_settings_error(self::SERVICE_AUTHOR_OPTION, 'invalid_service_author', 'DiscussionBridge service author must be an existing WordPress user allowed to publish posts.');
            return '';
        }
        return $user->user_login;
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
        if (preg_match('/\A[A-Za-z0-9_-]{43}\z/D', $value) !== 1) {
            return '';
        }
        return $value;
    }

    private static function encrypt_secret(string $secret): string
    {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }
        try {
            $iv = random_bytes(12);
        } catch (\Throwable) {
            return '';
        }
        $tag = '';
        $ciphertext = openssl_encrypt(
            $secret,
            'aes-256-gcm',
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '',
            16
        );
        if (!is_string($ciphertext) || strlen($tag) !== 16) {
            return '';
        }
        return 'v1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt_secret(string $encrypted, int $depth = 0): string
    {
        if (!str_starts_with($encrypted, 'v1:') || !function_exists('openssl_decrypt')) {
            return '';
        }
        $payload = base64_decode(substr($encrypted, 3), true);
        if (!is_string($payload) || strlen($payload) < 29) {
            return '';
        }
        $secret = openssl_decrypt(
            substr($payload, 28),
            'aes-256-gcm',
            self::encryption_key(),
            OPENSSL_RAW_DATA,
            substr($payload, 0, 12),
            substr($payload, 12, 16)
        );
        if (!is_string($secret)) {
            return '';
        }
        if ($depth < 1 && str_starts_with($secret, 'v1:')) {
            return self::decrypt_secret($secret, $depth + 1);
        }
        return self::validated_secret($secret);
    }

    private static function encryption_key(): string
    {
        return hash('sha256', wp_salt('auth') . "\0" . wp_salt('secure_auth'), true);
    }
}
