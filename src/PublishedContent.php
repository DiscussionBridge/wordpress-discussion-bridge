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
        if (preg_match('//u', $html) !== 1) {
            return new WP_Error('discussionbridge_invalid_content_encoding', 'Published content is not valid UTF8.');
        }

        return $html;
    }

    /** Keep the native source complete; only the transmitted topic body is excerpted. */
    public static function excerpt(string $html, string $canonical_url, int $maximum_bytes = self::MAX_BYTES): string|WP_Error
    {
        if (strlen($html) <= $maximum_bytes) {
            return $html;
        }
        $suffix = '<p><a href="' . esc_url($canonical_url) . '">Read More</a></p>';
        $text = html_entity_decode(wp_strip_all_tags(preg_replace('/<\/(?:p|div|li|h[1-6]|tr)>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') {
            $text = 'Continue reading at the source.';
        }
        if ($maximum_bytes <= strlen($suffix) + 7 || preg_match('//u', $text) !== 1) {
            return new WP_Error('discussionbridge_excerpt_unavailable', 'The source excerpt cannot fit within the delivery limit.');
        }
        $low = 0;
        $high = strlen($text);
        $best = '';
        while ($low <= $high) {
            $length = intdiv($low + $high, 2);
            $prefix = substr($text, 0, $length);
            while ($prefix !== '' && preg_match('//u', $prefix) !== 1) {
                $prefix = substr($prefix, 0, -1);
            }
            $candidate = '<p>' . esc_html($prefix) . '</p>' . $suffix;
            if (strlen($candidate) <= $maximum_bytes) {
                $best = $candidate;
                $low = $length + 1;
            } else {
                $high = $length - 1;
            }
        }
        return $best === '' ? new WP_Error('discussionbridge_excerpt_unavailable', 'The source excerpt cannot fit within the delivery limit.') : $best;
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
