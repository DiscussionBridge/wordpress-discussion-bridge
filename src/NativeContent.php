<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;
use WP_Post;

/** Existing native sanitizer order; exact V4 delivery caps and generated link belong to the SDK. */
final class NativeContent
{
    public static function complete(WP_Post $post, string $article_url): string|WP_Error
    {
        $blocks = self::without_bridge(parse_blocks($post->post_content));
        $rendered = do_blocks(serialize_blocks($blocks));
        $html = trim(wp_kses_post($rendered));
        $function_loss = self::function_loss($rendered, $html);
        if ($function_loss !== null) return new WP_Error('discussionbridge_native_function_preservation',
            'Native preparation removed a specific active function. Preserve the original and publication state; the operator must decide how that function is supported.', $function_loss);
        $data_loss = self::data_loss($rendered, $html);
        if ($data_loss !== null) return new WP_Error('discussionbridge_native_data_preservation',
            'Native preparation removed specific nonempty article data. Preserve the original and publication state; this is a Bridge limitation.', $data_loss);
        // Actual native element removal is a specific unsupported preparation, never faithful full delivery.
        foreach (['iframe', 'video', 'audio', 'source', 'track'] as $tag) {
            if (preg_match_all('/<' . $tag . '\\b[^>]*>/i', $rendered) > preg_match_all('/<' . $tag . '\\b[^>]*>/i', $html))
                return new WP_Error('discussionbridge_native_media_preservation', 'The current native preparation removed a media element. Preserve the source and reconcile native rendering before delivery.',
                    ['element' => $tag, 'rendered_bytes' => strlen($rendered), 'rendered_sha256' => hash('sha256', $rendered)]);
        }
        if ($html === '' && $rendered !== '') return self::limitation('native_nonempty_preparation_removed');
        if ($html === '') return new WP_Error('discussionbridge_empty_content', 'Published content is empty.');
        if (preg_match('//u', $html) !== 1) return new WP_Error('discussionbridge_invalid_content_encoding', 'Published content is not valid UTF8.');
        return self::relative_targets($html, $article_url);
    }

    /** Parsed observations only: no execution permission, content-policy allowlist or semantic equivalence claim. */
    public static function function_loss(string $original, string $prepared): ?array
    {
        if (!class_exists(\WP_HTML_Tag_Processor::class)) return null;
        $before = self::constructs($original, false); $after = self::constructs($prepared, false);
        foreach ($before as $key => $observation) if ($observation['count'] > ($after[$key]['count'] ?? 0)) {
            // Compare full parsed identities first; bound only the diagnostic names.
            foreach (['tag', 'attribute'] as $name) if (isset($observation[$name]))
                $observation[$name] = preg_replace('/[^a-zA-Z0-9_:.-]/', '_', substr($observation[$name], 0, 64));
            return $observation + ['prepared_count' => $after[$key]['count'] ?? 0];
        }
        return null;
    }

    public static function data_loss(string $original, string $prepared): ?array
    {
        if (!class_exists(\WP_HTML_Tag_Processor::class)) return null;
        $before = self::constructs($original, true); $after = self::constructs($prepared, true);
        foreach ($before as $key => $observation) if ($observation['count'] > ($after[$key]['count'] ?? 0))
            return $observation + ['prepared_count' => $after[$key]['count'] ?? 0];
        return null;
    }

    private static function constructs(string $html, bool $data_only): array
    {
        $tags = new \WP_HTML_Tag_Processor($html); $found = [];
        $record = static function (array $observation) use (&$found): void {
            $key = hash('sha256', (string) json_encode($observation));
            if (isset($found[$key])) $found[$key]['count']++; else $found[$key] = $observation + ['count' => 1];
        };
        while ($tags->next_tag()) {
            $tag = $tags->get_tag();
            if ($tag === 'SCRIPT') {
                $type = $tags->get_attribute('type'); $language = $tags->get_attribute('language');
                $type = is_string($type) ? strtolower(trim($type)) : '';
                if ($type === '' && is_string($language) && $language !== '') $type = 'text/' . strtolower($language);
                $classic = in_array($type, ['', 'application/ecmascript', 'application/javascript', 'application/x-ecmascript', 'application/x-javascript',
                    'text/ecmascript', 'text/javascript', 'text/javascript1.0', 'text/javascript1.1', 'text/javascript1.2', 'text/javascript1.3',
                    'text/javascript1.4', 'text/javascript1.5', 'text/jscript', 'text/livescript', 'text/x-ecmascript', 'text/x-javascript'], true);
                $src = $tags->get_attribute('src'); $text = $tags->get_modifiable_text();
                $active = $classic || in_array($type, ['module', 'importmap', 'speculationrules'], true);
                if (!$data_only && $active && (is_string($src) && $src !== '' || trim($text) !== ''))
                    $record(['kind' => 'script_function', 'tag' => $tag, 'type' => $classic ? 'classic' : $type,
                        'function_sha256' => hash('sha256', (string) json_encode([$src, $text]))]);
                if ($data_only && !$active && $text !== '') $record(['kind' => 'script_data', 'tag' => $tag, 'type' => $type,
                    'data_bytes' => strlen($text), 'data_sha256' => hash('sha256', $text)]);
            }
            if ($data_only) continue;
            // Observe actual parsed on* attributes, never enable or execute them.
            foreach ($tags->get_attribute_names_with_prefix('on') ?? [] as $attribute) {
                $handler = $tags->get_attribute($attribute);
                if (is_string($handler) && trim($handler) !== '') $record(['kind' => 'event_handler', 'tag' => $tag, 'attribute' => $attribute, 'function_sha256' => hash('sha256', $handler)]);
            }
        }
        return $found;
    }

    /** Only copied reference targets change; native KSES and the original article remain untouched. */
    public static function relative_targets(string $html, string $article_url): string|WP_Error
    {
        if (!class_exists(\WP_HTML_Tag_Processor::class) || !class_exists(\WpOrg\Requests\Iri::class)) return self::limitation('native_reference_api_unavailable');
        $tags = new \WP_HTML_Tag_Processor($html);
        // The native list describes URL attributes, not permission to admit any element, URI or provider.
        $attributes = wp_kses_uri_attributes();
        while ($tags->next_tag()) {
            if ($tags->get_tag() === 'BASE' || $tags->get_attribute('srcdoc') !== null) return self::limitation('nested_reference_base');
            $style = $tags->get_attribute('style');
            if (is_string($style) && str_contains($style, '\\')) return self::limitation('native_css_reference_preparation_unavailable');
            if (is_string($style) && preg_match_all('/url\(\s*([\'"]?)(.*?)\1\s*\)/i', $style, $css)) {
                foreach ($css[2] as $reference) if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', trim($reference))) return self::limitation('native_css_reference_preparation_unavailable');
            }
            foreach ($attributes as $name) {
                $value = $tags->get_attribute($name); if (!is_string($value)) continue;
                if (in_array($name, ['archive', 'profile'], true)) {
                    preg_match_all('/[^\x09\x0a\x0c\x0d\x20]+/', $value, $parts);
                    // Native set_attribute uses esc_url for these names; it cannot preserve a rewritten multi-URL list.
                    if (count($parts[0]) > 1) {
                        foreach ($parts[0] as $reference) if (!preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) return self::limitation('native_url_list_preparation_unavailable');
                    } else {
                        $value = self::reference($value, $article_url); if (is_wp_error($value)) return $value;
                    }
                } else {
                    $value = self::reference($value, $article_url); if (is_wp_error($value)) return $value;
                }
                if ($value !== $tags->get_attribute($name)) $tags->set_attribute($name, $value);
            }
            $srcset = $tags->get_attribute('srcset');
            if (is_string($srcset)) {
                $resolved = self::srcset($srcset, $article_url); if (is_wp_error($resolved)) return $resolved;
                if ($resolved !== $srcset) $tags->set_attribute('srcset', $resolved);
            }
        }
        return $tags->paused_at_incomplete_token() ? self::limitation('native_reference_token_incomplete') : $tags->get_updated_html();
    }

    private static function reference(string $reference, string $article_url): string|WP_Error
    {
        // Absolute references stay byte-identical; existing native preparation owns protocol permissions.
        $original = $reference; $reference = trim($reference, "\x09\x0a\x0c\x0d\x20");
        if (preg_match('/^[a-z][a-z0-9+.-]*:/i', $reference)) return $original;
        if (str_contains($reference, '\\')) return self::limitation('native_reference_backslash_unqualified');
        try {
            $target = \WpOrg\Requests\Iri::absolutize($article_url, $reference);
            return $target !== false && is_string($target->uri) ? $target->uri : self::limitation('native_reference_preparation_unavailable');
        } catch (\Throwable) { return self::limitation('native_reference_preparation_unavailable'); }
    }

    /** HTML srcset URL-token boundaries; descriptors, spacing and absolute/data URLs are retained. */
    private static function srcset(string $value, string $article_url): string|WP_Error
    {
        $output = ''; $offset = 0; $length = strlen($value);
        while ($offset < $length) {
            $start = $offset; $offset += strspn($value, "\x09\x0a\x0c\x0d\x20,", $offset); $output .= substr($value, $start, $offset - $start);
            if ($offset >= $length) break;
            $start = $offset; $offset += strcspn($value, "\x09\x0a\x0c\x0d\x20", $offset);
            $url = substr($value, $start, $offset - $start); $trimmed = rtrim($url, ',');
            $resolved = self::reference($trimmed, $article_url); if (is_wp_error($resolved)) return $resolved;
            $output .= $resolved . substr($url, strlen($trimmed));
            if ($trimmed !== $url) continue;
            $start = $offset; $parentheses = 0;
            while ($offset < $length) {
                $character = $value[$offset++];
                if ($character === '(') $parentheses++;
                elseif ($character === ')' && $parentheses > 0) $parentheses--;
                elseif ($character === ',' && $parentheses === 0) break;
            }
            $output .= substr($value, $start, $offset - $start);
        }
        return $output;
    }

    private static function limitation(string $reason): WP_Error
    {
        return new WP_Error('discussionbridge_native_preparation_limitation', 'Bridge cannot yet preserve this native reference form. Retain the source and exact publication state.', ['reason' => $reason]);
    }

    public static function article_prefix(string $html, int $maximum_bytes): string|WP_Error
    {
        $text = html_entity_decode(wp_strip_all_tags(preg_replace('/<\/(?:p|div|li|h[1-6]|tr)>/i', "\n", $html) ?? $html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if ($text === '') $text = 'Continue reading at the source.';
        if ($maximum_bytes <= 7 || preg_match('//u', $text) !== 1) return new WP_Error('discussionbridge_excerpt_unavailable', 'The source prefix cannot fit.');
        $low = 1; $high = strlen($text); $best = '';
        while ($low <= $high) {
            $length = intdiv($low + $high, 2); $prefix = substr($text, 0, $length);
            while ($prefix !== '' && preg_match('//u', $prefix) !== 1) $prefix = substr($prefix, 0, -1);
            $candidate = '<p>' . esc_html($prefix) . '</p>';
            if (strlen($candidate) <= $maximum_bytes) { $best = $candidate; $low = $length + 1; } else $high = $length - 1;
        }
        return $best !== '' ? $best : new WP_Error('discussionbridge_excerpt_unavailable', 'The source prefix cannot fit.');
    }

    private static function without_bridge(array $blocks): array
    {
        $kept = [];
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === 'discussionbridge/from-discourse') continue;
            if (isset($block['innerBlocks']) && is_array($block['innerBlocks'])) $block['innerBlocks'] = self::without_bridge($block['innerBlocks']);
            $kept[] = $block;
        }
        return $kept;
    }
}
