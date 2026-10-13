<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Presentation
{
    private const META_PREFIX = '_discussionbridge_';
    private const INITIAL_SIMPLE_REPLIES = 5;
    private const MAX_SIMPLE_REPLIES = 50;
    public const COMMENTS_MODE_META = '_discussionbridge_comments_mode';
    public const RICH_CONTENT_META = '_discussionbridge_rich_content';
    private static bool $embed_enqueued = false;
    private static int $embed_sequence = 0;

    public static function native_comments_open(bool $open, int $post_id): bool
    {
        if ($post_id > 0 && (get_post_meta($post_id, Publisher::ENABLED_META, true) === '1'
            || get_post_meta($post_id, Materializer::IMPORTED_META, true) === '1')) {
            return false;
        }
        return $open;
    }

    public static function register_block(): void
    {
        register_block_type(dirname(__DIR__) . '/blocks/from-discourse', [
            'render_callback' => [self::class, 'render_block'],
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public static function render_block(array $attributes): string
    {
        $mode = array_key_exists('commentsMode', $attributes) ? $attributes['commentsMode'] : 'interactive';
        return self::render(
            (string) ($attributes['resourceId'] ?? ''),
            self::valid_mode(is_string($mode) ? $mode : '')
        );
    }

    public static function shortcode(array|string $attributes = []): string
    {
        $attributes = shortcode_atts(
            ['resource_id' => '', 'comments' => 'interactive'],
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
            $post_id = (int) get_queried_object_id();
            if ($post_id > 0 && get_post_meta($post_id, Materializer::IMPORTED_META, true) === '1'
                && self::rich_content_enabled($post_id)) {
                wp_enqueue_script(
                    'discussionbridge-rich-content',
                    plugins_url('assets/discussionbridge-rich-content.js', DISCUSSIONBRIDGE_WORDPRESS_FILE),
                    [],
                    DISCUSSIONBRIDGE_WORDPRESS_VERSION,
                    true
                );
            }
        }
        $mapping = self::current_to_discourse_mapping();
        if ($mapping === null) {
            return;
        }
        $mode = self::current_comments_mode();
        if (!in_array($mode, ['none', 'simple'], true)) {
            self::enqueue_embed($mapping['topic_id'], $mode, self::current_is_source_presentation());
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

        if ($mode === 'simple') {
            $simple = self::render_simple($mapping['topic_id'], $mapping['topic_url']);
            return $simple === '' ? $content : TableOfContents::render($content) . $simple;
        }

        return TableOfContents::render($content) . sprintf(
            '<section class="discussionbridge-discussion discussionbridge-discussion--%s" data-discussionbridge-resource="%s"><div class="discussionbridge-discussion__header"><h2>%s</h2><a href="%s">%s</a></div>%s%s</section>',
            esc_attr($mode),
            esc_attr($mapping['resource_id']),
            esc_html__('Discussion', 'discussionbridge'),
            esc_url($mapping['topic_url']),
            esc_html__('Open in Discourse', 'discussionbridge'),
            self::embed_markup($mapping['topic_id'], $mode, self::current_is_source_presentation()),
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

    private static function current_is_source_presentation(): bool
    {
        return get_post_meta((int) get_queried_object_id(), Materializer::IMPORTED_META, true) === '1';
    }

    private static function render(string $requested_resource_id, string $comments_mode): string
    {
        $resource_id = trim($requested_resource_id);
        if (!wp_is_uuid($resource_id) || !Settings::ready()) {
            return '';
        }

        $cache_key = 'discussionbridge_record_' . hash('sha256', Settings::connection_id() . "\n" . $resource_id);
        $cached = get_transient($cache_key);
        $client = new Client();
        // Fresh authenticated metadata is required even when the body is cached.
        $response = $client->record($resource_id, true);
        if (is_wp_error($response) || !is_array($response['bridge_record'] ?? null)) {
            return '';
        }
        $record = $response['bridge_record'];
        if (($record['resource_id'] ?? null) !== $resource_id || ($record['source']['status'] ?? null) === 'withdrawn'
            || ($record['direction'] ?? null) !== 'from_discourse' || ($record['state'] ?? null) !== 'healthy') {
            delete_transient($cache_key);
            return '';
        }
        $edition = $record['source']['edition'] ?? null;
        $descriptor = $record['source']['content'] ?? null;
        if (is_string($edition) && preg_match('/\A[a-f0-9]{64}\z/D', $edition) === 1
            && is_array($cached) && ($cached['source']['edition'] ?? null) === $edition
            && is_string($cached['content_html'] ?? null) && is_array($descriptor)
            && ($descriptor['bytes'] ?? null) === strlen($cached['content_html'])
            && ($descriptor['sha256'] ?? null) === hash('sha256', $cached['content_html'])) {
            $record['content_html'] = $cached['content_html'];
        } else {
            $record = $client->publication_content($record);
            if (is_wp_error($record) || ($record['source']['status'] ?? null) === 'withdrawn') {
                delete_transient($cache_key);
                return '';
            }
        }
        $ttl = (int) apply_filters('discussionbridge_record_cache_ttl', 300, $resource_id);
        set_transient($cache_key, $record, min(3600, max(30, $ttl)));

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
            if ($comments_mode === 'simple') {
                $discussion = self::render_simple($record['topic_id'], $record['topic_url'], false);
            } else {
                self::enqueue_embed($record['topic_id'], $comments_mode, $comments_mode === 'interactive');
                $discussion = sprintf(
                    '<div class="discussionbridge-discussion__header"><h2>%s</h2><a href="%s">%s</a></div>%s',
                    esc_html__('Discussion', 'discussionbridge'),
                    esc_url($record['topic_url']),
                    esc_html__('Open in Discourse', 'discussionbridge'),
                    self::embed_markup($record['topic_id'], $comments_mode, $comments_mode === 'interactive')
                );
            }
        }

        return sprintf(
            '<section class="discussionbridge-record discussionbridge-record--%s" data-discussionbridge-resource="%s"><div class="discussionbridge-record__content">%s</div>%s%s</section>',
            esc_attr($comments_mode),
            esc_attr($resource_id),
            TableOfContents::render(self::clean_source_presentation(wp_kses_post($record['content_html']))),
            $discussion,
            self::credit()
        );
    }

    private static function credit(): string
    {
        return sprintf(
            '<footer class="discussionbridge-credit" aria-label="%s"><span class="discussionbridge-credit__prefix">%s</span> <a class="discussionbridge-credit__brand" href="%s" rel="nofollow">%s</a></footer>',
            esc_attr__('DiscussionBridge credit', 'discussionbridge'),
            esc_html__('Connected by', 'discussionbridge'),
            esc_url('https://discussionbridge.dev/'),
            esc_html__('DiscussionBridge', 'discussionbridge')
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

    public static function rich_content_enabled(int $post_id): bool
    {
        $local = (string) get_post_meta($post_id, self::RICH_CONTENT_META, true);
        if ($local === 'enabled' || $local === 'disabled') {
            return $local === 'enabled';
        }
        $site = Settings::rich_content();
        // Existing imports without a new choice retain their previously active renderer.
        return $site === 'enabled' || ($site === 'preserve' && $local === '');
    }

    private static function valid_mode(string $value): string
    {
        return Settings::sanitize_comments_mode($value);
    }

    private static function render_simple(int $topic_id, string $topic_url, bool $include_credit = true): string
    {
        if ($topic_id <= 0 || !Settings::topic_url_matches($topic_url, $topic_id)) {
            return '';
        }
        $cache_key = 'discussionbridge_simple_' . hash('sha256', Settings::server_url() . "\n" . $topic_id);
        $topic = get_transient($cache_key);
        if (!is_array($topic)) {
            $topic = (new Client())->public_topic($topic_id);
            if (is_wp_error($topic)) {
                return '';
            }
            set_transient($cache_key, $topic, 60);
        }
        $post_stream = $topic['post_stream'] ?? null;
        $posts = is_array($post_stream) ? ($post_stream['posts'] ?? null) : null;
        $stream = is_array($post_stream) ? ($post_stream['stream'] ?? null) : null;
        if (!is_array($posts) || !is_array($stream)) {
            return '';
        }
        $target_ids = array_slice($stream, 1, self::MAX_SIMPLE_REPLIES);
        foreach ($target_ids as $post_id) {
            if (!is_int($post_id) || $post_id <= 0) {
                return '';
            }
        }
        $posts_by_id = [];
        foreach ($posts as $post) {
            if (is_array($post) && is_int($post['id'] ?? null) && $post['id'] > 0) {
                $posts_by_id[$post['id']] = $post;
            }
        }
        $missing = array_values(array_diff($target_ids, array_keys($posts_by_id)));
        $client = new Client();
        foreach (array_chunk($missing, 20) as $batch) {
            $additional = $client->public_topic_posts($topic_id, $batch);
            if (is_wp_error($additional) || !is_array($additional['post_stream']['posts'] ?? null)) {
                return '';
            }
            foreach ($additional['post_stream']['posts'] as $post) {
                if (!is_array($post) || !is_int($post['id'] ?? null) || $post['id'] <= 0) {
                    return '';
                }
                $posts_by_id[$post['id']] = $post;
            }
        }
        $rendered = [];
        foreach ($target_ids as $post_id) {
            $post = $posts_by_id[$post_id] ?? null;
            if (!is_array($post)
                || !is_int($post['post_number'] ?? null) || $post['post_number'] < 2
                || !is_string($post['username'] ?? null) || trim($post['username']) === '' || strlen($post['username']) > 100
                || !is_string($post['cooked'] ?? null) || !is_string($post['created_at'] ?? null)) {
                return '';
            }
            try {
                $created = new \DateTimeImmutable($post['created_at']);
            } catch (\Throwable) {
                return '';
            }
            $body = wp_kses_post($post['cooked']);
            if (trim($body) === '') {
                continue;
            }
            $name = is_string($post['name'] ?? null) && trim($post['name']) !== '' ? trim($post['name']) : trim($post['username']);
            $avatar = self::simple_avatar($post, $post['username']);
            $post_url = untrailingslashit($topic_url) . '/' . $post['post_number'];
            $rendered[] = '<article class="discussionbridge-simple__reply">' . $avatar
                . '<div class="discussionbridge-simple__content"><header class="discussionbridge-simple__meta"><strong>' . esc_html($name)
                . '</strong><a href="' . esc_url($post_url) . '" rel="nofollow noopener noreferrer"><time datetime="' . esc_attr($created->format(DATE_ATOM)) . '">' . esc_html($created->format('M j, Y'))
                . '</time></a></header><div class="discussionbridge-simple__body">' . $body . '</div></div></article>';
        }
        $replies = $rendered === [] ? '<p class="discussionbridge-simple__empty">' . esc_html__('No comments yet.', 'discussionbridge') . '</p>' : implode('', array_slice($rendered, 0, self::INITIAL_SIMPLE_REPLIES));
        $remaining = array_slice($rendered, self::INITIAL_SIMPLE_REPLIES);
        if ($remaining !== []) {
            $count = count($remaining);
            $replies .= '<details class="discussionbridge-simple__more"><summary><span class="discussionbridge-simple__more-closed">'
                . esc_html(sprintf(_n('Show %d more comment', 'Show %d more comments', $count, 'discussionbridge'), $count))
                . '</span><span class="discussionbridge-simple__more-open">' . esc_html__('Show fewer comments', 'discussionbridge') . '</span></summary>' . implode('', $remaining) . '</details>';
        }
        if (count($stream) - 1 > self::MAX_SIMPLE_REPLIES) {
            $replies .= '<p class="discussionbridge-simple__limit">' . esc_html(sprintf(__('Showing the first %d comments.', 'discussionbridge'), self::MAX_SIMPLE_REPLIES))
                . ' <a href="' . esc_url($topic_url) . '" rel="nofollow noopener noreferrer">' . esc_html__('View the complete discussion at source', 'discussionbridge') . '</a>.</p>';
        }
        wp_enqueue_style('discussionbridge-presentation', plugins_url('assets/discussionbridge.css', DISCUSSIONBRIDGE_WORDPRESS_FILE), [], DISCUSSIONBRIDGE_WORDPRESS_VERSION);
        return '<section class="discussionbridge-simple"><div class="discussionbridge-discussion__header"><h2>' . esc_html__('Comments', 'discussionbridge')
            . '</h2><a href="' . esc_url($topic_url) . '" rel="nofollow noopener noreferrer">' . esc_html__('Open discussion', 'discussionbridge') . '</a></div>'
            . $replies . self::discourse_credit($client) . ($include_credit ? self::credit() : '') . '</section>';
    }

    private static function discourse_credit(Client $client): string
    {
        $cache_key = 'discussionbridge_powered_by_' . hash('sha256', Settings::server_url());
        $cached = get_transient($cache_key);
        if ($cached !== 'enabled' && $cached !== 'disabled') {
            $enabled = $client->public_powered_by_discourse();
            if (is_wp_error($enabled)) {
                return '';
            }
            set_transient($cache_key, $enabled ? 'enabled' : 'disabled', 10 * MINUTE_IN_SECONDS);
        } else {
            $enabled = $cached === 'enabled';
        }
        if (!$enabled) {
            return '';
        }
        $wordmark = file_get_contents(plugin_dir_path(DISCUSSIONBRIDGE_WORDPRESS_FILE) . 'assets/discourse-wordmark.svg');
        if (!is_string($wordmark) || $wordmark === '') {
            return '';
        }
        return '<a class="discussionbridge-powered-by" href="https://www.discourse.org/powered-by" aria-label="Powered by Discourse" rel="nofollow noopener noreferrer"><span>Powered by</span><span class="discussionbridge-powered-by__wordmark">' . $wordmark . '</span></a>';
    }

    /** @param array<string, mixed> $post */
    private static function simple_avatar(array $post, string $username): string
    {
        $template = $post['avatar_template'] ?? null;
        if (is_string($template) && str_starts_with($template, '/') && !str_starts_with($template, '//') && strlen($template) <= 500 && preg_match('/[\x00-\x1f\x7f]/', $template) !== 1) {
            $url = Settings::server_url() . str_replace('{size}', '48', $template);
            return '<span class="discussionbridge-simple__avatar" aria-hidden="true"><img src="' . esc_url($url) . '" alt="" width="48" height="48" loading="lazy"></span>';
        }
        return '<span class="discussionbridge-simple__avatar discussionbridge-simple__avatar--fallback" aria-hidden="true">' . esc_html(strtoupper(substr(trim($username), 0, 1))) . '</span>';
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
            plugins_url('assets/discussionbridge-embed.js', DISCUSSIONBRIDGE_WORDPRESS_FILE),
            [],
            DISCUSSIONBRIDGE_WORDPRESS_VERSION,
            true
        );
        wp_enqueue_script('discussionbridge-discourse-embed');
    }

    /** Native /embed/comments supports one topic per iframe; avoid its global single-target wrapper. */
    private static function embed_markup(int $topic_id, string $mode, bool $source_presentation): string
    {
        self::enqueue_embed($topic_id, $mode, $source_presentation);
        $query = ['topic_id' => $topic_id];
        if ($mode === 'interactive') {
            $query['full_app'] = 'true';
            if ($source_presentation) {
                $query['class_name'] = 'discussion-bridge-source-presentation';
            }
        }
        $src = Settings::server_url() . '/embed/comments?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        return sprintf('<iframe id="discussionbridge-embed-%d" class="discussionbridge-embed" data-discussionbridge-embed="%s" src="%s" title="%s" width="100%%" height="%d" frameborder="0" scrolling="%s" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe>',
            ++self::$embed_sequence, esc_attr($mode), esc_url($src),
            esc_attr(sprintf(__('Discourse discussion %d', 'discussionbridge'), $topic_id)),
            $mode === 'interactive' ? 600 : 150, $mode === 'interactive' ? 'yes' : 'no');
    }
}
