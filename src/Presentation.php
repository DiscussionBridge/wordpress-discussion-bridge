<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Presentation
{
    private const META_PREFIX = '_discussionbridge_';
    public const COMMENTS_MODE_META = '_discussionbridge_comments_mode';
    private static bool $embed_enqueued = false;

    public static function register_block(): void
    {
        register_block_type(dirname(__DIR__) . '/blocks/from-discourse', [
            'render_callback' => [self::class, 'render_block'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_block(array $attributes): string
    {
        return self::render(
            (string) ($attributes['resourceId'] ?? ''),
            self::valid_mode((string) ($attributes['commentsMode'] ?? 'fullInteractive'))
        );
    }

    public static function shortcode(array|string $attributes = []): string
    {
        $attributes = shortcode_atts(
            ['resource_id' => '', 'comments' => 'fullInteractive'],
            is_array($attributes) ? $attributes : [],
            'discussionbridge_record'
        );
        return self::render((string) $attributes['resource_id'], self::valid_mode((string) $attributes['comments']));
    }

    public static function enqueue_mapped_discussion(): void
    {
        if (!is_admin() && is_singular(Settings::post_types())) {
            wp_enqueue_style(
                'discussionbridge-presentation',
                plugins_url('assets/discussionbridge.css', DISCUSSIONBRIDGE_WORDPRESS_FILE),
                [],
                DISCUSSIONBRIDGE_WORDPRESS_VERSION
            );
        }
        $mapping = self::current_to_discourse_mapping();
        if ($mapping === null) {
            return;
        }
        $mode = self::current_comments_mode();
        if ($mode !== 'none') {
            self::enqueue_embed($mapping['topic_id'], $mode, false);
        }
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
        $mode = self::current_comments_mode();
        if ($mode === 'none') {
            return $content;
        }

        return TableOfContents::render($content) . sprintf(
            '<section class="discussionbridge-discussion" data-discussionbridge-resource="%s"><div class="discussionbridge-discussion__header"><h2>%s</h2><a href="%s">%s</a></div><div id="discourse-comments"></div>%s</section>',
            esc_attr($mapping['resource_id']),
            esc_html__('Discussion', 'discussionbridge'),
            esc_url($mapping['topic_url']),
            esc_html__('Open in Discourse', 'discussionbridge'),
            self::credit()
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

    private static function render(string $requested_resource_id, string $comments_mode): string
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

        $discussion = '';
        if ($comments_mode !== 'none') {
            self::enqueue_embed($record['topic_id'], $comments_mode, $comments_mode === 'fullInteractive');
            $discussion = sprintf(
                '<div class="discussionbridge-discussion__header"><h2>%s</h2><a href="%s">%s</a></div><div id="discourse-comments"></div>',
                esc_html__('Discussion', 'discussionbridge'),
                esc_url($record['topic_url']),
                esc_html__('Open in Discourse', 'discussionbridge')
            );
        }

        return sprintf(
            '<section class="discussionbridge-record" data-discussionbridge-resource="%s"><div class="discussionbridge-record__content">%s</div>%s%s</section>',
            esc_attr($resource_id),
            TableOfContents::render(self::clean_source_presentation(wp_kses_post($record['content_html']))),
            $discussion,
            self::credit()
        );
    }

    private static function credit(): string
    {
        return sprintf(
            '<p class="discussionbridge-credit"><a href="%s" rel="nofollow">%s</a></p>',
            esc_url('https://discussionbridge.dev/'),
            esc_html__('Connected by DiscussionBridge', 'discussionbridge')
        );
    }

    private static function clean_source_presentation(string $html): string
    {
        if (!class_exists(\DOMDocument::class) || trim($html) === '') {
            return str_replace('[discotoc]', '', $html);
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="discussionbridge-source-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return str_replace('[discotoc]', '', $html);
        }

        $xpath = new \DOMXPath($document);
        $nodes = $xpath->query(
            '//*[@id="discussionbridge-source-root"]//p[normalize-space(.)="[discotoc]"]'
            . ' | //*[@id="discussionbridge-source-root"]//*[contains(concat(" ", normalize-space(@class), " "), " lightbox-wrapper ")]'
            . '//*[contains(concat(" ", normalize-space(@class), " "), " meta ")]'
        );
        if ($nodes !== false) {
            foreach (iterator_to_array($nodes) as $node) {
                $node->parentNode?->removeChild($node);
            }
        }

        $root = $document->getElementById('discussionbridge-source-root');
        if (!$root instanceof \DOMElement) {
            return str_replace('[discotoc]', '', $html);
        }
        $content = '';
        foreach ($root->childNodes as $child) {
            $content .= $document->saveHTML($child);
        }
        return $content;
    }

    private static function current_comments_mode(): string
    {
        $post_id = get_queried_object_id();
        $mode = $post_id > 0 ? (string) get_post_meta($post_id, self::COMMENTS_MODE_META, true) : '';
        return $mode === '' ? Settings::comments_mode() : self::valid_mode($mode);
    }

    private static function valid_mode(string $value): string
    {
        return in_array($value, ['none', 'full', 'fullInteractive'], true) ? $value : 'fullInteractive';
    }

    private static function enqueue_embed(int $topic_id, string $mode, bool $source_presentation): void
    {
        if (self::$embed_enqueued || $topic_id <= 0 || $mode === 'none') {
            return;
        }
        self::$embed_enqueued = true;
        wp_enqueue_style(
            'discussionbridge-presentation',
            plugins_url('assets/discussionbridge.css', DISCUSSIONBRIDGE_WORDPRESS_FILE),
            [],
            DISCUSSIONBRIDGE_WORDPRESS_VERSION
        );
        wp_register_script(
            'discussionbridge-discourse-embed',
            Settings::server_url() . '/javascripts/embed.js',
            [],
            null,
            true
        );
        $configuration = [
            'discourseUrl' => Settings::server_url() . '/',
            'topicId' => $topic_id,
        ];
        if ($mode === 'fullInteractive') {
            $configuration['fullApp'] = true;
            $configuration['dynamicHeight'] = false;
            if ($source_presentation) {
                $configuration['className'] = 'discussion-bridge-source-presentation';
            }
        }
        wp_add_inline_script(
            'discussionbridge-discourse-embed',
            'window.DiscourseEmbed=' . wp_json_encode($configuration, JSON_UNESCAPED_SLASHES) . ';',
            'before'
        );
        wp_enqueue_script('discussionbridge-discourse-embed');
    }
}
