<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class WpDiscourseGuard
{
    /**
     * Literal WP Discourse 2.6.x option/meta names are isolated here so
     * coexistence remains explicit and regression-testable.
     */
    public static function conflicts(int $post_id): bool
    {
        unset($post_id);
        $connect = get_option('discourse_connect', []);
        if (!is_array($connect)) {
            return false;
        }
        // WP Discourse can be forced to publish by the wpdc_publish_after_save
        // filter even when its native option/meta decision is false. Therefore
        // a fully configured WP Discourse connection is an unconditional
        // collision boundary for this Alpha adapter.
        return trim((string) ($connect['url'] ?? '')) !== ''
            && trim((string) ($connect['api-key'] ?? '')) !== ''
            && trim((string) ($connect['publish-username'] ?? '')) !== '';
    }
}
