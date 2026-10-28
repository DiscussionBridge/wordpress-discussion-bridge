<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use DiscussionBridge\CmsV4\Codec;
use stdClass;

require_once __DIR__ . '/private/cms-v4/CmsCodec.php';

/** One current development SDK; bindings describe installed bytes, never publication authority. */
final class CmsArtifacts
{
    public const CANDIDATE = 'discussionbridge-v4-core-20261025-r6';
    public const CODEC_SHA256 = '6eece074e1268d2d90890afd0215da80c06c23961b497a4df190f7876af0fc19';
    public const SCHEMA_SHA256 = '8563a9b1346e3bf82635bd65a46bb1e25415a1003d7432f8fcb8c1c6e4f03c14';

    public static function codec(): Codec
    {
        if (hash_file('sha256', __DIR__ . '/private/cms-v4/CmsCodec.php') !== self::CODEC_SHA256
            || hash_file('sha256', __DIR__ . '/private/cms-v4/cms-schema.json') !== self::SCHEMA_SHA256) {
            throw new \RuntimeException('The installed CMS development artifact does not match its recorded bytes.');
        }
        return new Codec(__DIR__ . '/private/cms-v4/cms-schema.json');
    }

    public static function binding(): stdClass
    {
        return (object) ['candidate' => self::CANDIDATE, 'wire' => 'v4', 'codec_sha256' => self::CODEC_SHA256, 'schema_sha256' => self::SCHEMA_SHA256];
    }

    public static function matches(stdClass $binding): bool
    {
        $codec = self::codec();
        return $codec->semanticIdentity($binding) === $codec->semanticIdentity(self::binding());
    }

    /** Exactly the prior closed r5 profile is migratable. Preserve its provenance and every pending byte. */
    public static function upgrade_from_r5(stdClass $state, callable $save): bool
    {
        $old = (object) ['candidate' => 'discussionbridge-v4-core-20261015-r5', 'wire' => 'v4',
            'codec_sha256' => '717513f474719538be0d40ad8cc4aea274051c17cf522274898ee29abf64a481',
            'schema_sha256' => '7dee282601e4195459f38b2f2451a5f0f03af39a5f60affc65c49b34664e3f7a'];
        if (self::CANDIDATE === $old->candidate || !isset($state->artifacts)
            || self::codec()->semanticIdentity($state->artifacts) !== self::codec()->semanticIdentity($old)
            || !self::pending($state->pending ?? null)) return false;
        $state->artifact_history ??= []; $state->artifact_history[] = $state->artifacts;
        $state->artifacts = self::binding();
        return $save();
    }

    /** Historical descriptors never select a codec or replace current installed-byte integrity. */
    public static function provenance(mixed $binding): bool
    {
        return $binding instanceof stdClass && ($binding->wire ?? null) === 'v4'
            && is_string($binding->candidate ?? null) && $binding->candidate !== ''
            && preg_match('/\A[a-f0-9]{64}\z/D', $binding->codec_sha256 ?? '') === 1
            && preg_match('/\A[a-f0-9]{64}\z/D', $binding->schema_sha256 ?? '') === 1;
    }

    public static function pending(mixed $pending): bool
    {
        if ($pending === null) return true;
        try {
            $codec = self::codec();
            if (!$pending instanceof stdClass || !is_string($pending->route ?? null) || !$pending->options instanceof stdClass
                || !is_string($pending->identity ?? null)
                || isset($pending->creator_artifacts) && !self::provenance($pending->creator_artifacts)) return false;
            $codec->validateRoute($pending->route, (array) $pending->options);
            return hash_equals($pending->identity, $codec->semanticIdentity((object) ['route' => $pending->route, 'options' => $pending->options]));
        } catch (\Throwable) { return false; }
    }

    /** Validate the retained objects about to be consumed; do not scan or rewrite old history. */
    public static function retained(stdClass $state): bool
    {
        try {
            $codec = self::codec();
            if (!self::provenance($state->artifacts ?? null) || !self::pending($state->pending ?? null)) return false;
            if (isset($state->withdrawal) && !self::pending($state->withdrawal->pending ?? null)) return false;
            if (isset($state->current_record)) $codec->validateGeneratedEnvelope($state->current_record);
            if (isset($state->capture)) {
                $codec->validateGeneratedEnvelope($state->capture->body);
                if (isset($state->capture->creator_artifacts) && !self::provenance($state->capture->creator_artifacts)) return false;
                if (($state->pending->route ?? null) === 'V4-02'
                    && $codec->semanticIdentity($state->capture->body) !== $codec->semanticIdentity($state->pending->options->body->bridge_record)) return false;
            }
            if (isset($state->source_move)) {
                $move = $state->source_move;
                if (!self::provenance($move->artifacts ?? null)) return false;
                foreach (['request' => 'V4-28', 'finalize_request' => 'V4-29', 'abort_request' => 'V4-30'] as $key => $route)
                    if (isset($move->$key)) $codec->validateRoute($route, (array) $move->$key);
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Only new archive entries use this role map; previous entries are never retagged. */
    public static function archive(stdClass $state, ?stdClass $pending): array
    {
        $context = ['artifacts' => $pending->creator_artifacts ?? $state->artifacts, 'journal_artifacts' => $state->artifacts,
            'request_creator_known' => isset($pending->creator_artifacts)];
        if (isset($state->capture->creator_artifacts)) $context['capture_artifacts'] = $state->capture->creator_artifacts;
        if (isset($state->response_artifacts)) $context['result_artifacts'] = $state->response_artifacts;
        return $context;
    }
}
