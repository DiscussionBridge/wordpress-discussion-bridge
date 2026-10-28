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
    public const RICH_CONTENT_OPTION = 'discussionbridge_rich_content';
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
        register_setting('discussionbridge', self::RICH_CONTENT_OPTION, [
            'type' => 'string',
            'sanitize_callback' => [self::class, 'sanitize_rich_content'],
            'default' => 'preserve',
        ]);
    }

    public static function sanitize_rich_content(mixed $value): string
    {
        return in_array($value, ['preserve', 'enabled', 'disabled'], true) ? $value : 'preserve';
    }

    public static function rich_content(): string
    {
        return self::sanitize_rich_content(get_option(self::RICH_CONTENT_OPTION, 'preserve'));
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

    /** Receiver text is never a trusted diagnostic or a credential display channel. */
    public static function diagnostic_reason(string $reason): string
    {
        if ($reason === '') {
            return '';
        }
        if (strlen($reason) > 2048) {
            return 'receiver_reason_rejected';
        }
        for ($pass = 0; $pass < 3; $pass++) {
            $decoded = html_entity_decode(rawurldecode($reason), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $reason) {
                break;
            }
            $reason = $decoded;
        }
        $secret = self::connection_secret();
        if ($secret !== '') {
            $reason = str_ireplace([$secret, base64_encode($secret), rtrim(strtr(base64_encode($secret), '+/', '-_'), '=')],
                'credential_redacted', $reason);
        }
        if (strlen($reason) > 128 || preg_match('/\A[a-zA-Z0-9_-]+\z/D', $reason) !== 1) {
            return 'receiver_reason_rejected';
        }
        $reason = sanitize_key($reason);
        // Recheck after normalization so a historical mixed-case diagnostic cannot disclose a key.
        return $secret === '' ? $reason : str_ireplace(sanitize_key($secret), 'credential_redacted', $reason);
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

    public static function topic_url_matches(string $value, int|string $topic_id): bool
    {
        $identity = (string) $topic_id;
        if (preg_match('/\A[1-9][0-9]{0,18}\z/D', $identity) !== 1 || strlen($identity) === 19 && strcmp($identity, '9223372036854775807') > 0) {
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
        $path = (string) ($parts['path'] ?? '');
        if (!self::valid_url_path($path)
            || preg_match('#^/t/(?:[^/]+/)?([1-9][0-9]*)(?:/[1-9][0-9]*)?/?$#', $path, $matches) !== 1) {
            return false;
        }
        return $matches[1] === $identity;
    }

    public static function valid_url_path(string $path): bool
    {
        for ($pass = 0; $pass <= 4; $pass++) {
            if (preg_match('//u', $path) !== 1 || str_contains($path, '//') || str_contains($path, '\\')
                || preg_match('/[\x00-\x1F\x7F]|%(?:2f|5c)|%(?![0-9a-f]{2})/i', $path)
                || array_intersect(explode('/', $path), ['.', '..']) !== []) {
                return false;
            }
            $decoded = rawurldecode($path);
            if ($decoded === $path) {
                return true;
            }
            $path = $decoded;
        }
        return false;
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
        $labels = explode('.', $host);
        if (filter_var($host, FILTER_VALIDATE_IP) || str_ends_with($host, '.') || strlen($host) > 253
            || preg_match('/\A(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+)){0,3}\z/i', $host)
            || array_filter($labels, static fn(string $label): bool => strlen($label) > 63
                || preg_match('/\A[a-z0-9](?:[a-z0-9-]*[a-z0-9])?\z/i', $label) !== 1) !== []) {
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
        if (str_starts_with($submitted, 'gcm1:')) {
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
        add_settings_error(self::LANE_OPTION, 'invalid_lane', 'DiscussionBridge lane is invalid.');
        return '';
    }

    public static function valid_forum_name(mixed $value): bool
    {
        return is_string($value) && strlen($value) <= 200 && preg_match('/[^\p{Z}\x{FEFF}]/u', $value) === 1
            && preg_match('//u', $value) === 1 && preg_match('/\p{Cc}/u', $value) === 0;
    }

    public static function sanitize_comments_mode(mixed $value): string
    {
        $value = (string) $value;
        if (in_array($value, ['none', 'simple', 'full', 'interactive'], true)) {
            return $value;
        }
        add_settings_error(self::COMMENTS_MODE_OPTION, 'invalid_comments_mode', 'DiscussionBridge discussion mode is invalid.');
        return 'none';
    }

    public static function sanitize_service_author(mixed $value): string
    {
        $username = sanitize_user(trim((string) $value), true);
        if ($username === '') {
            return '';
        }
        $user = get_user_by('login', $username);
        if (!$user instanceof \WP_User || !user_can($user, 'publish_posts')) {
            // Readiness is also checked by frontend/cron requests without native admin helpers.
            if (function_exists('add_settings_error')) {
                add_settings_error(self::SERVICE_AUTHOR_OPTION, 'invalid_service_author', 'DiscussionBridge service author must be an existing WordPress user allowed to publish posts.');
            }
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
        return 'gcm1:' . base64_encode($iv . $tag . $ciphertext);
    }

    private static function decrypt_secret(string $encrypted): string
    {
        if (!str_starts_with($encrypted, 'gcm1:') || !function_exists('openssl_decrypt')) {
            return '';
        }
        $payload = base64_decode(substr($encrypted, 5), true);
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
        return self::validated_secret($secret);
    }

    private static function encryption_key(): string
    {
        return hash('sha256', wp_salt('auth') . "\0" . wp_salt('secure_auth'), true);
    }
}
