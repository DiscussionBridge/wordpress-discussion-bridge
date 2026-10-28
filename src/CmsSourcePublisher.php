<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Current native source delivery inside the one Publisher and shared durable writer. */
final class CmsSourcePublisher
{
    public static function step(int $id, callable $guard): string|WP_Error
    {
        $storage = new CmsJournal($id); $state = $storage->read(); if (is_wp_error($state)) return $state;
        if ($state !== null && (isset($state->withdrawal) || isset($state->lifecycle_attention))) return CmsSourceWithdrawal::step($id, $state, $storage, $guard);
        if ($state !== null && isset($state->source_move)) return CmsSourceMove::resume($id, $state, $storage, $guard);
        $client = new CmsClient(null, 'to'); $scope = self::scope($id);
        if ($state === null) $state = (object) ['scope' => $scope, 'native_id' => (string) $id, 'receiver_scope' => null,
            'pending' => null, 'capture' => null, 'accepted' => null, 'receipt' => null,
            'inventory_complete' => false, 'inventory_cursor' => null, 'inventory_seen' => [], 'observation' => null,
            'artifacts' => CmsArtifacts::binding(), 'current_record' => null, 'accepted_history' => []];
        if (!CmsArtifacts::retained($state)) return self::error('artifact_reconciliation');
        $save = fn(): bool => $storage->write($state, fn(): bool => $guard() && hash_equals($scope, self::scope($id)));
        if ($state->scope !== $scope || $state->native_id !== (string) $id || !$guard()) return self::error('scope_reconciliation');
        $connection = $client->request('V4-01'); if (is_wp_error($connection)) return $connection;
        if ($connection->connection_id !== Settings::connection_id() || $connection->profile !== 'wordpress'
            || CmsCoordinator::origin($connection->destination_origin) !== CmsCoordinator::origin(home_url('/'))
            || !in_array('to_discourse', $connection->directions, true)
            || Settings::lane() !== '' && $connection->publication_lane !== Settings::lane()) return self::error('connection_scope');
        if ($state->receiver_scope !== null && $state->receiver_scope !== $connection->scope_revision) return self::error('scope_reconciliation');
        $state->receiver_scope = $connection->scope_revision;
        if (isset($state->terminal) && !CmsMutation::preservation_rejected($state) && !CmsMutation::invalid_field_rejected($state)) { self::observe($client, $state, $save); return self::error('terminal_reconciliation'); }
        $current = CmsSourceEdition::capture($id, $client);
        if (is_wp_error($current)) {
            if (in_array($current->get_error_code(), ['discussionbridge_native_media_preservation', 'discussionbridge_native_preparation_limitation', 'discussionbridge_native_function_preservation', 'discussionbridge_native_data_preservation'], true)) {
                $state->local_issue = (object) ['code' => $current->get_error_code(), 'native_id' => (string) $id, 'observation' => (object) $current->get_error_data()];
                if (!$save()) return self::error('state_persistence');
            }
            if ($state->pending !== null) self::observe($client, $state, $save);
            return $current;
        }
        if (isset($state->local_issue)) { unset($state->local_issue); if (!$save()) return self::error('state_persistence'); }
        $bound_url = (string) get_post_meta($id, '_discussionbridge_canonical_url', true);
        if ($bound_url !== '' && $bound_url !== $current['body']->canonical_url) return self::error('canonical_reconciliation');
        if (CmsMutation::invalid_field_rejected($state)) {
            $continued = self::continue_invalid_field($client, $state, $current, $save, $guard);
            if ($continued !== true) return $continued;
        }
        if (CmsMutation::preservation_rejected($state)) {
            $continued = self::continue_rejected($client, $state, $current, $save, $guard);
            if (is_wp_error($continued)) return $continued;
        }
        if ($state->accepted !== null) return self::commit_receipt($id, $state, $current, $save, $guard);
        if ($state->pending !== null) {
            if ($state->capture === null || $client->codec()->semanticIdentity($state->capture->proof) !== $client->codec()->semanticIdentity($current['proof'])) {
                self::observe($client, $state, $save); return self::error('changed_pending_source');
            }
        } else {
            $existing_topic = null;
            if ($state->receipt === null && get_post_meta($id, '_discussionbridge_resource_id', true) !== '') return self::error('existing_binding_reconciliation');
            if ($state->receipt === null && !$state->inventory_complete) {
                $query = new stdClass(); if ($state->inventory_cursor !== null) $query->cursor = $state->inventory_cursor;
                $page = $client->request('V4-03', ['query' => $query]); if (is_wp_error($page)) return $page;
                foreach ($page->items as $record) if ($record->direction === 'to_discourse'
                    && ($record->external_id === $current['body']->external_id || $record->canonical_url === $current['body']->canonical_url)) {
                    $state->observation = $record; if (!$save()) return self::error('state_persistence'); return self::error('existing_binding_reconciliation');
                }
                if ($page->next_cursor !== null && ($page->next_cursor === $state->inventory_cursor || in_array($page->next_cursor, $state->inventory_seen, true))) return self::error('inventory_inconsistent');
                if ($page->next_cursor !== null) $state->inventory_seen[] = $page->next_cursor;
                $state->inventory_cursor = $page->next_cursor; $state->inventory_complete = $page->next_cursor === null;
                if (!$save()) return self::error('state_persistence'); if (!$state->inventory_complete) return 'working';
            }
            if ($state->receipt !== null && isset($state->current_record) && $state->current_record !== null
                && $current['body']->source_revision === $state->receipt->source_revision) {
                $delivery = clone $state->current_record; $delivery->expected_source_revision = $state->receipt->source_revision;
            } else $delivery = CmsSourceEdition::delivery($current, $client, $state->receipt?->source_revision, $existing_topic);
            if (is_wp_error($delivery)) return $delivery;
            $state->capture = (object) ['body' => $delivery, 'proof' => $current['proof'], 'native_source' => $current['native_source'], 'creator_artifacts' => CmsArtifacts::binding()];
            if (!$save()) return self::error('state_persistence');
        }
        $body = $state->pending === null ? $state->capture->body : $state->pending->options->body->bridge_record;
        $probe = (object) ['mutation_id' => '00000000-0000-4000-8000-000000000000', 'bridge_record' => $body];
        if (strlen($client->codec()->encodeJson($probe)) > 65536) return self::error('request_metadata_too_large');
        $before_dispatch = CmsSourceEdition::capture($id, $client);
        if (is_wp_error($before_dispatch) || !$guard() || $client->codec()->semanticIdentity($before_dispatch['proof']) !== $client->codec()->semanticIdentity($state->capture->proof)) return self::error('changed_pending_source');
        if (isset($state->pending->authorized_retry) && !($state->pending->retry_budget_initialized ?? false)) {
            if (!self::meta($id, 'attempts', 0)) return self::error('state_persistence');
            $state->pending->retry_budget_initialized = true;
            if (!$save()) return self::error('state_persistence');
        }
        $attempts = get_post_meta($id, '_discussionbridge_attempt_revision', true) === $body->source_revision ? (int) get_post_meta($id, '_discussionbridge_attempts', true) + 1 : 1;
        if ($attempts > 3) return self::error('retry_limit_reached');
        foreach (['attempt_revision' => $body->source_revision, 'attempts' => $attempts, 'status' => 'delivering'] as $key => $value)
            if (!self::meta($id, $key, $value) || !$guard()) return self::error('state_persistence');
        // Capture is durable, then the exact mutation ID/tree is durable before authenticated dispatch.
        $result = CmsMutation::perform($client, 'V4-02', ['body' => (object) ['bridge_record' => $body]], $state, $save);
        if (is_wp_error($result)) {
            $data = $result->get_error_data();
            if (in_array($result->get_error_code(), ['discussionbridge_cms_rejected', 'discussionbridge_cms_reconciliation'], true) && is_array($data) && ($data['retryable'] ?? true) === false) {
                $state->terminal = (object) ['code' => $data['code'], 'status' => $data['status']];
                if (!$save()) return self::error('state_persistence');
            }
            return $result;
        }
        if (!in_array($result->outcome, ['created', 'resolved', 'updated'], true) || $result->direction !== 'to_discourse'
            || $result->source_revision !== $body->source_revision || !Settings::topic_url_matches($result->topic_url, $result->topic_id)) return self::error('invalid_receipt');
        $old_resource = $state->receipt?->resource_id ?? ((string) get_post_meta($id, '_discussionbridge_resource_id', true));
        $old_topic = $state->receipt?->topic_id ?? ((string) get_post_meta($id, '_discussionbridge_topic_id', true));
        if ($old_resource !== '' && $old_resource !== $result->resource_id || $old_topic !== '' && $old_topic !== $result->topic_id) return self::error('identity_reconciliation');
        $state->accepted = $result;
        if (!$save()) return self::error('accepted_receipt_persistence');
        $after = CmsSourceEdition::capture($id, $client);
        if (is_wp_error($after)) return self::error('accepted_source_identity');
        return self::commit_receipt($id, $state, $after, $save, $guard);
    }

    /** Existing native operator entry. No HTTP or mutation UUID is created by this authorization. */
    public static function authorize_retry(int $id, string $identity, int $actor, callable $guard): bool|WP_Error
    {
        $storage = new CmsJournal($id); $state = $storage->read();
        if (!$state instanceof stdClass || !CmsArtifacts::retained($state) || !CmsMutation::invalid_field_rejected($state)
            || !hash_equals($state->pending->identity, $identity) || $actor <= 0 || $state->scope !== self::scope($id)
            || $state->accepted !== null || isset($state->withdrawal) || isset($state->source_move) || isset($state->lifecycle_attention) || !$guard()) return self::error('retry_reconciliation');
        $client = new CmsClient(null, 'to'); $current = CmsSourceEdition::capture($id, $client);
        if (is_wp_error($current) || $client->codec()->semanticIdentity($current['proof']) !== $client->codec()->semanticIdentity($state->capture->proof)) return self::error('changed_pending_source');
        if (isset($state->retry_action)) return $state->retry_action->request_identity === $identity ? true : self::error('retry_reconciliation');
        $state->retry_action = (object) ['kind' => 'native_operator_retry', 'actor_id' => (string) $actor,
            'request_identity' => $identity, 'authorized_at' => time(), 'source_proof' => $current['proof']];
        return $storage->write($state, fn(): bool => $guard() && $state->scope === self::scope($id)) ? true : self::error('state_persistence');
    }

    private static function continue_invalid_field(CmsClient $client, stdClass $state, array $current, callable $save, callable $guard): bool|string|WP_Error
    {
        $old = $state->pending; $record = $old->options->body->bridge_record;
        if ($state->accepted !== null || $state->capture === null || $record->expected_source_revision !== ($state->receipt?->source_revision)
            || $current['body']->external_id !== $record->external_id || $current['body']->canonical_url !== $record->canonical_url) return self::error('rejected_request_reconciliation');
        $changed = $current['body']->source_revision !== $record->source_revision;
        $action = $state->retry_action ?? null;
        if (!$changed && (!$action instanceof stdClass || $action->request_identity !== $old->identity
            || $client->codec()->semanticIdentity($action->source_proof) !== $client->codec()->semanticIdentity($current['proof']))) return self::error('invalid_field_refusal_retained');
        if ($state->receipt !== null) {
            $observed = $client->request('V4-04', ['path' => ['resource_id' => $state->receipt->resource_id]]);
            $state->observation = is_wp_error($observed) ? (object) ['error' => $observed->get_error_code()] : $observed;
            if (!$save()) return self::error('state_persistence');
            $accepted = $state->current_record;
            if (is_wp_error($observed) || !$accepted instanceof stdClass || $observed->state !== 'active' || $observed->direction !== 'to_discourse'
                || $observed->resource_id !== $state->receipt->resource_id || $observed->topic_id !== $state->receipt->topic_id
                || $observed->topic_url !== $state->receipt->topic_url || $observed->source_revision !== $state->receipt->source_revision
                || $observed->external_id !== $accepted->external_id || $observed->canonical_url !== $accepted->canonical_url
                || $observed->source_created_at !== $accepted->source_created_at || $observed->source_updated_at !== $accepted->source_updated_at) return self::error('rejected_predecessor_reconciliation');
        } else {
            if (get_post_meta((int) $state->native_id, '_discussionbridge_resource_id', true) !== '') return self::error('existing_binding_reconciliation');
            if (!isset($state->refusal_scan) || $state->refusal_scan->request_identity !== $old->identity) {
                $state->refusal_scan = (object) ['request_identity' => $old->identity, 'cursor' => null, 'seen' => [], 'complete' => false];
                if (!$save()) return self::error('state_persistence');
            }
            $scan = $state->refusal_scan;
            if (!$scan->complete) {
                $query = new stdClass(); if ($scan->cursor !== null) $query->cursor = $scan->cursor;
                $page = $client->request('V4-03', ['query' => $query]); if (is_wp_error($page)) return $page;
                foreach ($page->items as $item) if ($item->direction === 'to_discourse'
                    && ($item->external_id === $record->external_id || $item->canonical_url === $record->canonical_url)) {
                    $state->observation = $item; if (!$save()) return self::error('state_persistence'); return self::error('existing_binding_reconciliation');
                }
                if ($page->next_cursor !== null && ($page->next_cursor === $scan->cursor || in_array($page->next_cursor, $scan->seen, true))) return self::error('inventory_inconsistent');
                if ($page->next_cursor !== null) $scan->seen[] = $page->next_cursor;
                $scan->cursor = $page->next_cursor; $scan->complete = $page->next_cursor === null;
                if (!$save()) return self::error('state_persistence'); if (!$scan->complete) return 'working';
            }
        }
        $latest = CmsSourceEdition::capture((int) $state->native_id, $client);
        if (is_wp_error($latest) || !$guard() || $client->codec()->semanticIdentity($latest['proof']) !== $client->codec()->semanticIdentity($current['proof'])) return self::error('changed_pending_source');
        $capture = $state->capture;
        if ($changed) {
            $delivery = CmsSourceEdition::delivery($current, $client, $state->receipt?->source_revision);
            if (is_wp_error($delivery)) return $delivery;
            $capture = (object) ['body' => $delivery, 'proof' => $current['proof'], 'native_source' => $current['native_source'], 'creator_artifacts' => CmsArtifacts::binding()];
        }
        $fresh = CmsMutation::prepare($client, 'V4-02', ['body' => (object) ['bridge_record' => $capture->body]]);
        if (is_wp_error($fresh)) return $fresh;
        if (!$changed) { $fresh->authorized_retry = $action; $fresh->retry_budget_initialized = false; }
        $fresh->replaces_refused_identity = $old->identity;
        $state->rejected_history ??= [];
        $state->rejected_history[] = (object) array_merge(['request' => $old, 'capture' => $state->capture,
            'terminal' => $state->terminal ?? null, 'receipt' => $state->receipt, 'current_record' => $state->current_record,
            'scope' => $state->scope, 'receiver_scope' => $state->receiver_scope,
            'refusal_evidence' => $old->rejection ?? (object) ['code' => 'invalid_field', 'http_status' => 422,
                'decoded_error_known' => false, 'unknown_fields' => ['raw_error', 'correlation_id', 'unretained_decoded_fields']],
            'continuation' => $changed ? 'ordinary_changed_edition' : 'explicit_native_retry', 'operator_action' => $changed ? null : $action,
            'replacement_identity' => $fresh->identity, 'replacement_mutation_id' => $fresh->options->body->mutation_id], CmsArtifacts::archive($state, $old));
        $state->pending = $fresh; $state->capture = $capture;
        unset($state->terminal, $state->retry_action, $state->refusal_scan, $state->response_artifacts);
        // One commit retains the refusal AND its one replacement intent before any HTTP.
        return $save() ? true : self::error('state_persistence');
    }

    /** A genuinely different current native snapshot is ordinary source work, not a retry of the rejected tree. */
    private static function continue_rejected(CmsClient $client, stdClass $state, array $current, callable $save, callable $guard): bool|WP_Error
    {
        $pending = $state->pending; $old = $pending->options->body->bridge_record ?? null;
        if ($pending->route !== 'V4-02' || !$old instanceof stdClass || $state->capture === null || $state->accepted !== null
            || $client->codec()->semanticIdentity($old) !== $client->codec()->semanticIdentity($state->capture->body)
            || $old->expected_source_revision !== ($state->receipt?->source_revision)) return self::error('rejected_request_reconciliation');
        if ($current['body']->external_id !== $old->external_id || $current['body']->canonical_url !== $old->canonical_url) return self::error('canonical_reconciliation');
        if ($current['body']->source_revision === $old->source_revision) {
            self::observe($client, $state, $save);
            return self::error('preservation_rejection_retained');
        }
        if ($state->receipt !== null) {
            $record = $client->request('V4-04', ['path' => ['resource_id' => $state->receipt->resource_id]]);
            $state->observation = is_wp_error($record) ? (object) ['error' => $record->get_error_code()] : $record;
            if (!$save()) return self::error('state_persistence');
            $accepted = $state->current_record;
            if (is_wp_error($record) || !$accepted instanceof stdClass || $record->state !== 'active' || $record->direction !== 'to_discourse'
                || $record->resource_id !== $state->receipt->resource_id || $record->topic_id !== $state->receipt->topic_id
                || $record->topic_url !== $state->receipt->topic_url || $record->source_revision !== $state->receipt->source_revision
                || $record->external_id !== $accepted->external_id || $record->canonical_url !== $accepted->canonical_url
                || $record->source_created_at !== $accepted->source_created_at || $record->source_updated_at !== $accepted->source_updated_at) return self::error('rejected_predecessor_reconciliation');
        } elseif (get_post_meta((int) $state->native_id, '_discussionbridge_resource_id', true) !== '') return self::error('existing_binding_reconciliation');
        if (!$guard()) return self::error('scope_reconciliation');
        // Keep the exact rejected UUID/tree/capture/outcome and prior accepted identity unchanged in history.
        $state->rejected_history ??= [];
        $state->rejected_history[] = (object) array_merge(['request' => $pending, 'capture' => $state->capture, 'receipt' => $state->receipt,
            'current_record' => $state->current_record, 'scope' => $state->scope, 'receiver_scope' => $state->receiver_scope], CmsArtifacts::archive($state, $pending));
        $state->pending = null; $state->capture = null; unset($state->terminal);
        if ($state->receipt === null) { $state->inventory_complete = false; $state->inventory_cursor = null; $state->inventory_seen = []; }
        return $save() ? true : self::error('state_persistence');
    }

    private static function commit_receipt(int $id, stdClass $state, array $current, callable $save, callable $guard): string|WP_Error
    {
        $result = $state->accepted; $body = $state->capture->body;
        if (!$guard() || $current['body']->external_id !== $body->external_id || $current['body']->canonical_url !== $body->canonical_url) return self::error('accepted_source_identity');
        $metadata = ['resource_id' => $result->resource_id, 'topic_id' => $result->topic_id, 'topic_url' => $result->topic_url,
            'canonical_url' => $body->canonical_url, 'source_revision' => $result->source_revision, 'source_created_at' => $body->source_created_at ?? '',
            'source_updated_at' => $body->source_updated_at ?? '', 'source_content_sha256' => $body->source_content_sha256,
            'source_content_bytes' => $body->source_content_bytes, 'synchronized_at' => $result->synchronized_at,
            'last_outcome' => $result->outcome, 'last_reason' => $result->reason, 'status' => 'healthy', 'attempts' => 0];
        foreach ($metadata as $key => $value) if (!$guard() || !self::meta($id, $key, $value)) return self::error('accepted_receipt_persistence');
        if (!CmsSourceOwnership::bind($id, $body, $result, $state->capture->native_source)) return self::error('accepted_receipt_persistence');
        $state->accepted_history ??= [];
        $state->accepted_history[] = (object) array_merge(['request' => $state->pending, 'receipt' => $result, 'record' => $body,
            'native_source' => $state->capture->native_source ?? null], CmsArtifacts::archive($state, $state->pending));
        $state->receipt = $result; $state->current_record = $body;
        $state->current_native_source = $state->capture->native_source ?? null;
        $state->accepted = null; $state->capture = null; CmsMutation::settled($state);
        if (!$save()) return self::error('accepted_receipt_persistence');
        return $current['body']->source_revision === $body->source_revision ? 'healthy' : 'working';
    }

    private static function observe(CmsClient $client, stdClass $state, callable $save): void
    {
        $resource = $state->receipt?->resource_id ?? ((string) get_post_meta((int) $state->native_id, '_discussionbridge_resource_id', true));
        if (is_string($resource) && wp_is_uuid($resource)) $state->observation = $client->request('V4-04', ['path' => ['resource_id' => $resource]]);
        if ($state->observation instanceof WP_Error) $state->observation = (object) ['error' => $state->observation->get_error_code()];
        $save(); // Observation is not a reconstructed accepted mutation/receipt.
    }

    private static function meta(int $id, string $key, mixed $value): bool
    {
        update_post_meta($id, '_discussionbridge_' . $key, wp_slash($value)); wp_cache_delete($id, 'post_meta');
        return metadata_exists('post', $id, '_discussionbridge_' . $key) && (string) get_post_meta($id, '_discussionbridge_' . $key, true) === (string) $value;
    }

    public static function scope(int $id): string
    {
        return hash('sha256', (string) wp_json_encode([home_url('/'), Settings::site_id(), Settings::server_url(), Settings::connection_id(),
            hash('sha256', Settings::connection_secret()), Settings::lane(), Settings::post_types(), $id]));
    }

    private static function error(string $reason): WP_Error { return new WP_Error('discussionbridge_cms_to_' . $reason, 'The native source binding needs attention; preserve its exact source request and accepted predecessor.'); }
}
