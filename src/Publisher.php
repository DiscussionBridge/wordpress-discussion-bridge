<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Post;

final class Publisher
{
    public const DELIVERY_HOOK = 'discussionbridge_deliver_post';
    public const ENABLED_META = '_discussionbridge_enabled';
    private const MAX_AUTOMATIC_ATTEMPTS = 3;
    private const META_PREFIX = '_discussionbridge_';

    public static function post_saved(int $post_id, WP_Post $post, bool $update, ?WP_Post $post_before): void
    {
        unset($update, $post_before);
        if ($post->post_status !== 'publish'
            || get_post_meta($post_id, Materializer::IMPORTED_META, true) === '1'
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
            || !in_array($post->post_type, Settings::post_types(), true)
            || get_post_meta($post_id, Materializer::IMPORTED_META, true) === '1'
            || get_post_meta($post_id, self::ENABLED_META, true) !== '1') {
            return;
        }
        if ($manual || !wp_next_scheduled(self::DELIVERY_HOOK, [$post_id])) {
            update_post_meta($post_id, self::META_PREFIX . 'status', 'queued');
            update_post_meta($post_id, self::META_PREFIX . 'correlation_id', 'wp-' . wp_generate_uuid4());
            if ($manual) {
                update_post_meta($post_id, self::META_PREFIX . 'attempts', 0);
                wp_clear_scheduled_hook(self::DELIVERY_HOOK, [$post_id]);
            }
            wp_schedule_single_event(time() + 1, self::DELIVERY_HOOK, [$post_id]);
        }
    }

    public static function deliver(int $post_id): void
    {
        $post = get_post($post_id);
        if (!$post instanceof WP_Post
            || $post->post_status !== 'publish'
            || !in_array($post->post_type, Settings::post_types(), true)
            || get_post_meta($post_id, Materializer::IMPORTED_META, true) === '1'
            || get_post_meta($post_id, self::ENABLED_META, true) !== '1'
            || !Settings::ready()) {
            self::record_failure($post_id, 'not_deliverable', false);
            return;
        }
        if (WpDiscourseGuard::conflicts($post_id)) {
            self::record_failure($post_id, 'wp_discourse_conflict', false);
            return;
        }

        $canonical_url = get_permalink($post);
        $title = get_the_title($post);
        if (!is_string($canonical_url) || $canonical_url === '' || $title === '') {
            self::record_failure($post_id, 'invalid_published_post', false);
            return;
        }
        $bound_url = (string) get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true);
        if ($bound_url !== '' && $bound_url !== $canonical_url) {
            self::record_failure($post_id, 'source_canonical_drift', false);
            return;
        }
        $full_content = PublishedContent::for_post($post);
        if (is_wp_error($full_content)) {
            self::record_failure($post_id, $full_content->get_error_code(), false);
            return;
        }
        $authors = SourceAuthors::for_post($post);
        $created_at = self::native_date($post->post_date_gmt ?? null);
        $updated_at = self::native_date($post->post_modified_gmt ?? null);
        $edition_input = wp_json_encode([$title, $full_content, $authors, $created_at, $updated_at], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($edition_input)) {
            self::record_failure($post_id, 'invalid_source_edition', false);
            return;
        }
        $revision = hash('sha256', $edition_input);
        $previous_revision = (string) get_post_meta($post_id, self::META_PREFIX . 'source_revision', true);
        if ($previous_revision !== '' && preg_match('/\A[a-f0-9]{64}\z/D', $previous_revision) !== 1) {
            self::record_failure($post_id, 'invalid_source_receipt', false);
            return;
        }
        $attempts = get_post_meta($post_id, self::META_PREFIX . 'attempt_revision', true) === $revision
            ? (int) get_post_meta($post_id, self::META_PREFIX . 'attempts', true) + 1 : 1;
        if ($attempts > self::MAX_AUTOMATIC_ATTEMPTS) {
            self::record_failure($post_id, 'retry_limit_reached', false);
            return;
        }
        if (!self::write_meta($post_id, 'attempt_revision', $revision)
            || !self::write_meta($post_id, 'attempts', $attempts)
            || !self::write_meta($post_id, 'status', 'delivering')) {
            self::record_failure($post_id, 'delivery_state_persistence', false);
            return;
        }
        $content_html = PublishedContent::excerpt($full_content, $canonical_url);
        if (is_wp_error($content_html)) {
            self::record_failure($post_id, $content_html->get_error_code(), false);
            return;
        }
        $record = [
            'direction' => 'to_discourse',
            'external_id' => 'post:' . Settings::site_id() . ':' . $post_id,
            'canonical_url' => $canonical_url,
            'title' => $title,
            'content_html' => $content_html,
            'published' => true,
            'visibility' => 'unlisted',
            'adapter_id' => 'wordpress-discussionbridge',
            'adapter_version' => DISCUSSIONBRIDGE_WORDPRESS_VERSION,
            'correlation_id' => (string) get_post_meta($post_id, self::META_PREFIX . 'correlation_id', true),
            'source_publication' => [
                'revision' => $revision,
                'previous_revision' => $previous_revision === '' ? null : $previous_revision,
                'created_at' => $created_at,
                'updated_at' => $updated_at,
                'content_sha256' => hash('sha256', $full_content),
                'content_bytes' => strlen($full_content),
                'excerpted' => $content_html !== $full_content,
            ],
        ];
        $record = array_merge($record, $authors);
        if (Settings::lane() !== '') {
            $record['lane'] = Settings::lane();
        }
        // JSON quotes/backslashes can exceed the envelope even when HTML fits.
        $budget = PublishedContent::MAX_BYTES;
        while (true) {
            $envelope = wp_json_encode(['bridge_record' => $record], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($envelope)) {
                self::record_failure($post_id, 'invalid_source_encoding', false);
                return;
            }
            if (strlen($envelope) <= 65536) {
                break;
            }
            $budget = min($budget - 1024, intdiv($budget, 2));
            $excerpt = PublishedContent::excerpt($full_content, $canonical_url, $budget);
            if (is_wp_error($excerpt)) {
                self::record_failure($post_id, 'request_metadata_too_large', false);
                return;
            }
            $record['content_html'] = $excerpt;
            $record['source_publication']['excerpted'] = true;
        }

        $result = (new Client())->resolve($record);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            $reason = is_array($data) && isset($data['reason']) ? (string) $data['reason'] : $result->get_error_code();
            $transient = $result->get_error_code() === 'discussionbridge_transport_failed'
                || (is_array($data) && in_array($data['status'] ?? null, [408, 429, 500, 502, 503, 504], true));
            $retry = $transient && $attempts < self::MAX_AUTOMATIC_ATTEMPTS;
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
            || !Settings::topic_url_matches($topic_url, $topic_id)
            || ($result['core_fallback'] ?? null) !== false
            || ($result['source_revision'] ?? null) !== $revision
            || !in_array($result['source_publication_outcome'] ?? null, ['created', 'adopted', 'updated', 'unchanged'], true)) {
            self::record_failure($post_id, 'invalid_success_response', false);
            return;
        }

        $old_resource = (string) get_post_meta($post_id, self::META_PREFIX . 'resource_id', true);
        $old_topic = (int) get_post_meta($post_id, self::META_PREFIX . 'topic_id', true);
        if (($old_resource !== '' && $old_resource !== $resource_id) || ($old_topic > 0 && $old_topic !== $topic_id)) {
            self::record_failure($post_id, 'source_identity_drift', false);
            return;
        }
        $current_revision = (string) get_post_meta($post_id, self::META_PREFIX . 'source_revision', true);
        if ($current_revision !== $previous_revision && $current_revision !== $revision) {
            self::record_failure($post_id, 'stale_source_receipt', false);
            return;
        }
        $receipt = [
            'resource_id' => $resource_id, 'topic_id' => $topic_id, 'topic_url' => $topic_url, 'canonical_url' => $canonical_url,
            'source_revision' => $revision, 'source_created_at' => $created_at ?? '',
            'source_updated_at' => $updated_at ?? '', 'source_content_sha256' => hash('sha256', $full_content),
            'source_content_bytes' => strlen($full_content), 'last_outcome' => $outcome,
            'source_publication_outcome' => $result['source_publication_outcome'],
            'last_reason' => Settings::diagnostic_reason((string) ($result['reason'] ?? '')), 'synchronized_at' => gmdate('c'),
            'last_attempt_at' => gmdate('c'), 'attempts' => 0, 'status' => 'healthy',
        ];
        $before = [];
        foreach ($receipt as $key => $value) {
            $before[$key] = [metadata_exists('post', $post_id, self::META_PREFIX . $key), get_post_meta($post_id, self::META_PREFIX . $key, true)];
        }
        foreach ($receipt as $key => $value) {
            if (!self::write_meta($post_id, $key, $value)) {
                foreach ($before as $name => [$exists, $prior]) {
                    if ($exists) {
                        update_post_meta($post_id, self::META_PREFIX . $name, wp_slash($prior));
                    } else {
                        delete_post_meta($post_id, self::META_PREFIX . $name);
                    }
                }
                self::record_failure($post_id, 'source_receipt_persistence', false);
                return;
            }
        }
        $current = get_post($post_id);
        if ($current instanceof WP_Post && $current->post_status === 'publish') {
            $current_body = PublishedContent::for_post($current);
            if (!is_wp_error($current_body)) {
                $current_input = wp_json_encode([get_the_title($current), $current_body, SourceAuthors::for_post($current),
                    self::native_date($current->post_date_gmt ?? null), self::native_date($current->post_modified_gmt ?? null)],
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (is_string($current_input) && hash('sha256', $current_input) !== $revision) {
                    self::write_meta($post_id, 'status', 'queued');
                    if (!wp_next_scheduled(self::DELIVERY_HOOK, [$post_id])) {
                        wp_schedule_single_event(time() + 1, self::DELIVERY_HOOK, [$post_id]);
                    }
                }
            }
        }
    }

    private static function native_date(mixed $value): ?string
    {
        if (!is_string($value) || $value === '' || $value === '0000-00-00 00:00:00') {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date !== false && $date->format('Y-m-d H:i:s') === $value ? $date->format('Y-m-d\TH:i:s\Z') : null;
    }

    private static function write_meta(int $post_id, string $key, mixed $value): bool
    {
        update_post_meta($post_id, self::META_PREFIX . $key, wp_slash($value));
        return metadata_exists('post', $post_id, self::META_PREFIX . $key)
            && (string) get_post_meta($post_id, self::META_PREFIX . $key, true) === (string) $value;
    }

    private static function record_failure(int $post_id, string $reason, bool $retry): void
    {
        update_post_meta($post_id, self::META_PREFIX . 'status', $retry ? 'retrying' : 'attention');
        update_post_meta($post_id, self::META_PREFIX . 'last_outcome', 'failed');
        update_post_meta($post_id, self::META_PREFIX . 'last_reason', Settings::diagnostic_reason($reason));
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
