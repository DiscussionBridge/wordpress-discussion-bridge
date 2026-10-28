<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Post;

/** Versioned owned-fragment boundary around actual native rendered content, not a source digest marker. */
final class CmsPresentation
{
    public const META = '_discussionbridge_cms_state';
    public const PROFILE = 'wordpress-rendered-fragment-1';

    public static function wrap(string $content): string
    {
        if (is_admin() || !is_singular(Settings::post_types()) || !in_the_loop() || !is_main_query()) return $content;
        $id = get_queried_object_id(); $state = get_post_meta($id, self::META, true);
        if (!is_array($state) || ($state['receipt']['native_id'] ?? '') !== (string) $id || ($state['action'] ?? '') !== 'publish_revision') return $content;
        return self::envelope($content, $state);
    }

    public static function envelope(string $content, array $state): string
    {
        return '<div data-discussionbridge-cms="' . self::PROFILE . '" data-native-id="' . esc_attr($state['receipt']['native_id'])
            . '" data-resource-id="' . esc_attr($state['receipt']['resource_id']) . '" data-source-revision="' . esc_attr($state['receipt']['source_revision'])
            . '" data-presentation-mode="' . esc_attr($state['mode']) . '">' . $content . '</div>';
    }

    /** Each source author remains provenance; no source identity is mapped into a native user. */
    public static function provenance(stdClass $connection, stdClass $source): string
    {
        $authors = [];
        foreach ($source->source_authors as $author) $authors[] = '<span data-source-author-id="' . esc_attr($author->id) . '">' . esc_html($author->name) . '</span>';
        $dates = '';
        foreach (['source_created_at' => 'Published', 'source_updated_at' => 'Updated'] as $field => $label) {
            if ($source->$field !== null) $dates .= '<time datetime="' . esc_attr($source->$field) . '">' . esc_html($label . ': ' . $source->$field) . '</time>';
        }
        return '<aside class="discussionbridge-source-provenance"><a href="' . esc_url($source->topic_url) . '">'
            . esc_html('Source: ' . $connection->forum_name) . '</a>' . implode(', ', $authors) . $dates . '</aside>';
    }

    public static function expected(WP_Post $native, array $state): string
    {
        // Native content filters execute against the actual persisted post. Discussion uses its existing renderer.
        global $post;
        $prior = $post ?? null; $post = $native;
        $discussion_removed = remove_filter('the_content', [Presentation::class, 'append_mapped_discussion']);
        $wrapper_removed = remove_filter('the_content', [self::class, 'wrap'], PHP_INT_MAX);
        try {
            setup_postdata($native);
            $rendered = apply_filters('the_content', $native->post_content);
            $rendered = Presentation::append_for_mapping($rendered, ['resource_id' => $state['receipt']['resource_id'],
                'topic_id' => (int) $state['topic_id'], 'topic_url' => $state['topic_url']], $state['mode'], true);
            return self::envelope($rendered, $state);
        } finally {
            $post = $prior;
            if ($prior instanceof WP_Post) setup_postdata($prior);
            if ($discussion_removed) add_filter('the_content', [Presentation::class, 'append_mapped_discussion']);
            if ($wrapper_removed) add_filter('the_content', [self::class, 'wrap'], PHP_INT_MAX);
        }
    }
}
