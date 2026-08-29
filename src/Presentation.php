<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Presentation
{
    private const META_PREFIX = '_discussionbridge_';

    public static function register_block(): void
    {
        register_block_type(dirname(__DIR__) . '/blocks/from-discourse', [
            'render_callback' => [self::class, 'render_block'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_block(array $attributes): string
    {
        return self::render((string) ($attributes['resourceId'] ?? ''));
    }

    public static function shortcode(array|string $attributes = []): string
    {
        $attributes = shortcode_atts(['resource_id' => ''], is_array($attributes) ? $attributes : [], 'discussionbridge_record');
        return self::render((string) $attributes['resource_id']);
    }

    public static function enqueue_mapped_discussion(): void
    {
        $mapping = self::current_to_discourse_mapping();
        if ($mapping === null) {
            return;
        }

        $script_url = Settings::server_url() . '/javascripts/embed.js';
        wp_enqueue_style(
            'discussionbridge-presentation',
            plugins_url('assets/discussionbridge.css', DISCUSSIONBRIDGE_WORDPRESS_FILE),
            [],
            DISCUSSIONBRIDGE_WORDPRESS_VERSION
        );
        wp_register_script('discussionbridge-discourse-embed', $script_url, [], null, true);
        wp_add_inline_script(
            'discussionbridge-discourse-embed',
            'window.DiscourseEmbed=' . wp_json_encode([
                'discourseUrl' => Settings::server_url() . '/',
                'topicId' => $mapping['topic_id'],
                'fullApp' => true,
                'dynamicHeight' => true,
            ], JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );
        wp_enqueue_script('discussionbridge-discourse-embed');
    }

    public static function append_mapped_discussion(string $content): string
    {
        if (!in_the_loop() || !is_main_query()) {
            return $content;
        }
        $mapping = self::current_to_discourse_mapping();
        if ($mapping === null) {
            return $content;
        }

        return $content . sprintf(
            '<section class="discussionbridge-discussion" data-discussionbridge-resource="%s"><div class="discussionbridge-discussion__header"><h2>%s</h2><a href="%s">%s</a></div><div id="discourse-comments"></div></section>',
            esc_attr($mapping['resource_id']),
            esc_html__('Discussion', 'discussionbridge'),
            esc_url($mapping['topic_url']),
            esc_html__('Open in Discourse', 'discussionbridge')
        );
    }

    /** @return array{resource_id: string, topic_id: int, topic_url: string}|null */
    private static function current_to_discourse_mapping(): ?array
    {
        if (is_admin() || !is_singular(Settings::post_types()) || !Settings::ready()) {
            return null;
        }
        $post_id = get_queried_object_id();
        if ($post_id <= 0
            || get_post_meta($post_id, self::META_PREFIX . 'status', true) !== 'healthy') {
            return null;
        }

        $resource_id = (string) get_post_meta($post_id, self::META_PREFIX . 'resource_id', true);
        $topic_id = (int) get_post_meta($post_id, self::META_PREFIX . 'topic_id', true);
        $topic_url = (string) get_post_meta($post_id, self::META_PREFIX . 'topic_url', true);
        if (!wp_is_uuid($resource_id) || !Settings::topic_url_matches($topic_url, $topic_id)) {
            return null;
        }

        return [
            'resource_id' => $resource_id,
            'topic_id' => $topic_id,
            'topic_url' => $topic_url,
        ];
    }

    private static function render(string $requested_resource_id): string
    {
        $resource_id = trim($requested_resource_id);
        if (!wp_is_uuid($resource_id) || !Settings::ready()) {
            return '';
        }

        $cache_key = 'discussionbridge_record_' . hash('sha256', Settings::connection_id() . "\n" . $resource_id);
        $record = get_transient($cache_key);
        if (!is_array($record)) {
            $response = (new Client())->record($resource_id);
            if (is_wp_error($response) || !isset($response['bridge_record']) || !is_array($response['bridge_record'])) {
                return '';
            }
            $record = $response['bridge_record'];
            $ttl = (int) apply_filters('discussionbridge_record_cache_ttl', 300, $resource_id);
            set_transient($cache_key, $record, min(3600, max(30, $ttl)));
        }

        if (($record['direction'] ?? null) !== 'from_discourse'
            || ($record['state'] ?? null) !== 'healthy'
            || ($record['resource_id'] ?? null) !== $resource_id
            || !is_string($record['content_html'] ?? null)
            || !is_int($record['topic_id'] ?? null)
            || !is_string($record['topic_url'] ?? null)
            || !Settings::topic_url_matches($record['topic_url'], $record['topic_id'])) {
            return '';
        }

        return sprintf(
            '<section class="discussionbridge-record" data-discussionbridge-resource="%s"><div class="discussionbridge-record__content">%s</div><p class="discussionbridge-record__discussion"><a href="%s">%s</a></p></section>',
            esc_attr($resource_id),
            wp_kses_post($record['content_html']),
            esc_url($record['topic_url']),
            esc_html__('Continue the discussion', 'discussionbridge')
        );
    }
}
