<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;
use WP_Post;

/** Source URL intent/replay shares the existing source publisher journal and writer. */
final class CmsSourceMove
{
    /** Existing protected native-owner API, with actual supported native planning before reservation. */
    public static function prepare(int $id, string $new_url, string $nonce): string|WP_Error
    {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $id)
            || !wp_verify_nonce($nonce, 'discussionbridge_source_move_' . $id)) return self::error('permission');
        return FromSynchronizer::serialized(function (callable $guard) use ($id, $new_url): string|WP_Error {
            $storage = new CmsJournal($id); $state = $storage->read(); if (is_wp_error($state)) return $state;
            if ($state === null || $state->receipt === null || !isset($state->current_record, $state->artifacts)
                || $state->scope !== CmsSourcePublisher::scope($id) || isset($state->withdrawal) || isset($state->lifecycle_attention)) return self::error('binding');
            if (isset($state->source_move)) return $state->source_move->request->body->new_url === $new_url ? 'working' : self::error('pending');
            if ($state->pending !== null || $state->accepted !== null || $state->capture !== null || isset($state->terminal)) return self::error('pending');
            if (!CmsArtifacts::matches($state->artifacts)) return self::error('artifacts');
            $client = new CmsClient(null, 'to'); $current = CmsSourceEdition::capture($id, $client); if (is_wp_error($current)) return $current;
            $record = $state->current_record;
            if ($current['body']->source_revision !== $state->receipt->source_revision || $current['body']->canonical_url !== $record->canonical_url
                || $current['body']->external_id !== $record->external_id || $current['body']->source_content_sha256 !== $record->source_content_sha256) return self::error('native_changed');
            $ownership = CmsSourceOwnership::observe($id, $state); if (is_wp_error($ownership)) return $ownership;
            $plan = CmsSourceNativeMove::plan($id, $new_url, $ownership); if (is_wp_error($plan)) return $plan;
            try {
                $client->codec()->validate('Url', $new_url);
                if ($new_url === $record->canonical_url || CmsCoordinator::origin($new_url) !== CmsCoordinator::origin($record->canonical_url)) return self::error('origin');
                if (!function_exists('url_to_postid')) return self::error('native_collision_check');
                $owner = url_to_postid($new_url); if ($owner !== 0 && $owner !== $id) return self::error('native_collision');
                $projection = clone $record; $projection->canonical_url = $new_url;
                if ($record->representation->kind === 'generated_excerpt') {
                    $projection->representation = clone $record->representation; $projection->representation->read_more_url = $new_url;
                    $projection->content_html = substr($record->content_html, 0, $record->representation->article_content_bytes) . $client->codec()->generatedReadMore($new_url);
                    $client->codec()->validateGeneratedEnvelope($projection);
                } elseif ($record->representation->kind === 'full') {
                    $client->codec()->validate('ToRecord', $projection);
                    if (strlen($client->codec()->encodeJson((object) ['mutation_id' => wp_generate_uuid4(), 'bridge_record' => $projection])) > 65536) return self::error('delivery_cap');
                } else return self::error('unowned_excerpt');
                // Measurement only: native modified time may become a declared 24-byte Instant instead of null.
                // No timestamp is fabricated or assigned to the source by this preflight.
                $size = strlen($client->codec()->encodeJson((object) ['mutation_id' => '00000000-0000-4000-8000-000000000000', 'bridge_record' => $projection]));
                if ($size + ($record->source_updated_at === null ? 22 : 0) > 65536) return self::error('delivery_cap');
                $request = (object) ['path' => (object) ['resource_id' => $state->receipt->resource_id], 'body' => (object) [
                    'mutation_id' => wp_generate_uuid4(), 'expected_source_revision' => $state->receipt->source_revision,
                    'old_url' => $record->canonical_url, 'new_url' => $new_url, 'external_id' => $record->external_id]];
                $client->codec()->validateRoute('V4-28', (array) $request);
            } catch (\RuntimeException) { return self::error('invalid_intent'); }
            $state->source_move = (object) ['request' => $request, 'native_proof' => $current['proof'], 'artifacts' => $state->artifacts,
                'accepted_record' => $record, 'accepted_receipt' => $state->receipt, 'receipt' => null, 'attempts' => 0,
                'stage' => 'prepare_pending', 'native_operation' => 'not_performed', 'history' => null, 'native_plan' => $plan,
                'phase_attempts' => (object) ['prepare' => 0, 'finalize' => 0, 'abort' => 0]];
            $state->pending = (object) ['route' => 'V4-28', 'options' => $request,
                'identity' => $client->codec()->semanticIdentity((object) ['route' => 'V4-28', 'options' => $request]), 'started_at' => time()];
            if (!$storage->write($state, fn(): bool => $guard() && $state->scope === CmsSourcePublisher::scope($id))) return self::error('persistence');
            Publisher::queue($id, false); return 'working';
        });
    }

    public static function resume(int $id, stdClass $state, CmsJournal $storage, callable $guard): string|WP_Error
    {
        $move = $state->source_move;
        if (!$guard() || $state->scope !== CmsSourcePublisher::scope($id)) return self::error('scope');
        if (!CmsArtifacts::matches($move->artifacts)) return self::error('artifacts');
        $client = new CmsClient(null, 'to');
        $save = fn(): bool => $storage->write($state, fn(): bool => $guard() && $state->scope === CmsSourcePublisher::scope($id));
        if (!isset($move->native_plan, $move->phase_attempts)) return self::error('complete_native_receipt_unavailable');
        $connection = $client->request('V4-01'); if (is_wp_error($connection)) return $connection;
        if ($connection->connection_id !== Settings::connection_id() || $connection->scope_revision !== $state->receiver_scope
            || $connection->profile !== 'wordpress' || !in_array('to_discourse', $connection->directions, true)
            || CmsCoordinator::origin($connection->destination_origin) !== CmsCoordinator::origin(home_url('/'))
            || Settings::lane() !== '' && $connection->publication_lane !== Settings::lane()) return self::error('scope');
        if (isset($move->terminal) || CmsMutation::preservation_rejected($state)) return self::error('reconciliation');
        if ($move->stage === 'prepare_pending') {
            if (!self::before($id, $state)) return self::error('native_changed');
            $result = self::dispatch($client, 'V4-28', $move->request, 'prepare', $state, $save);
            if (is_wp_error($result)) return $result;
            $body = $move->request->body;
            if ($result->state !== 'prepared' || $result->resource_id !== $state->receipt->resource_id || $result->expected_source_revision !== $body->expected_source_revision
                || $result->old_url !== $body->old_url || $result->new_url !== $body->new_url) return self::error('receipt');
            $move->receipt = $result; $move->accepted_request = $state->pending; $move->stage = 'awaiting_actual_native_render';
            CmsMutation::settled($state); if (!$save()) return self::error('persistence');
            return 'awaiting_actual_native_render';
        }
        if ($move->stage === 'awaiting_actual_native_render') return 'awaiting_actual_native_render';
        if ($move->stage === 'abort_pending') return self::finish_abort($id, $client, $state, $save);
        if ($move->stage === 'native_commit_pending' || $move->stage === 'native_commit_uncertain') return self::error('native_commit_reconciliation');
        if ($move->stage === 'native_not_applied') return self::error('native_not_applied');
        if ($move->stage === 'render_expected_captured') {
            if (!self::before($id, $state) || !isset($move->expected_native)
                || $move->expected_native->before_receipt_sha256 !== CmsSourceOwnership::digest($move->native_plan->before)) return self::error('native_changed');
            $move->stage = 'native_commit_pending'; if (!$save()) return self::error('persistence');
            $applied = CmsSourceNativeMove::apply($id, $state, $save, $guard);
            if (is_wp_error($applied)) {
                $data = $applied->get_error_data(); $rolled_back = is_array($data) && ($data['rollback_proven'] ?? false) === true;
                $move->native_operation = $rolled_back ? 'not_applied' : 'uncertain';
                $move->stage = $rolled_back ? 'native_not_applied' : 'native_commit_uncertain';
                $move->native_issue = (object) ['code' => $applied->get_error_code(), 'rollback_proven' => $rolled_back]; $save();
                return $applied;
            }
        }
        if (isset($move->finalize_response)) return self::commit($id, $client, $state, $save, $guard);
        if (!in_array($move->stage, ['native_applied', 'public_verified', 'finalize_pending'], true) || !self::after($id, $state)) return self::error('native_changed');
        if (!isset($move->finalize_request)) {
            $verification = CmsSourcePublicVerifier::verify($id, $state, $guard); if (is_wp_error($verification)) return $verification;
            $move->verification = $verification;
            $move->finalize_request = (object) ['path' => (object) ['resource_id' => $state->receipt->resource_id, 'move_id' => $move->receipt->move_id],
                'body' => (object) ['mutation_id' => wp_generate_uuid4(), 'move_id' => $move->receipt->move_id, 'proof_nonce' => $move->receipt->proof_nonce,
                    'expected_source_revision' => $move->accepted_receipt->source_revision, 'verification' => $verification,
                    'new_source_revision' => $move->new_source_revision, 'source_updated_at' => $move->after_source->semantic_source->source_updated_at]];
            try { $client->codec()->validateRoute('V4-29', (array) $move->finalize_request); } catch (\RuntimeException) { return self::error('invalid_finalize'); }
            $move->stage = 'finalize_pending'; if (!$save()) return self::error('persistence');
        }
        $result = self::dispatch($client, 'V4-29', $move->finalize_request, 'finalize', $state, $save);
        if (is_wp_error($result)) return $result;
        if ($result->state !== 'active' || $result->direction !== 'to_discourse' || $result->resource_id !== $move->accepted_receipt->resource_id
            || $result->topic_id !== $move->accepted_receipt->topic_id || $result->topic_url !== $move->accepted_receipt->topic_url
            || $result->source_revision !== $move->new_source_revision || $result->canonical_url !== $move->request->body->new_url
            || $result->external_id !== $move->accepted_record->external_id || $result->source_created_at !== $move->accepted_record->source_created_at
            || $result->source_updated_at !== $move->after_source->semantic_source->source_updated_at) return self::error('finalize_receipt');
        $move->finalize_response = $result; if (!$save()) return self::error('persistence');
        return self::commit($id, $client, $state, $save, $guard);
    }

    private static function dispatch(CmsClient $client, string $route, stdClass $options, string $phase, stdClass $state, callable $save): mixed
    {
        $move = $state->source_move;
        if ($move->phase_attempts->$phase >= Publisher::MAX_AUTOMATIC_ATTEMPTS || isset($move->terminal)) return self::error('reconciliation');
        $move->phase_attempts->$phase++; if (!$save()) return self::error('persistence');
        $result = CmsMutation::perform($client, $route, (array) $options, $state, $save);
        if (!is_wp_error($result)) return $result;
        $data = $result->get_error_data();
        if ($result->get_error_code() === 'discussionbridge_cms_rejected' && is_array($data) && ($data['retryable'] ?? true) === false) {
            $move->terminal = (object) ['route' => $route, 'code' => $data['code'], 'status' => $data['status']]; $save();
        }
        $data = is_array($data) ? $data : []; $data['native_attempts'] = $move->phase_attempts->$phase;
        return new WP_Error($result->get_error_code(), $result->get_error_message(), $data);
    }

    private static function before(int $id, stdClass $state): bool
    {
        $current = CmsSourceOwnership::observe($id, $state);
        return !is_wp_error($current) && CmsSourceOwnership::digest($current) === CmsSourceOwnership::digest($state->source_move->native_plan->before);
    }

    /** Keep the last accepted discussion visible while its source-only URL operation is queued/held. */
    public static function presentation_binding(int $id): bool
    {
        $state = (new CmsJournal($id))->read(); $metadata = CmsSourceOwnership::metadata($id);
        return $state instanceof stdClass && isset($state->source_move) && !isset($state->withdrawal) && !isset($state->lifecycle_attention)
            && $metadata !== null && CmsArtifacts::matches($state->artifacts) && $state->scope === CmsSourcePublisher::scope($id)
            && $state->receipt !== null && $state->receipt->resource_id === $metadata['receipt']->resource_id
            && $state->receipt->topic_id === $metadata['receipt']->topic_id
            && get_post_meta($id, Publisher::ENABLED_META, true) === '1' && get_post_meta($id, Materializer::IMPORTED_META, true) !== '1';
    }

    /** Existing positive native discovery may restore a lost scheduling event, never replay a known rejection. */
    public static function recoverable(stdClass $state): bool
    {
        $move = $state->source_move ?? null;
        if (!$move instanceof stdClass || !isset($move->native_plan, $move->phase_attempts) || isset($move->terminal)
            || CmsMutation::preservation_rejected($state)) return false;
        return match ($move->stage) {
            'prepare_pending' => $move->phase_attempts->prepare < Publisher::MAX_AUTOMATIC_ATTEMPTS,
            'render_expected_captured', 'native_applied', 'public_verified' => true,
            'finalize_pending' => $move->phase_attempts->finalize < Publisher::MAX_AUTOMATIC_ATTEMPTS,
            'abort_pending' => $move->phase_attempts->abort < Publisher::MAX_AUTOMATIC_ATTEMPTS,
            default => false,
        };
    }

    private static function after(int $id, stdClass $state): bool
    {
        $row = CmsSourceNativeMove::row($id); $move = $state->source_move;
        return !is_wp_error($row) && isset($move->after_row) && get_object_vars($row) == get_object_vars($move->after_row)
            && get_permalink($id) === $move->request->body->new_url;
    }

    /** Truthful protected native abort; an applied/uncertain operation never frees reservations. */
    public static function abort(int $id, string $nonce): string|WP_Error
    {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $id) || !wp_verify_nonce($nonce, 'discussionbridge_source_move_' . $id)) return self::error('permission');
        return FromSynchronizer::serialized(function (callable $guard) use ($id): string|WP_Error {
            $storage = new CmsJournal($id); $state = $storage->read();
            if (!$state instanceof stdClass || !isset($state->source_move->receipt) || $state->pending !== null
                || !CmsArtifacts::matches($state->artifacts) || $state->scope !== CmsSourcePublisher::scope($id)) return self::error('abort_binding');
            $move = $state->source_move;
            if (!in_array($move->stage, ['awaiting_actual_native_render', 'render_expected_captured', 'native_not_applied'], true)
                || !in_array($move->native_operation, ['not_performed', 'not_applied'], true) || !self::before($id, $state)) return self::error('abort_applied_or_uncertain');
            $move->abort_request = (object) ['path' => (object) ['resource_id' => $state->receipt->resource_id, 'move_id' => $move->receipt->move_id], 'body' => (object) [
                'mutation_id' => wp_generate_uuid4(), 'move_id' => $move->receipt->move_id, 'expected_source_revision' => $state->receipt->source_revision,
                'native_change_applied' => false, 'old_url' => $move->request->body->old_url, 'native_ownership_receipt_sha256' => CmsSourceOwnership::digest($move->native_plan->before)]];
            $move->stage = 'abort_pending'; if (!$storage->write($state, $guard)) return self::error('persistence');
            return self::resume($id, $state, $storage, $guard);
        });
    }

    private static function finish_abort(int $id, CmsClient $client, stdClass $state, callable $save): string|WP_Error
    {
        if (!self::before($id, $state) || !in_array($state->source_move->native_operation, ['not_performed', 'not_applied'], true)) return self::error('abort_applied_or_uncertain');
        $result = self::dispatch($client, 'V4-30', $state->source_move->abort_request, 'abort', $state, $save);
        if (is_wp_error($result)) return $result;
        $move = $state->source_move;
        if ($result->state !== 'aborted' || $result->move_id !== $move->receipt->move_id || $result->resource_id !== $state->receipt->resource_id
            || $result->old_url !== $move->receipt->old_url || $result->new_url !== $move->receipt->new_url || $result->expected_source_revision !== $state->receipt->source_revision
            || $result->proof_nonce !== $move->receipt->proof_nonce || $result->prepared_at !== $move->receipt->prepared_at) return self::error('abort_receipt');
        $move->abort_response = $result; $move->abort_mutation = $state->pending; $state->source_move_history ??= []; $state->source_move_history[] = $move;
        unset($state->source_move); CmsMutation::settled($state);
        return $save() ? 'aborted' : self::error('persistence');
    }

    private static function commit(int $id, CmsClient $client, stdClass $state, callable $save, callable $guard): string|WP_Error
    {
        global $wpdb; $move = $state->source_move; $committed = false;
        if (!self::after($id, $state) || !$guard() || !CmsNativeFence::transactional_tables()) return self::error('native_changed');
        try {
            if ($wpdb->query('START TRANSACTION') === false || !CmsNativeFence::begin(true)) return self::error('storage');
            $locked = $wpdb->get_row($wpdb->prepare('SELECT * FROM `' . str_replace('`', '``', $wpdb->posts) . '` WHERE ID = %s FOR UPDATE', (string) $id));
            if (!is_object($locked) || get_object_vars($locked) != get_object_vars($move->after_row) || !CmsNativeFence::storage_alive()) return self::error('native_changed');
            $record = clone $move->accepted_record;
            if ($record->representation->kind === 'generated_excerpt') $record = $client->codec()->projectGeneratedReadMoreMove($record, $move->request->body->new_url, $move->new_source_revision, $move->after_source->semantic_source->source_updated_at);
            else { $record->canonical_url = $move->request->body->new_url; $record->source_revision = $move->new_source_revision;
                $record->expected_source_revision = $move->accepted_receipt->source_revision; $record->source_updated_at = $move->after_source->semantic_source->source_updated_at; $client->codec()->validateGeneratedEnvelope($record); }
            foreach (['canonical_url' => $record->canonical_url, 'source_revision' => $record->source_revision,
                'source_updated_at' => $record->source_updated_at ?? '', 'synchronized_at' => $move->finalize_response->synchronized_at, 'status' => 'healthy', 'attempts' => 0] as $key => $value) {
                update_post_meta($id, '_discussionbridge_' . $key, wp_slash($value)); wp_cache_delete($id, 'post_meta');
                if ((string) get_post_meta($id, '_discussionbridge_' . $key, true) !== (string) $value || !$guard()) return self::error('metadata');
            }
            if (!CmsSourceOwnership::bind($id, $record, $move->finalize_response, $move->after_source)) return self::error('ownership');
            $move->finalize_mutation = $state->pending; $state->source_move_history ??= []; $state->source_move_history[] = $move;
            $state->accepted_history[] = (object) ['kind' => 'source_url_move', 'request' => $state->pending, 'receipt' => $move->finalize_response,
                'record' => $record, 'native_source' => $move->after_source, 'artifacts' => $state->artifacts];
            $state->receipt = $move->finalize_response; $state->current_record = $record; $state->current_native_source = $move->after_source;
            $state->source_ancestry = $move->ancestry; unset($state->source_move); CmsMutation::settled($state);
            if (!$save() || !$guard() || !CmsNativeFence::commit_transaction()) return self::error('commit');
            $committed = true; return 'healthy';
        } catch (\Throwable) { return self::error('commit'); }
        finally {
            if (!$committed) CmsNativeFence::rollback_transaction(); CmsNativeFence::finish($committed);
            clean_post_cache($id); wp_cache_delete((new CmsJournal($id))->option_name(), 'options');
        }
    }

    public static function history(int $id, ?string $cursor = null): stdClass|WP_Error
    {
        if (!current_user_can('manage_options') || get_post($id) instanceof WP_Post && !current_user_can('edit_post', $id)) return self::error('permission');
        return FromSynchronizer::serialized(function (callable $guard) use ($id, $cursor): stdClass|WP_Error {
            $storage = new CmsJournal($id); $state = $storage->read(); if (is_wp_error($state)) return $state;
            if ($state === null || $state->receipt === null || $state->scope !== CmsSourcePublisher::scope($id)) return self::error('binding');
            if (!CmsArtifacts::matches($state->artifacts)) return self::error('artifacts');
            $client = new CmsClient(null, 'to'); $query = new stdClass(); if ($cursor !== null) $query->cursor = $cursor;
            $page = $client->request('V4-31', ['path' => ['resource_id' => $state->receipt->resource_id], 'query' => $query]);
            if (is_wp_error($page)) return $page;
            $state->url_history_observation = $page;
            return $storage->write($state, fn(): bool => $guard() && $state->scope === CmsSourcePublisher::scope($id)) ? $page : self::error('persistence');
        });
    }

    private static function error(string $reason): WP_Error { return new WP_Error('discussionbridge_cms_to_source_move_' . $reason,
        'Retain the source URL intent and reservations. Native source ownership and actual public nonce verification must be established before finalize or abort.'); }
}
