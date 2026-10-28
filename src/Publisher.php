<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Post;

/** Ordinary opted-in native published-source hooks use the one typed publisher and durable writer. */
final class Publisher
{
    public const DELIVERY_HOOK = 'discussionbridge_deliver_post';
    public const ENABLED_META = '_discussionbridge_enabled';
    public const MAX_AUTOMATIC_ATTEMPTS = 3;

    public static function recover(): void
    {
        if (!Settings::ready()) return;
        try { FromSynchronizer::serialized(fn(callable $guard) => CmsSourceRecovery::step($guard)); }
        catch (\Throwable) { /* No native publication or destructive recovery follows an unavailable ledger. */ }
    }

    public static function post_saved(int $id, WP_Post $post, bool $update, ?WP_Post $prior): void
    {
        unset($update, $prior);
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || CmsSourceNativeMove::writing($id) || get_post_meta($id, Materializer::IMPORTED_META, true) === '1') return;
        if ($post->post_status === 'publish' && !in_array($post->post_type, Settings::post_types(), true)) {
            CmsSourceWithdrawal::capture($id, 'source_ineligible', $post); return;
        }
        if ($post->post_status === 'publish' && get_post_meta($id, self::ENABLED_META, true) === '1') self::queue($id, false);
    }

    public static function queue(int $id, bool $manual): void
    {
        $post = get_post($id);
        if (!$post instanceof WP_Post || $post->post_status !== 'publish' || !in_array($post->post_type, Settings::post_types(), true)
            || get_post_meta($id, Materializer::IMPORTED_META, true) === '1' || get_post_meta($id, self::ENABLED_META, true) !== '1'
            || CmsSourceWithdrawal::has_lifecycle($id) !== false) return;
        if ($manual) { update_post_meta($id, '_discussionbridge_attempts', 0); wp_clear_scheduled_hook(self::DELIVERY_HOOK, [$id]); }
        if (!wp_next_scheduled(self::DELIVERY_HOOK, [$id])) {
            update_post_meta($id, '_discussionbridge_status', 'queued');
            wp_schedule_single_event(time() + 1, self::DELIVERY_HOOK, [$id]);
        }
    }

    public static function deliver(int $id): void
    {
        try { $result = FromSynchronizer::serialized(fn(callable $guard) => CmsSourcePublisher::step($id, $guard)); }
        catch (\Throwable) { $result = new \WP_Error('discussionbridge_cms_native_unavailable', 'Current source delivery needs reconciliation.'); }
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $attempts = is_array($data) && isset($data['native_attempts']) ? (int) $data['native_attempts'] : (int) get_post_meta($id, '_discussionbridge_attempts', true);
            $retry = $attempts < self::MAX_AUTOMATIC_ATTEMPTS && ($result->get_error_code() === 'discussionbridge_cms_transport'
                || $result->get_error_code() === 'discussionbridge_native_writer_busy' || is_array($data) && ($data['retryable'] ?? false) === true);
            self::failure($id, is_array($data) ? (string) ($data['code'] ?? $result->get_error_code()) : $result->get_error_code(), $retry,
                is_array($data) ? (int) ($data['retry_after_seconds'] ?? 0) : 0, $attempts);
        } elseif ($result === 'working') self::queue($id, false);
    }

    private static function failure(int $id, string $reason, bool $retry, int $retry_after, int $attempts): void
    {
        if (get_post($id) instanceof WP_Post) {
            update_post_meta($id, '_discussionbridge_status', $retry ? 'retrying' : 'attention');
            update_post_meta($id, '_discussionbridge_last_outcome', 'failed');
            update_post_meta($id, '_discussionbridge_last_reason', Settings::diagnostic_reason($reason));
            update_post_meta($id, '_discussionbridge_last_attempt_at', gmdate('c'));
        }
        if ($retry && !wp_next_scheduled(self::DELIVERY_HOOK, [$id])) {
            $delay = min(3600, max($retry_after, 60 * (2 ** (max(1, $attempts) - 1))));
            wp_schedule_single_event(time() + $delay, self::DELIVERY_HOOK, [$id]);
        }
    }
}
