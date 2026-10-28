<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;
use WP_Post;

/** An actual native lifecycle event stops an current owned binding, not its forum discussion. */
final class CmsSourceWithdrawal
{
    public static function deletion_guard(): NativeDeletion
    {
        return new NativeDeletion(new NativeWriter(), static function (int $id): bool|WP_Error {
            $state = (new CmsJournal($id))->read();
            if (is_wp_error($state)) return $state;
            if ($state === null || isset($state->withdrawal->response)) return false;
            return $state->receipt !== null || $state->pending !== null || $state->accepted !== null || $state->capture !== null;
        }, static function (int $id, WP_Post $post, callable $guard): bool {
            return $guard() && self::capture($id, 'source_deleted', $post, false) === true && $guard();
        }, static function (int $id, WP_Post $post, callable $guard): bool {
            return $guard() && self::capture($id, 'source_deleted', $post, true) === true && $guard();
        });
    }

    public static function has_lifecycle(int $id): bool|WP_Error
    {
        $state = (new CmsJournal($id))->read();
        if (is_wp_error($state)) return $state;
        return $state !== null && (isset($state->withdrawal) || isset($state->lifecycle_attention));
    }

    public static function status_changed(string $new, string $old, WP_Post $post): void
    {
        if ($old === 'publish' && $new !== 'publish') self::capture($post->ID, 'source_unpublished', $post);
    }


    /** Local hook capture is durable before deletion; dispatch waits for the actual completed event. */
    public static function capture(int $id, string $reason, WP_Post $post, bool $confirmed = true, string $nonce = '', mixed $owner_fact = null): bool|WP_Error
    {
        if ($post->ID !== $id || wp_is_post_revision($id) || wp_is_post_autosave($id)) return false;
        if (!in_array($reason, ['source_unpublished', 'source_deleted', 'source_ineligible', 'policy_excluded'], true)) return self::error('reason');
        if ($reason === 'policy_excluded' && ((($owner_fact === null && (!current_user_can('edit_post', $id)
            || !wp_verify_nonce($nonce, 'discussionbridge_post_' . $id))) || ($owner_fact !== null && !CmsSourceRecovery::valid_exclusion($id, $owner_fact)))
            || get_post_meta($id, Publisher::ENABLED_META, true) === '1')) return self::error('exclusion_permission');
        return FromSynchronizer::serialized(function (callable $guard) use ($id, $reason, $post, $confirmed): bool|WP_Error {
            $storage = new CmsJournal($id); $state = $storage->read(); if (is_wp_error($state)) return $state;
            if ($state === null) return false;
            $save = fn(): bool => $storage->write($state, $guard);
            $fact = (object) ['native_id' => (string) $id, 'reason' => $reason, 'post_status' => $post->post_status,
                'post_type' => $post->post_type, 'confirmed' => $confirmed];
            if ($state->receipt === null) {
                if ($state->pending !== null || $state->accepted !== null || $state->capture !== null) {
                    $state->lifecycle_attention = $fact; $save(); return self::error('publication_pending');
                }
                return false;
            }
            if (!isset($state->current_record, $state->artifacts)) return self::error('binding');
            if (isset($state->withdrawal->response)) return false;
            if ($state->native_id !== (string) $id || $state->current_record->external_id !== 'post:' . Settings::site_id() . ':' . $id) return self::error('binding');
            $preserve_publication = self::closable_pending($state);
            if ($state->pending !== null && $state->pending->route !== 'V4-05' && !$preserve_publication || $state->accepted !== null || $state->capture !== null && !$preserve_publication
                || $state->scope !== CmsSourcePublisher::scope($id)) {
                $state->lifecycle_attention = $fact; $save(); return self::error('publication_pending');
            }
            clean_post_cache($id); $current = get_post($id);
            if ($reason === 'source_unpublished' && (!$current instanceof WP_Post || $current->post_status === 'publish')
                || $reason === 'source_ineligible' && (!$current instanceof WP_Post || in_array($current->post_type, Settings::post_types(), true))
                || $reason === 'source_deleted' && $confirmed && $current instanceof WP_Post) return self::error('native_event');
            if (!isset($state->withdrawal)) {
                $state->withdrawal = (object) ['fact' => $fact, 'confirmed' => $confirmed, 'attempts' => 0, 'status' => 'queued', 'accepted' => null,
                    'preserves_publication_pending' => $preserve_publication, 'pending' => null,
                    'request' => (object) ['path' => (object) ['resource_id' => $state->receipt->resource_id],
                        'body' => (object) ['mutation_id' => wp_generate_uuid4(), 'expected_source_revision' => $state->receipt->source_revision, 'reason' => $reason]]];
            } elseif ($reason === 'source_deleted' && $confirmed && $state->withdrawal->fact->reason === 'source_deleted') {
                $state->withdrawal->confirmed = true; $state->withdrawal->fact->confirmed = true;
            }
            if ($reason === 'source_deleted') {
                $state->withdrawal->native_delete ??= (object) ['captured' => true, 'confirmed' => false];
                if ($confirmed) $state->withdrawal->native_delete->confirmed = true;
                // A later native delete never rewrites the already frozen05 reason/tree.
            }
            $mutation = ($state->withdrawal->preserves_publication_pending ?? false) ? $state->withdrawal : $state;
            if ($mutation->pending === null) {
                if (!CmsArtifacts::matches($state->artifacts)) return self::error('artifact_reconciliation');
                $codec = CmsArtifacts::codec();
                $options = $state->withdrawal->request;
                try { $codec->validateRoute('V4-05', (array) $options); }
                catch (\RuntimeException) { return self::error('request'); }
                $mutation->pending = (object) ['route' => 'V4-05', 'options' => $options,
                    'identity' => $codec->semanticIdentity((object) ['route' => 'V4-05', 'options' => $options]), 'started_at' => time()];
            }
            if (!$save()) return self::error('persistence');
            if ($state->withdrawal->confirmed && !wp_next_scheduled(Publisher::DELIVERY_HOOK, [$id])) wp_schedule_single_event(time() + 1, Publisher::DELIVERY_HOOK, [$id]);
            return true;
        });
    }

    public static function step(int $id, stdClass $state, CmsJournal $storage, callable $guard): string|WP_Error
    {
        if (!isset($state->withdrawal)) return self::error('publication_pending');
        if (!CmsArtifacts::matches($state->artifacts)) return self::error('artifact_reconciliation');
        $codec = CmsArtifacts::codec();
        $client = new CmsClient($codec, 'to');
        $save = fn(): bool => $storage->write($state, fn(): bool => $guard() && $state->scope === CmsSourcePublisher::scope($id));
        if (!$guard() || $state->scope !== CmsSourcePublisher::scope($id)) return self::error('scope');
        if (isset($state->withdrawal->response)) return self::display($id, $state, $save);
        if (!$state->withdrawal->confirmed) return self::error('deletion_unconfirmed');
        $connection = $client->request('V4-01'); if (is_wp_error($connection)) return $connection;
        if ($connection->connection_id !== Settings::connection_id() || $connection->scope_revision !== $state->receiver_scope
            || $connection->profile !== 'wordpress' || !in_array('to_discourse', $connection->directions, true)
            || CmsCoordinator::origin($connection->destination_origin) !== CmsCoordinator::origin(home_url('/'))
            || Settings::lane() !== '' && $connection->publication_lane !== Settings::lane()) return self::error('scope');
        if ($state->withdrawal->accepted === null && (isset($state->withdrawal->terminal) || $state->withdrawal->attempts >= Publisher::MAX_AUTOMATIC_ATTEMPTS)) {
            $observed = $client->request('V4-04', ['path' => ['resource_id' => $state->receipt->resource_id]]);
            $state->withdrawal->observation = is_wp_error($observed) ? (object) ['error' => $observed->get_error_code()] : $observed;
            $save(); return self::error('reconciliation');
        }
        $preserve_publication = ($state->withdrawal->preserves_publication_pending ?? false) === true;
        $mutation = $preserve_publication ? $state->withdrawal : $state;
        if ($preserve_publication && $state->withdrawal->accepted === null) {
            if (!self::closable_pending($state) || !self::closed_native($id, $state, $guard)) return self::error('publication_pending');
            $observed = $client->request('V4-04', ['path' => ['resource_id' => $state->receipt->resource_id]]);
            if (is_wp_error($observed)) return $observed;
            $state->withdrawal->observation = $observed;
            if (!self::known_record($observed, $state) || !$save() || !self::closed_native($id, $state, $guard)) return self::error('publication_pending');
        } elseif ($state->pending !== null && $state->pending->route !== 'V4-05') return self::error('publication_pending');
        if ($state->withdrawal->accepted === null) {
            $state->withdrawal->attempts++; $state->withdrawal->status = 'delivering'; if (!$save()) return self::error('persistence');
            $request = $state->withdrawal->request;
            if (!$guard() || $preserve_publication && !self::closed_native($id, $state, $guard)) return self::error('native_event');
            $result = CmsMutation::perform($client, 'V4-05', ['path' => (array) $request->path, 'body' => $request->body], $mutation, $save);
            if (is_wp_error($result)) {
                $data = $result->get_error_data(); $retry = $result->get_error_code() === 'discussionbridge_cms_transport' || is_array($data) && ($data['retryable'] ?? false);
                $state->withdrawal->status = $retry && $state->withdrawal->attempts < Publisher::MAX_AUTOMATIC_ATTEMPTS ? 'retrying' : 'attention';
                if (!$retry) $state->withdrawal->terminal = (object) ['code' => is_array($data) ? ($data['code'] ?? $result->get_error_code()) : $result->get_error_code()];
                $save(); $data = is_array($data) ? $data : []; $data['native_attempts'] = $state->withdrawal->attempts;
                return new WP_Error($result->get_error_code(), $result->get_error_message(), $data);
            }
            $record = $state->current_record; $receipt = $state->receipt;
            if ($result->state !== 'withdrawn' || $result->direction !== 'to_discourse' || $result->resource_id !== $receipt->resource_id
                || $result->topic_id !== $receipt->topic_id || $result->topic_url !== $receipt->topic_url || $result->external_id !== $record->external_id
                || $result->canonical_url !== $record->canonical_url || $result->source_revision !== $receipt->source_revision
                || $result->source_created_at !== $record->source_created_at || $result->source_updated_at !== $record->source_updated_at) return self::error('receipt');
            $state->withdrawal->accepted = $result; if (!$save()) return self::error('persistence');
        }
        $state->accepted_history ??= [];
        $state->accepted_history[] = (object) ['request' => $mutation->pending, 'receipt' => $state->withdrawal->accepted,
            'record' => $state->current_record, 'artifacts' => $state->artifacts];
        $state->withdrawal->response = $state->withdrawal->accepted; $state->withdrawal->accepted = null;
        $state->withdrawal->status = 'withdrawn'; CmsMutation::settled($mutation);
        if (!$save()) return self::error('persistence');
        return self::display($id, $state, $save);
    }

    private static function display(int $id, stdClass $state, callable $save): string|WP_Error
    {
        clean_post_cache($id);
        if (get_post($id) instanceof WP_Post) foreach (['status' => 'paused', 'last_outcome' => 'withdrawn', 'last_reason' => $state->withdrawal->request->body->reason, 'to_withdrawn' => '1'] as $key => $value) {
            update_post_meta($id, '_discussionbridge_' . $key, $value); wp_cache_delete($id, 'post_meta');
            if (get_post_meta($id, '_discussionbridge_' . $key, true) !== $value) return self::error('persistence');
        }
        return $save() ? 'withdrawn' : self::error('persistence');
    }

    private static function closable_pending(stdClass $state): bool
    {
        if ($state->receipt === null || $state->accepted !== null || $state->pending?->route !== 'V4-02'
            || $state->capture === null || $state->current_record === null) return false;
        $body = $state->pending->options->body->bridge_record ?? null;
        if (!$body instanceof stdClass || $body->expected_source_revision !== $state->receipt->source_revision
            || $body->external_id !== $state->current_record->external_id || $body->canonical_url !== $state->current_record->canonical_url) return false;
        return CmsArtifacts::codec()->semanticIdentity($body) === CmsArtifacts::codec()->semanticIdentity($state->capture->body);
    }

    private static function known_record(stdClass $observed, stdClass $state): bool
    {
        return in_array($observed->state, ['active', 'withdrawn'], true) && $observed->direction === 'to_discourse'
            && $observed->resource_id === $state->receipt->resource_id && $observed->topic_id === $state->receipt->topic_id
            && $observed->topic_url === $state->receipt->topic_url && $observed->source_revision === $state->receipt->source_revision
            && $observed->external_id === $state->current_record->external_id && $observed->canonical_url === $state->current_record->canonical_url
            && $observed->source_created_at === $state->current_record->source_created_at && $observed->source_updated_at === $state->current_record->source_updated_at;
    }

    private static function closed_native(int $id, stdClass $state, callable $guard): bool
    {
        if (!$guard()) return false;
        clean_post_cache($id); $post = get_post($id);
        if (($state->withdrawal->native_delete->confirmed ?? false) && !$post instanceof WP_Post) return $guard();
        $closed = match ($state->withdrawal->fact->reason) {
            'source_deleted' => $state->withdrawal->confirmed && !$post instanceof WP_Post,
            'source_unpublished' => $post instanceof WP_Post && $post->post_status !== 'publish',
            'source_ineligible' => $post instanceof WP_Post && !in_array($post->post_type, Settings::post_types(), true),
            'policy_excluded' => $post instanceof WP_Post && get_post_meta($id, Publisher::ENABLED_META, true) !== '1',
            default => false,
        };
        return $closed && $guard();
    }

    private static function error(string $reason): WP_Error { return new WP_Error('discussionbridge_cms_to_withdrawal_' . $reason, 'Preserve the source lifecycle event and its accepted binding; withdrawal needs reconciliation.'); }
}
