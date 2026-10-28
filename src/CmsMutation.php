<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** An uncertain authenticated mutation retains the same route, mutation ID and typed request tree. */
final class CmsMutation
{
    /** Build one new typed intent; callers persist it with its history before dispatch. */
    public static function prepare(CmsClient $client, string $route, array $options): stdClass|WP_Error
    {
        $body = clone $options['body']; $body->mutation_id = wp_generate_uuid4(); $options['body'] = $body;
        $tree = (object) $options;
        if (isset($tree->path) && is_array($tree->path)) $tree->path = (object) $tree->path;
        try {
            $client->codec()->validateRoute($route, (array) $tree);
            return (object) ['route' => $route, 'options' => $tree,
                'identity' => $client->codec()->semanticIdentity((object) ['route' => $route, 'options' => $tree]),
                'started_at' => time(), 'creator_artifacts' => CmsArtifacts::binding()];
        } catch (\RuntimeException) { return new WP_Error('discussionbridge_cms_invalid_request', 'The new native intent is not representable.'); }
    }

    public static function perform(CmsClient $client, string $route, array $options, stdClass $state, callable $save): mixed
    {
        $body = clone $options['body'];
        $body->mutation_id = $state->pending?->options->body->mutation_id ?? wp_generate_uuid4();
        $options['body'] = $body;
        $tree = (object) $options;
        if (isset($tree->path) && is_array($tree->path)) $tree->path = (object) $tree->path;
        try {
            $client->codec()->validateRoute($route, (array) $tree);
            $identity = $client->codec()->semanticIdentity((object) ['route' => $route, 'options' => $tree]);
        } catch (\DiscussionBridge\CmsV4\ValidationException $error) {
            return new WP_Error('discussionbridge_cms_invalid_request', 'The durable CMS mutation is invalid.', ['code' => $error->validationCode, 'path' => $error->path, 'route' => $route]);
        } catch (\RuntimeException) {
            return new WP_Error('discussionbridge_cms_invalid_request', 'The durable CMS mutation is invalid.');
        }
        if ($state->pending === null) {
            $state->pending = (object) ['route' => $route, 'options' => $tree, 'identity' => $identity, 'started_at' => time(), 'creator_artifacts' => CmsArtifacts::binding()];
            if (!$save()) return self::stale();
        } elseif ($state->pending->identity !== $identity) {
            return new WP_Error('discussionbridge_cms_mutation_pending', 'An earlier uncertain CMS mutation must be reconciled before another mutation.');
        }
        // A known rejected request is immutable evidence, never an uncertain request to retransmit.
        if (!CmsArtifacts::pending($state->pending)) return self::stale();
        if (isset($state->pending->rejection) || self::invalid_field_rejected($state)) return self::rejection_error($state);
        $result = $client->request($state->pending->route, (array) $state->pending->options);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if ($result->get_error_code() === 'discussionbridge_cms_rejected' && is_array($data)
                && (isset($data['preservation_rejection']) || isset($data['invalid_field_rejection']))) {
                $outcome = $data['preservation_rejection'] ?? $data['invalid_field_rejection'];
                if ($outcome->route !== $route) return self::stale();
                $state->pending->rejection = (object) ['route' => $route, 'http_status' => $outcome->http_status,
                    'error' => $outcome->error, 'request_identity' => $identity, 'artifacts' => CmsArtifacts::binding()];
                $state->response_artifacts = CmsArtifacts::binding();
                // Persist the definitive outcome BEFORE the caller's terminal marker or later native hooks.
                if (!$save()) return new WP_Error('discussionbridge_cms_rejection_persistence', 'The definitive response could not be retained. Preserve the exact journal and reconcile its storage before delivery.');
            }
        }
        if (!is_wp_error($result)) $state->response_artifacts = CmsArtifacts::binding();
        return $result;
    }

    public static function preservation_rejected(stdClass $state): bool
    {
        $pending = $state->pending ?? null; $rejection = $pending->rejection ?? null;
        return $rejection instanceof stdClass && in_array($pending->route, ['V4-02', 'V4-29'], true)
            && $rejection->route === $pending->route && $rejection->http_status === 422
            && $rejection->request_identity === $pending->identity && $rejection->error->outcome === 'rejected'
            && $rejection->error->code === 'content_preservation_unavailable' && $rejection->error->retryable === false
            && $rejection->error->retry_after_seconds === null && $rejection->error->core_fallback === false;
    }

    public static function invalid_field_rejected(stdClass $state): bool
    {
        $pending = $state->pending ?? null;
        if (!$pending instanceof stdClass || ($pending->route ?? null) !== 'V4-02' || !CmsArtifacts::pending($pending)) return false;
        $rejection = $pending->rejection ?? null;
        if ($rejection instanceof stdClass) {
            try {
                CmsArtifacts::codec()->validateResponse('V4-02', $rejection->error, $rejection->http_status);
                return $rejection->route === 'V4-02' && $rejection->http_status === 422
                    && $rejection->request_identity === $pending->identity && $rejection->error->code === 'invalid_field';
            } catch (\Throwable) { return false; }
        }
        // The shipped strict client already persisted this terminal. Missing historical Error fields stay UNKNOWN.
        return ($state->terminal->code ?? null) === 'invalid_field' && ($state->terminal->status ?? null) === 422
            && isset($state->capture->body)
            && CmsArtifacts::codec()->semanticIdentity($state->capture->body) === CmsArtifacts::codec()->semanticIdentity($pending->options->body->bridge_record);
    }

    private static function rejection_error(stdClass $state): WP_Error
    {
        if (self::invalid_field_rejected($state)) return new WP_Error('discussionbridge_cms_rejected', 'The retained source request was definitively refused; explicit native Retry is required for unchanged input.',
            ['status' => 422, 'code' => 'invalid_field', 'retryable' => false, 'retry_after_seconds' => null]);
        if (!self::preservation_rejected($state)) return new WP_Error('discussionbridge_cms_rejection_reconciliation', 'The stored definitive response needs reconciliation; do not dispatch its request.');
        $rejection = $state->pending->rejection;
        return new WP_Error('discussionbridge_cms_rejected', 'The exact current source request was definitively rejected.',
            ['status' => $rejection->http_status, 'code' => $rejection->error->code, 'retryable' => false, 'retry_after_seconds' => null,
                'preservation_rejection' => $rejection]);
    }

    /** Called in the same durable commit as the validated response's resulting local state. */
    public static function settled(stdClass $state): void { $state->pending = null; }

    private static function stale(): WP_Error { return new WP_Error('discussionbridge_stale_worker', 'This worker cannot commit its CMS mutation identity.'); }
}
