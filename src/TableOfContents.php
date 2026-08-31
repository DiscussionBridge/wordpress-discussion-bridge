<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class TableOfContents
{
    public static function render(string $html): string
    {
        if (!class_exists(\DOMDocument::class) || trim($html) === '') {
            return $html;
        }

        $document = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<?xml encoding="utf-8" ?><div id="discussionbridge-content-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        if (!$loaded) {
            return $html;
        }

        $xpath = new \DOMXPath($document);
        $headings = $xpath->query('//*[@id="discussionbridge-content-root"]//*[self::h2 or self::h3]');
        if ($headings === false || $headings->length < 2) {
            return $html;
        }

        $used = [];
        $links = [];
        foreach ($headings as $heading) {
            $label = trim((string) $heading->textContent);
            if ($label === '') {
                continue;
            }
            $base = sanitize_title($heading->getAttribute('id') ?: $label);
            $base = $base !== '' ? $base : 'section';
            $id = $base;
            $suffix = 2;
            while (isset($used[$id])) {
                $id = $base . '-' . $suffix++;
            }
            $used[$id] = true;
            $heading->setAttribute('id', $id);
            $links[] = sprintf(
                '<li class="discussionbridge-toc__level-%s"><a href="#%s">%s</a></li>',
                strtolower($heading->nodeName),
                esc_attr($id),
                esc_html($label)
            );
        }
        if (count($links) < 2) {
            return $html;
        }

        $root = $document->getElementById('discussionbridge-content-root');
        if (!$root instanceof \DOMElement) {
            return $html;
        }
        $content = '';
        foreach ($root->childNodes as $child) {
            $content .= $document->saveHTML($child);
        }

        return sprintf(
            '<nav class="discussionbridge-toc" aria-label="%s"><strong>%s</strong><ol>%s</ol></nav>%s',
            esc_attr__('On this page', 'discussionbridge'),
            esc_html__('On this page', 'discussionbridge'),
            implode('', $links),
            $content
        );
    }
}
