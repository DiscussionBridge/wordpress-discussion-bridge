<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;
use WP_Post;

final class PublishedContent
{
    public const MAX_BYTES = 48 * 1024;

    public static function for_post(WP_Post $post): string|WP_Error
    {
        $blocks = parse_blocks($post->post_content);
        $content = serialize_blocks(self::without_bridge_presentation($blocks));
        $html = trim(wp_kses_post(do_blocks($content)));
        if ($html === '') {
            return new WP_Error('discussionbridge_empty_content', 'Published content is empty.');
        }
        if (strlen($html) > self::MAX_BYTES) {
            return new WP_Error('discussionbridge_content_too_large', 'Published content exceeds the DiscussionBridge limit.');
        }

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $blocks
     * @return array<int, array<string, mixed>>
     */
    private static function without_bridge_presentation(array $blocks): array
    {
        $kept = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === 'discussionbridge/from-discourse') {
                continue;
            }
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = self::without_bridge_presentation($block['innerBlocks']);
            }
            $kept[] = $block;
        }

        return $kept;
    }
}
