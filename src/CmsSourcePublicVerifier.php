<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Independent anonymous new-canonical fetch of a whole native source article, not the From fragment profile. */
final class CmsSourcePublicVerifier
{
    public static function verify(int $id, stdClass $state, callable $guard): stdClass|WP_Error
    {
        $move = $state->source_move;
        if (!$guard() || $move->native_operation !== 'applied' || !isset($move->expected_native, $move->native_receipt, $move->after_row)
            || !CmsArtifacts::matches($state->artifacts) || $state->scope !== CmsSourcePublisher::scope($id)) return self::failed();
        $row = CmsSourceNativeMove::row($id);
        if (is_wp_error($row) || get_object_vars($row) != get_object_vars($move->after_row) || get_permalink($id) !== $move->request->body->new_url) return self::failed();
        $revision = CmsNativeFence::revision($id); if (!is_string($revision)) return self::failed();
        $maximum = max(262144, strlen($move->expected_native->tree->body) * 2 + 262144);
        $response = wp_safe_remote_get($move->request->body->new_url, ['timeout' => 10, 'redirection' => 0, 'decompress' => false,
            'limit_response_size' => $maximum + 1, 'cookies' => [], 'headers' => ['Accept' => 'text/html', 'Accept-Encoding' => 'identity', 'Cache-Control' => 'no-cache']]);
        if (is_wp_error($response) || !$guard() || wp_remote_retrieve_response_code($response) !== 200) return self::failed();
        $encoding = strtolower(trim(wp_remote_retrieve_header($response, 'content-encoding')));
        $raw = wp_remote_retrieve_body($response);
        if ($encoding !== '' && $encoding !== 'identity' || !self::htmlUtf8(wp_remote_retrieve_header($response, 'content-type')) || strlen($raw) > $maximum) return self::failed();
        $parts = CmsSourceArticleProfile::parts($raw, $state->current_native_source->article_url); if (is_wp_error($parts)) return self::failed();
        $identity = ['data-site-id' => Settings::site_id(), 'data-native-id' => (string) $id,
            'data-resource-id' => $state->receipt->resource_id, 'data-external-id' => $state->current_record->external_id,
            'data-source-revision' => $move->new_source_revision, 'data-source-content-sha256' => $state->current_record->source_content_sha256,
            'data-move-id' => $move->receipt->move_id, 'data-proof-nonce' => $move->receipt->proof_nonce,
            'data-template-sha256' => CmsSourceOwnership::digest($move->expected_native->template)];
        foreach ($identity as $key => $value) if ($parts['article']->getAttribute($key) !== $value) return self::failed();
        $actual = CmsSourceOwnership::digest($parts['tree']);
        if (!hash_equals($move->expected_native->sha256, $actual)
            || CmsArtifacts::codec()->semanticIdentity($parts['tree']) !== CmsArtifacts::codec()->semanticIdentity($move->expected_native->tree)) return self::failed();
        $after = CmsSourceNativeMove::row($id); $after_revision = CmsNativeFence::revision($id);
        if (is_wp_error($after) || get_object_vars($after) != get_object_vars($row) || $after_revision !== $revision || !$guard()) return self::failed();
        $checked = CmsSourceArticleProfile::now(); if ($checked < $move->receipt->prepared_at || $checked < $move->expected_native->observed_at) return self::failed();
        $report = (object) ['url' => $move->request->body->new_url, 'external_id' => $state->current_record->external_id,
            'source_revision' => $move->new_source_revision, 'source_content_sha256' => $state->current_record->source_content_sha256,
            'native_ownership_receipt_sha256' => CmsSourceOwnership::digest($move->native_receipt), 'native_publication_sha256' => $actual,
            'verification_profile' => CmsSourceOwnership::PROFILE, 'checked_at' => $checked];
        try { return CmsArtifacts::codec()->validate('SourceUrlVerification', $report); } catch (\RuntimeException) { return self::failed(); }
    }

    /** One HTML media value, syntactically valid parameters, at most one explicit UTF-8 charset. */
    private static function htmlUtf8(string $media): bool
    {
        if (preg_match('/[\x00-\x08\x0a-\x1f\x7f]/', $media) === 1
            || preg_match('/\A[ \t]*text\/html[ \t]*(?=;|\z)/iD', $media, $essence) !== 1) return false;
        $offset = strlen($essence[0]); $charset = false;
        $token = "[!#$%&'*+.^_`|~0-9A-Za-z-]+";
        $parameter = '@\\G[ \\t]*;[ \\t]*('.$token.')[ \\t]*=[ \\t]*(?:"((?:[\\x09\\x20-\\x21\\x23-\\x5b\\x5d-\\x7e]|\\\\[\\x09\\x20-\\x7e])*)"|('.$token.'))[ \\t]*@';
        while ($offset < strlen($media)) {
            if (preg_match($parameter, $media, $match, PREG_UNMATCHED_AS_NULL, $offset) !== 1) return false;
            $offset += strlen($match[0]);
            if (strtolower($match[1]) === 'charset') {
                $value = $match[2] !== null ? preg_replace('/\\\\(.)/s', '$1', $match[2]) : $match[3];
                if ($charset || strtolower($value ?? '') !== 'utf-8') return false;
                $charset = true;
            }
        }
        return true;
    }

    private static function failed(): WP_Error { return new WP_Error('discussionbridge_source_public_verification', 'The actual anonymous new-URL whole native article could not be verified. Retain the applied move, exact source and both reservations.'); }
}
