<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use DOMDocument;
use DOMElement;
use DOMXPath;
use stdClass;
use WP_Error;

/** An explicit whole-article integration in a REAL normal native theme render, never a callback replay. */
final class CmsSourceArticleProfile
{
    private static ?stdClass $template = null;
    private static ?array $content = null;
    private static ?string $template_path = null;
    public const BEGIN = '<!--discussionbridge-source-article-1:begin-->';
    public const END = '<!--discussionbridge-source-article-1:end-->';

    public static function template(string $path): string
    {
        self::$template = null; self::$content = null; self::$template_path = null;
        if (!function_exists('get_stylesheet_directory') || !function_exists('get_template_directory')) return $path;
        $file = realpath($path);
        foreach ([get_stylesheet_directory(), get_template_directory()] as $root) {
            $root = realpath($root);
            if (is_string($root) && is_string($file) && str_starts_with($file, $root . DIRECTORY_SEPARATOR) && is_file($file)
                && strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'php') {
                self::$template = (object) ['stylesheet' => get_stylesheet(), 'template' => get_template(),
                    'relative_path' => str_replace(DIRECTORY_SEPARATOR, '/', substr($file, strlen($root) + 1)), 'sha256' => hash_file('sha256', $file)];
                self::$template_path = $file;
                break;
            }
        }
        return $path;
    }

    private static function context(int $id): bool
    {
        $post = $GLOBALS['post'] ?? null;
        return self::$template !== null && self::$template_path !== null && is_file(self::$template_path) && hash_file('sha256', self::$template_path) === self::$template->sha256
            && $post instanceof \WP_Post && $post->ID === $id
            && !is_admin() && is_singular(Settings::post_types()) && in_the_loop() && is_main_query()
            && get_queried_object_id() === $id && function_exists('is_user_logged_in') && !is_user_logged_in()
            && function_exists('is_preview') && !is_preview() && (int) ($GLOBALS['more'] ?? 0) === 1;
    }

    /** Delimit the actual complete source before Bridge adds discussion; later native filters run normally. */
    public static function mark_content(string $html): string
    {
        $id = get_queried_object_id(); if (!self::context($id)) return $html;
        $state = (new CmsJournal($id))->read();
        if (!$state instanceof stdClass || !isset($state->source_move->receipt) || $state->scope !== CmsSourcePublisher::scope($id)
            || !CmsArtifacts::matches($state->artifacts) || str_contains($html, self::BEGIN) || str_contains($html, self::END)) return $html;
        return self::BEGIN . $html . self::END;
    }

    /** Observes the actual normal the_content result after native filters; invokes no render callback itself. */
    public static function content(string $html): string
    {
        $id = get_queried_object_id();
        if (self::context($id)) self::$content = ['native_id' => $id, 'html' => $html];
        return $html;
    }

    /** Called by the qualified native template around its complete title/authors/the_content article, before echo. */
    public static function article(string $html): string
    {
        $id = get_queried_object_id();
        if (!self::context($id) || self::$content === null || self::$content['native_id'] !== $id) return $html;
        $storage = new CmsJournal($id); $state = $storage->read();
        if (!$state instanceof stdClass || !CmsArtifacts::matches($state->artifacts)
            || $state->scope !== CmsSourcePublisher::scope($id)) return $html;
        if (!isset($state->source_move->receipt)) {
            $source = $state->current_native_source ?? null; $post = get_post($id); $metadata = CmsSourceOwnership::metadata($id);
            if (!isset($state->source_ancestry) || !$source instanceof stdClass || !$post instanceof \WP_Post || $post->post_status !== 'publish'
                || $metadata === null || $metadata['receipt']->resource_id !== ($state->receipt->resource_id ?? null)
                || $post->post_content !== $source->article || !CmsSourceOwnership::complete($source, $state->current_record)) return $html;
            // Normal future views retain original copied targets; no retired challenge/edition marker is emitted.
            $preserved = NativeContent::relative_targets($html, $source->article_url);
            return is_wp_error($preserved) ? $html : $preserved;
        }
        $move = $state->source_move; $source = $state->current_native_source;
        if (!CmsSourceOwnership::complete($source, $move->accepted_record)) return $html;
        $row = CmsSourceNativeMove::row($id); $native = get_post($id);
        if (is_wp_error($row) || !$native instanceof \WP_Post || $native->post_status !== 'publish' || ($native->post_password ?? '') !== '') return $html;
        $displayed = $GLOBALS['post'] ?? null;
        foreach (get_object_vars($row) as $field => $value) if (!$displayed instanceof \WP_Post || !property_exists($displayed, $field) || (string) $displayed->$field !== (string) $value) return $html;
        $applied = isset($move->after_row, $move->new_source_revision) && $move->native_operation === 'applied';
        $expected_row = $applied ? $move->after_row : $move->native_plan->before->native_row;
        if (get_object_vars($row) != get_object_vars($expected_row)
            || get_permalink($id) !== ($applied ? $move->request->body->new_url : $move->request->body->old_url)) return $html;
        $resolved_article = NativeContent::relative_targets($html, $source->article_url); if (is_wp_error($resolved_article)) return $html;
        $parts = self::parts($resolved_article, $source->article_url); if (is_wp_error($parts)) return $html;
        $document_body = $parts['document']->getElementsByTagName('body')->item(0);
        if (!$document_body instanceof DOMElement) return $html;
        foreach ($document_body->childNodes as $child) if ($child !== $parts['article'] && !($child instanceof \DOMText && trim($child->textContent) === '')) return $html;
        $content = NativeContent::relative_targets(self::$content['html'], $source->article_url);
        if (is_wp_error($content) || $parts['tree']->body !== self::body($content) || $parts['tree']->title !== $source->semantic_source->title
            || CmsArtifacts::codec()->semanticIdentity($parts['tree']->authors) !== CmsArtifacts::codec()->semanticIdentity($source->semantic_source->source_authors)
            || NativeContent::function_loss($source->prepared_article, $parts['tree']->body) !== null
            || NativeContent::data_loss($source->prepared_article, $parts['tree']->body) !== null) return $html;
        if (!$applied) {
            if (!in_array($move->stage, ['awaiting_actual_native_render', 'render_expected_captured'], true)) return $html;
            $saved = FromSynchronizer::serialized(function (callable $guard) use ($id, $storage, $state, $move, $parts): bool {
                $fresh = $storage->read();
                if (!$fresh instanceof stdClass || $fresh->source_move->receipt->move_id !== $move->receipt->move_id || $fresh->source_move->stage !== 'awaiting_actual_native_render'
                    || !$guard() || !CmsArtifacts::matches($fresh->artifacts)) return false;
                $observed = CmsSourceOwnership::observe($id, $fresh);
                if (is_wp_error($observed) || CmsSourceOwnership::digest($observed) !== CmsSourceOwnership::digest($move->native_plan->before)) return false;
                $fresh->source_move->expected_native = (object) ['profile' => CmsSourceOwnership::PROFILE, 'template' => self::$template,
                    'tree' => $parts['tree'], 'sha256' => CmsSourceOwnership::digest($parts['tree']),
                    'before_receipt_sha256' => CmsSourceOwnership::digest($observed), 'observed_at' => self::now()];
                $fresh->source_move->stage = 'render_expected_captured';
                if (!$storage->write($fresh, $guard)) return false;
                Publisher::queue($id, false); return true;
            });
            if ($saved !== true) return $html;
        } elseif (!isset($move->expected_native) || CmsSourceOwnership::digest(self::$template) !== CmsSourceOwnership::digest($move->expected_native->template)) return $html;
        $attributes = ['data-discussionbridge-source-article' => CmsSourceOwnership::PROFILE, 'data-site-id' => Settings::site_id(),
            'data-native-id' => (string) $id, 'data-resource-id' => $state->receipt->resource_id, 'data-external-id' => $state->current_record->external_id,
            'data-source-revision' => $applied ? $move->new_source_revision : $state->receipt->source_revision,
            'data-source-content-sha256' => $source->prepared_sha256, 'data-move-id' => $move->receipt->move_id,
            'data-proof-nonce' => $move->receipt->proof_nonce, 'data-template-sha256' => CmsSourceOwnership::digest(self::$template)];
        // Annotate original native tokens, never reserialize/convert rich article markup through DOM.
        $tags = new \WP_HTML_Tag_Processor($resolved_article);
        while ($tags->next_tag()) if ($tags->get_tag() === 'ARTICLE' && $tags->get_attribute('data-discussionbridge-source-article') === CmsSourceOwnership::PROFILE) {
            foreach ($attributes as $key => $value) $tags->set_attribute($key, $value);
            return $tags->get_updated_html();
        }
        return $html;
    }

    /** Whole article extraction is content comparison plus identity, never acceptance of a copied digest marker. */
    public static function parts(string $html, string $base): array|WP_Error
    {
        $document = self::document($html); if ($document === null) return self::failed();
        $xpath = new DOMXPath($document); $articles = $xpath->query('//*[@data-discussionbridge-source-article]');
        if ($articles === false || $articles->length !== 1 || !$articles->item(0) instanceof DOMElement) return self::failed();
        $article = $articles->item(0); if ($article->getAttribute('data-discussionbridge-source-article') !== CmsSourceOwnership::PROFILE || !self::activeBoundary($article)) return self::failed();
        $titles = $xpath->query('.//*[@data-discussionbridge-source-title]', $article); $bodies = $xpath->query('.//*[@data-discussionbridge-source-body]', $article);
        $authors = $xpath->query('.//*[@data-discussionbridge-source-author-id]', $article);
        if ($titles === false || $titles->length !== 1 || $bodies === false || $bodies->length !== 1 || $authors === false) return self::failed();
        foreach ([$titles->item(0), $bodies->item(0)] as $boundary) if (!$boundary instanceof DOMElement || !self::activeBoundary($boundary)) return self::failed();
        foreach ($authors as $author) if (!$author instanceof DOMElement || !self::activeBoundary($author)) return self::failed();
        $author_tree = []; foreach ($authors as $author) $author_tree[] = (object) ['id' => $author->getAttribute('data-discussionbridge-source-author-id'), 'name' => $author->textContent];
        $comments = $xpath->query('.//comment()', $bodies->item(0)); $begin = 0; $end = 0;
        foreach ($comments as $comment) { if ($comment->nodeValue === 'discussionbridge-source-article-1:begin') $begin++; if ($comment->nodeValue === 'discussionbridge-source-article-1:end') $end++; }
        if ($begin !== 1 || $end !== 1) return self::failed();
        $raw = self::body($html); if (!is_string($raw)) return self::failed();
        $resolved = NativeContent::relative_targets($raw, $base); if (is_wp_error($resolved)) return $resolved;
        return ['document' => $document, 'article' => $article, 'tree' => (object) ['title' => $titles->item(0)->textContent, 'authors' => $author_tree, 'body' => $resolved]];
    }

    private static function body(string $html): ?string
    {
        if (substr_count($html, self::BEGIN) !== 1 || substr_count($html, self::END) !== 1) return null;
        $start = strpos($html, self::BEGIN) + strlen(self::BEGIN); $end = strpos($html, self::END);
        return $end >= $start ? substr($html, $start, $end - $start) : null;
    }

    /** Check only owned boundaries and their ancestors, never arbitrary source descendants. */
    private static function activeBoundary(DOMElement $boundary): bool
    {
        for ($node = $boundary; $node instanceof DOMElement; $node = $node->parentNode) {
            if (in_array(strtolower($node->tagName), ['template', 'script', 'style', 'noscript', 'textarea', 'title', 'xmp', 'iframe', 'noembed', 'noframes', 'plaintext'], true)
                || $node->hasAttribute('hidden') || $node->hasAttribute('inert') || self::inactiveStyle($node->getAttribute('style'))) return false;
        }
        return true;
    }

    /** Explicit inline hiding only; no stylesheet evaluation or source-content eligibility policy. */
    private static function inactiveStyle(string $style): bool
    {
        $declarations = []; $part = ''; $quote = null; $depth = 0; $length = strlen($style);
        for ($i = 0; $i < $length; $i++) {
            $char = $style[$i];
            if ($quote !== null) {
                $part .= $char;
                if ($char === '\\' && $i + 1 < $length) $part .= $style[++$i];
                elseif ($char === $quote) $quote = null;
            } elseif ($char === '/' && ($style[$i + 1] ?? '') === '*') {
                $end = strpos($style, '*/', $i + 2); if ($end === false) break; $part .= ' '; $i = $end + 1;
            } elseif ($char === '"' || $char === "'") { $quote = $char; $part .= $char; }
            elseif ($char === '(') { $depth++; $part .= $char; }
            elseif ($char === ')') { $depth = max(0, $depth - 1); $part .= $char; }
            elseif ($char === ';' && $depth === 0) { $declarations[] = $part; $part = ''; }
            else $part .= $char;
        }
        $declarations[] = $part;
        $effective = [];
        foreach ($declarations as $declaration) {
            $pair = explode(':', $declaration, 2); if (count($pair) !== 2) continue;
            $name = strtolower(trim($pair[0])); $important = preg_match('/!\s*important\s*\z/i', $pair[1]) === 1;
            $value = strtolower(trim(preg_replace('/\s*!\s*important\s*\z/i', '', $pair[1]) ?? ''));
            $known = match ($name) {
                'display' => ['none', 'block', 'inline', 'inline-block', 'flow-root', 'list-item', 'flex', 'inline-flex', 'grid', 'inline-grid', 'contents', 'table', 'inline-table', 'table-row-group', 'table-header-group', 'table-footer-group', 'table-row', 'table-cell', 'table-column-group', 'table-column', 'table-caption'],
                'visibility' => ['visible', 'hidden', 'collapse'],
                'content-visibility' => ['visible', 'auto', 'hidden'],
                default => [],
            };
            // Unknown/computed values cannot clear a known hiding declaration.
            if (!in_array($value, $known, true)) continue;
            if (!isset($effective[$name]) || !$effective[$name]['important'] || $important) {
                $effective[$name] = ['value' => $value, 'important' => $important];
            }
        }
        return ($effective['display']['value'] ?? null) === 'none'
            || in_array($effective['visibility']['value'] ?? null, ['hidden', 'collapse'], true)
            || ($effective['content-visibility']['value'] ?? null) === 'hidden';
    }

    private static function document(string $html): ?DOMDocument
    {
        if (!class_exists(DOMDocument::class) || preg_match('//u', $html) !== 1) return null;
        $prior = libxml_use_internal_errors(true);
        try { $document = new DOMDocument('1.0', 'UTF-8'); return $document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING) ? $document : null; }
        finally { libxml_clear_errors(); libxml_use_internal_errors($prior); }
    }

    public static function now(): string { return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'); }
    private static function failed(): WP_Error { return new WP_Error('discussionbridge_source_article_boundary', 'The actual native whole-article title/authors/body profile is unavailable. Preserve the source and URL reservations.'); }
}
