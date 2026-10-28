<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use DOMDocument;
use DOMElement;
use DOMXPath;
use stdClass;
use WP_Error;

/** Anonymous canonical fetch and independent expected native rendered-fragment comparison. */
final class CmsPublicVerifier
{
    public static function verify(CmsClient $client, stdClass $mapping, stdClass $native_result, ?stdClass $source, callable $guard): stdClass|WP_Error
    {
        $id = $native_result->native_id;
        if ((string) (int) $id !== $id || !$guard()) return self::failed();
        clean_post_cache((int) $id); $native = get_post((int) $id);
        $state = get_post_meta((int) $id, CmsPresentation::META, true);
        if (!$native instanceof \WP_Post || !is_array($state) || ($state['receipt']['mapping_id'] ?? null) !== $mapping->mapping_id
            || ($state['receipt']['native_id'] ?? null) !== $id || $native_result->canonical_url !== $mapping->canonical_url
            || $state['receipt']['canonical_url'] !== $mapping->canonical_url) return self::failed();
        $revision = CmsNativeFence::revision((int) $id);
        if (is_wp_error($revision) || ($state['managed_revision'] ?? null) !== $revision || $native_result->native_revision !== $revision
            || !hash_equals($native_result->ownership_receipt_sha256, $client->codec()->nativeOwnershipReceiptSha256((object) $state['receipt']))) return self::failed();
        $expected = null;
        if ($native_result->kind === 'publish_revision') {
            if ($source === null || $native->post_status !== 'publish' || get_permalink($native) !== $mapping->canonical_url
                || $state['receipt']['source_revision'] !== $source->source_revision || $state['source_sha256'] !== $source->content_sha256
                || $state['mode'] !== $mapping->presentation_mode) return self::failed();
            $expected = self::fragment(CmsPresentation::expected($native, $state), $state);
            if (is_wp_error($expected)) return $expected;
        } elseif ($native->post_status === 'publish') return self::failed();
        if (!$guard()) return self::failed();
        // Public bytes need no protocol secret, lease token, cookies or logged-in identity.
        $maximum = $expected === null ? 65536 : max(262144, strlen($expected) * 2 + 262144);
        $response = wp_safe_remote_get($mapping->canonical_url, ['timeout' => 10, 'redirection' => 0, 'decompress' => false,
            'limit_response_size' => $maximum + 1, 'cookies' => [], 'headers' => ['Accept' => 'text/html', 'Accept-Encoding' => 'identity', 'Cache-Control' => 'no-cache']]);
        if (is_wp_error($response) || !$guard()) return self::failed();
        $status = wp_remote_retrieve_response_code($response); $encoding = strtolower(trim(wp_remote_retrieve_header($response, 'content-encoding')));
        $raw = wp_remote_retrieve_body($response);
        if (strlen($raw) > $maximum || ($encoding !== '' && $encoding !== 'identity')) return self::failed();
        $checked = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z');
        if ($mapping->synchronized_at === null || strcmp($checked, $mapping->synchronized_at) < 0) return self::failed();
        if ($native_result->kind === 'withdraw') {
            if (!in_array($status, [404, 410], true)) return self::failed();
            $report = (object) ['kind' => 'absent', 'url' => $mapping->canonical_url, 'native_id' => $id, 'checked_at' => $checked, 'http_status' => $status];
        } else {
            $media = strtolower(trim(wp_remote_retrieve_header($response, 'content-type')));
            if ($status !== 200 || preg_match('/\Atext\/html(?:\s*;.*)?\z/iD', $media) !== 1 || preg_match('//u', $raw) !== 1) return self::failed();
            $actual = self::fragment($raw, $state);
            if (is_wp_error($actual) || !hash_equals(hash('sha256', $expected), hash('sha256', $actual))) return self::failed();
            $report = (object) ['kind' => 'published', 'url' => $mapping->canonical_url, 'native_id' => $id,
                'source_revision' => $source->source_revision, 'source_content_sha256' => $source->content_sha256,
                'native_publication_sha256' => hash('sha256', $actual), 'verification_profile' => CmsPresentation::PROFILE,
                'checked_at' => $checked, 'representation' => $native_result->representation];
        }
        try { $client->codec()->validate('Verification', $report); }
        catch (\RuntimeException) { return self::failed(); }
        // A public read must not race a new local native change before its report is sent.
        clean_post_cache((int) $id); $after = get_post((int) $id);
        if (!$after instanceof \WP_Post || $after->post_content !== $native->post_content || $after->post_title !== $native->post_title
            || $after->post_status !== $native->post_status || get_post_meta((int) $id, CmsPresentation::META, true) !== $state || !$guard()) return self::failed();
        $final_revision = CmsNativeFence::revision((int) $id);
        if (!is_string($final_revision) || !hash_equals($revision, $final_revision)) return self::failed();
        return $report;
    }

    private static function fragment(string $html, array $state): string|WP_Error
    {
        if (!class_exists(DOMDocument::class)) return self::failed();
        $old = libxml_use_internal_errors(true);
        try {
            $document = new DOMDocument('1.0', 'UTF-8');
            if (!$document->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING)) return self::failed();
            $elements = (new DOMXPath($document))->query('//*[@data-discussionbridge-cms]');
            if ($elements === false || $elements->length !== 1) return self::failed();
            $fragment = $elements->item(0);
            if (!$fragment instanceof DOMElement || $fragment->getAttribute('data-discussionbridge-cms') !== CmsPresentation::PROFILE
                || $fragment->getAttribute('data-native-id') !== $state['receipt']['native_id']
                || $fragment->getAttribute('data-resource-id') !== $state['receipt']['resource_id']
                || $fragment->getAttribute('data-source-revision') !== $state['receipt']['source_revision']
                || $fragment->getAttribute('data-presentation-mode') !== $state['mode']) return self::failed();
            self::canonicalize($fragment);
            $serialized = $document->saveHTML($fragment);
            return is_string($serialized) ? $serialized : self::failed();
        } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    }

    private static function canonicalize(DOMElement $element): void
    {
        $attributes = [];
        foreach ($element->attributes as $attribute) $attributes[$attribute->name] = $attribute->value;
        // Existing renderer allocates request-local iframe IDs; they are not native publication identities.
        if ($element->tagName === 'iframe' && isset($attributes['data-discussionbridge-embed'])) unset($attributes['id']);
        while ($element->attributes->length > 0) $element->removeAttributeNode($element->attributes->item(0));
        ksort($attributes, SORT_STRING);
        foreach ($attributes as $name => $value) $element->setAttribute($name, $value);
        foreach ($element->childNodes as $child) if ($child instanceof DOMElement) self::canonicalize($child);
    }

    private static function failed(): WP_Error { return new WP_Error('discussionbridge_cms_public_verification_failed', 'The anonymous canonical native publication could not be verified. Preserve the synchronized result for reconciliation.'); }
}
