<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Native protected storage, not a wire type or publication authority. */
final class ProtectedJournal
{
    private string $option;
    public function __construct(string $context) { $this->option = 'discussionbridge_native_' . substr(hash('sha256', $context), 0, 40); }
    public function option_name(): string { return $this->option; }
    /** Discovery reads only this product's already sealed options, without inventing their context. */
    public static function read_existing(string $option): stdClass|WP_Error|null
    {
        if (preg_match('/\Adiscussionbridge_native_[a-f0-9]{40}\z/D', $option) !== 1) return self::error();
        $reader = new self('discovery'); $reader->option = $option;
        return $reader->read();
    }
    private function key(): string { return hash('sha256', wp_salt('auth') . "\0" . wp_salt('secure_auth'), true); }

    public function read(): stdClass|WP_Error|null
    {
        wp_cache_delete($this->option, 'options'); $value = get_option($this->option, null);
        if ($value === null) return null;
        if (!is_string($value) || !str_starts_with($value, 'gcm1:') || !function_exists('openssl_decrypt')) return self::error();
        $packed = base64_decode(substr($value, 5), true); if ($packed === false || strlen($packed) < 29) return self::error();
        $raw = openssl_decrypt(substr($packed, 28), 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, substr($packed, 0, 12), substr($packed, 12, 16), $this->option);
        if (!is_string($raw)) return self::error();
        try { $tree = json_decode($raw, false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return self::error(); }
        return $tree instanceof stdClass ? $tree : self::error();
    }

    public function write(stdClass $tree, callable $guard): bool
    {
        if (!$guard()) return false;
        try {
            $raw = json_encode($tree, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $iv = random_bytes(12); $tag = '';
            $cipher = openssl_encrypt($raw, 'aes-256-gcm', $this->key(), OPENSSL_RAW_DATA, $iv, $tag, $this->option, 16);
        } catch (\Throwable) { return false; }
        if (!is_string($cipher) || !$guard()) return false;
        $sealed = 'gcm1:' . base64_encode($iv . $tag . $cipher);
        update_option($this->option, $sealed, false); wp_cache_delete($this->option, 'options');
        return $guard() && get_option($this->option, null) === $sealed;
    }

    private static function error(): WP_Error { return new WP_Error('discussionbridge_native_journal', 'Protected native recovery state is unavailable. Preserve it and reconcile before writing.'); }
}
