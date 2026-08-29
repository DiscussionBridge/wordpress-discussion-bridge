<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Post;

final class Publisher
{
    public const DELIVERY_HOOK = 'discussionbridge_deliver_post';
    public const ENABLED_META = '_discussionbridge_enabled';
    private const MAX_AUTOMATIC_ATTEMPTS = 5;
    private const META_PREFIX = '_discussionbridge_';

    public static function post_saved(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void
    {
        unset($update);
        if ($post->post_status !== 'publish'
            || $post_before?->post_status === 'publish'
            || !in_array($post->post_type, Settings::post_types(), true)
            || get_post_meta($post_id, self::ENABLED_META, true) !== '1'
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)) {
            return;
        }
        self::queue($post_id, false);
    }

    public static function queue(int $post_id, bool $manual): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post
            || $post->post_status !== 'publish'
            || get_post_meta($post_id, self::ENABLED_META, true) !== '1') {
            return;
        }
        if ($manual || !wp_next_scheduled(self::DELIVERY_HOOK, [$post_id])) {
            update_post_meta($post_id, self::META_PREFIX . 'status', 'queued');
            update_post_meta($post_id, self::META_PREFIX . 'correlation_id', 'wp-' . wp_generate_uuid4());
            if ($manual) {
                update_post_meta($post_id, self::META_PREFIX . 'attempts', 0);
            }
            wp_schedule_single_event(time() + 1, self::DELIVERY_HOOK, [$post_id]);
        }
    }

    public static function deliver(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post
            || $post->post_status !== 'publish'
            || get_post_meta($post_id, self::ENABLED_META, true) !== '1'
            || !Settings::ready()) {
            self::record_failure($post_id, 'not_deliverable', false);
            return;
        }
        if (WpDiscourseGuard::conflicts($post_id)) {
            self::record_failure($post_id, 'wp_discourse_conflict', false);
            return;
        }

        $attempts = (int) get_post_meta($post_id, self::META_PREFIX . 'attempts', true) + 1;
        update_post_meta($post_id, self::META_PREFIX . 'attempts', $attempts);
        update_post_meta($post_id, self::META_PREFIX . 'status', 'delivering');

        $canonical_url = get_permalink($post);
        $title = get_the_title($post);
        if (!is_string($canonical_url) || $canonical_url === '' || $title === '') {
            self::record_failure($post_id, 'invalid_published_post', false);
            return;
        }
        $record = [
            'direction' => 'to_discourse',
            'external_id' => 'post:' . Settings::site_id() . ':' . $post_id,
            'canonical_url' => $canonical_url,
            'title' => $title,
            'published' => true,
            'visibility' => 'unlisted',
            'adapter_id' => 'wordpress-discussionbridge',
            'adapter_version' => DISCUSSIONBRIDGE_WORDPRESS_VERSION,
            'correlation_id' => (string) get_post_meta($post_id, self::META_PREFIX . 'correlation_id', true),
        ];
        if (Settings::lane() !== '') {
            $record['lane'] = Settings::lane();
        }

        $result = (new Client())->resolve($record);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $reason = is_array($data) && isset($data['reason']) ? (string) $data['reason'] : $result->get_error_code();
            $retry = $result->get_error_code() === 'discussionbridge_transport_failed' && $attempts < self::MAX_AUTOMATIC_ATTEMPTS;
            self::record_failure($post_id, $reason, $retry);
            return;
        }

        $outcome = isset($result['outcome']) && is_string($result['outcome']) ? $result['outcome'] : '';
        $resource_id = isset($result['resource_id']) && is_string($result['resource_id']) ? $result['resource_id'] : '';
        $topic_id = isset($result['topic_id']) && is_int($result['topic_id']) ? $result['topic_id'] : 0;
        $topic_url = isset($result['topic_url']) && is_string($result['topic_url']) ? $result['topic_url'] : '';
        if (!in_array($outcome, ['created', 'resolved'], true)
            || !wp_is_uuid($resource_id)
            || $topic_id <= 0
            || $topic_url === ''
            || ($result['core_fallback'] ?? null) !== false) {
            self::record_failure($post_id, 'invalid_success_response', false);
            return;
        }

        update_post_meta($post_id, self::META_PREFIX . 'status', 'healthy');
        update_post_meta($post_id, self::META_PREFIX . 'last_outcome', $outcome);
        update_post_meta($post_id, self::META_PREFIX . 'last_reason', (string) ($result['reason'] ?? ''));
        update_post_meta($post_id, self::META_PREFIX . 'resource_id', $resource_id);
        update_post_meta($post_id, self::META_PREFIX . 'topic_id', $topic_id);
        update_post_meta($post_id, self::META_PREFIX . 'topic_url', $topic_url);
        update_post_meta($post_id, self::META_PREFIX . 'last_attempt_at', gmdate('c'));
    }

    private static function record_failure(int $post_id, string $reason, bool $retry): void
    {
        update_post_meta($post_id, self::META_PREFIX . 'status', $retry ? 'retrying' : 'attention');
        update_post_meta($post_id, self::META_PREFIX . 'last_outcome', 'failed');
        update_post_meta($post_id, self::META_PREFIX . 'last_reason', sanitize_key($reason));
        update_post_meta($post_id, self::META_PREFIX . 'last_attempt_at', gmdate('c'));
        if ($retry) {
            $attempts = max(1, (int) get_post_meta($post_id, self::META_PREFIX . 'attempts', true));
            $delay = min(3600, 60 * (2 ** ($attempts - 1)));
            if (!wp_next_scheduled(self::DELIVERY_HOOK, [$post_id])) {
                wp_schedule_single_event(time() + $delay, self::DELIVERY_HOOK, [$post_id]);
            }
        }
    }
}
