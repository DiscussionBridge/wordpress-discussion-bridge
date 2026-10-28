<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use DiscussionBridge\CmsV4\NoContent;
use stdClass;
use WP_Error;

/** Bounded native From coordinator. Receiver-created work remains the sole publication authority. */
final class CmsCoordinator
{
    public static function fresh(string $scope): stdClass
    {
        return (object) ['scope' => $scope, 'receiver_scope' => null, 'sources' => CmsSourceContinuation::fresh(), 'jobs' => [],
            'mappings' => new stdClass(), 'mapping_cursor' => null, 'mapping_complete' => false, 'fences' => new stdClass(),
            'pending' => null, 'lease' => null, 'lease_deadline' => 0, 'attention' => null, 'catalog' => null,
            'retired' => [], 'attention_jobs' => new stdClass(), 'local_issues' => new stdClass(), 'source_records' => new stdClass(),
            'recovery_cursor' => 0, 'artifacts' => CmsArtifacts::binding()];
    }

    public static function step(string $scope, callable $guard): string|WP_Error
    {
        $client = new CmsClient(); $storage = new CmsJournal(); $state = $storage->read();
        if (is_wp_error($state)) return $state;
        if ($state === null) $state = self::fresh($scope);
        $save = fn(): bool => $storage->write($state, $guard);
        if (!isset($state->artifacts) || !CmsArtifacts::matches($state->artifacts)) {
            if (!isset($state->artifacts) || !CmsArtifacts::upgrade_from_r5($state, $save)) return self::error('artifact_reconciliation');
        }
        $connection = $client->request('V4-01'); if (is_wp_error($connection)) return $connection;
        $policy = $client->request('V4-08'); if (is_wp_error($policy)) return $policy;
        if (!$guard()) return self::error('stale_worker');
        $origin = self::origin(home_url('/'));
        if ($connection->connection_id !== Settings::connection_id() || $connection->profile !== 'wordpress'
            || self::origin($connection->destination_origin) !== $origin || $connection->publication_lane !== Settings::lane()
            || !in_array('from_discourse', $connection->directions, true)
            || $client->codec()->semanticIdentity($connection->policy) !== $client->codec()->semanticIdentity($policy)) return self::error('connection_scope');
        if (!$policy->enabled) return 'disabled'; // Catalog/default selection never enables receiver policy.
        $receiver_scope = $connection->scope_revision . "\0" . $policy->policy_revision;
        if ($state->scope !== $scope || $state->receiver_scope !== null && $state->receiver_scope !== $receiver_scope
            || $state->sources->reconciliation !== null) {
            $reconciled = self::reconcile($client, $state, $scope, $receiver_scope, $save);
            if (is_wp_error($reconciled)) return $reconciled;
            return 'working';
        }
        $state->receiver_scope = $receiver_scope;
        if ($state->attention !== null) return self::error($state->attention);
        if (!$save()) return self::error('stale_worker');
        // Reconcile known mapping identities before creating any new intent; never withdraw from absence.
        if (!$state->mapping_complete) {
            $query = new stdClass(); if ($state->mapping_cursor !== null) $query->cursor = $state->mapping_cursor;
            $page = $client->request('V4-10', ['query' => $query]); if (is_wp_error($page)) return $page;
            foreach ($page->items as $mapping) {
                if ($mapping->connection_id !== $connection->connection_id || self::origin($mapping->canonical_url) !== $origin
                    || isset($state->mappings->{$mapping->source_id})) return self::error('mapping_inventory');
                $state->mappings->{$mapping->source_id} = $mapping;
            }
            if ($page->next_cursor !== null && $page->next_cursor === $state->mapping_cursor) return self::error('mapping_inventory');
            $state->mapping_cursor = $page->next_cursor; $state->mapping_complete = $page->next_cursor === null;
            return $save() ? 'working' : self::error('stale_worker');
        }
        $attention = CmsAttentionRecovery::step($client, $connection, $policy, $state, $save, $guard);
        if (is_wp_error($attention)) return $attention;
        // A whole metadata page is staged and queued before checkpoint; native work stays bounded separately.
        $intake = CmsSourceContinuation::intake($client, $state->sources, $save);
        if (is_wp_error($intake)) return $intake;
        foreach ($state->sources->events as $event) {
            if ($event->event_type === 'publication') {
                $state->source_records ??= new stdClass();
                $source_key = hash('sha256', $event->source->source_id . "\0" . $event->source->source_revision);
                if (isset($state->source_records->$source_key) && $client->codec()->semanticIdentity($state->source_records->$source_key) !== $client->codec()->semanticIdentity($event->source)) return self::error('source_revision_identity');
                $state->source_records->$source_key = $event->source;
            }
            $exists = false;
            foreach ($state->jobs as $queued) if ($queued->event?->event_id === $event->event_id) {
                if ($client->codec()->semanticIdentity($queued->event) !== $client->codec()->semanticIdentity($event)) return self::error('event_identity');
                $exists = true; break;
            }
            if (!$exists) $state->jobs[] = (object) ['event' => $event, 'source' => $event->event_type === 'publication' ? $event->source : null,
                'plan' => null, 'mapping' => null, 'prepared' => false, 'hydration' => null, 'native_result' => null, 'synchronized' => false, 'verification' => null];
        }
        $state->sources->events = [];
        $finished = CmsSourceContinuation::finish_page($state->sources, $save); if (is_wp_error($finished)) return $finished;
        if ($state->sources->stream === 'initial') return 'working'; // Drain the pinned metadata snapshot before holding a native lease.
        $observed = CmsLocalLimitation::observe($client, $state, $policy, $save); if (is_wp_error($observed)) return $observed;
        $selected = null;
        foreach ($state->jobs as $index => $queued) {
            $mapping = $queued->mapping ?? ($state->mappings->{$queued->source?->source_id ?? $queued->event?->source_id ?? ''} ?? null);
            if ($state->lease !== null) {
                if ($mapping?->mapping_id === $state->lease->work->mapping_id) { $selected = $index; break; }
                continue;
            }
            // New exact source intent may supersede a held Work; it is prepared before any same-mapping claim.
            if (isset($queued->recovery) || $mapping === null || !CmsLocalLimitation::limited($state, $mapping->mapping_id)
                || CmsLocalLimitation::may_prepare($state, $queued, $mapping)) { $selected = $index; break; }
        }
        if ($selected === null && get_object_vars($state->local_issues ?? new stdClass()) !== []) {
            $mappings = array_values(get_object_vars($state->mappings));
            for ($i = 0; $i < count($mappings); $i++) {
                $index = ($state->recovery_cursor ?? 0) % count($mappings); $state->recovery_cursor = $index + 1; $mapping = $mappings[$index];
                if ($mapping->destination_native_id === null || CmsLocalLimitation::limited($state, $mapping->mapping_id)) continue;
                $duplicate = false; foreach ($state->jobs as $queued) if ($queued->mapping?->mapping_id === $mapping->mapping_id) { $duplicate = true; break; }
                if ($duplicate) continue;
                // Positive owned inventory identity only, never a global oldest-eligible claim or absence inference.
                $state->jobs[] = (object) ['event' => null, 'source' => null, 'plan' => null, 'mapping' => $mapping, 'prepared' => true,
                    'hydration' => null, 'native_result' => null, 'synchronized' => false, 'verification' => null];
                $selected = array_key_last($state->jobs); break;
            }
        }
        if ($selected === null) return $save() ? (get_object_vars($state->local_issues ?? new stdClass()) === [] && $state->jobs === [] ? 'healthy' : 'waiting') : self::error('stale_worker');
        $job = $state->jobs[$selected];
        $result = self::work($client, $connection, $policy, $job, $state, $save, $guard);
        if (is_wp_error($result)) {
            if ($result->get_error_code() === 'discussionbridge_cms_native_body_limitation') {
                if (!CmsLocalLimitation::hold($job, $state, $save)) return self::error('local_issue_commit');
                $result = 'held';
            } else {
            $data = $result->get_error_data();
            if ($result->get_error_code() === 'discussionbridge_cms_rejected' && is_array($data)
                && in_array($data['code'], ['work_superseded', 'source_ineligible', 'revision_unavailable', 'policy_changed'], true)) {
                $state->retired[] = (object) ['reason' => $data['code'], 'jobs' => [$job], 'pending' => $state->pending, 'lease' => $state->lease];
                CmsMutation::settled($state); $state->lease = null; $state->lease_deadline = 0; $result = 'done';
            } else {
            $code = self::failure_code($result);
            if ($code === null || $state->pending !== null || !CmsLease::valid($state, $guard, 10)) return $result;
            $job->failure_request = (object) ['authority' => CmsLease::authority($state->lease), 'code' => $code,
                'native_change_applied' => $job->native_result !== null, 'safe_detail' => null, 'retry_after_seconds' => null];
            if (!$save()) return self::error('stale_worker');
            $result = self::failure($client, $job, $state, $save);
            if (is_wp_error($result)) return $result;
            }
            }
        }
        if (in_array($result, ['done', 'held'], true)) { array_splice($state->jobs, $selected, 1); $state->lease = null; $state->lease_deadline = 0; }
        return $save() ? ($state->jobs === [] && $state->sources->page === null
            ? (get_object_vars($state->local_issues ?? new stdClass()) === [] ? 'healthy' : 'waiting') : 'working') : self::error('stale_worker');
    }

    private static function work(CmsClient $client, stdClass $connection, stdClass $policy, stdClass $job, stdClass $state,
        callable $save, callable $guard): string|WP_Error
    {
        // Crash between durable veto observation and queue separation must not trial the same native write again.
        if (isset($job->local_issue)) return new WP_Error('discussionbridge_cms_native_body_limitation', 'The exact native limitation is retained for status reconciliation.');
        if (isset($job->failure_request)) return self::failure($client, $job, $state, $save);
        if (isset($job->retry_at) && $job->retry_at !== null && (new \DateTimeImmutable($job->retry_at))->getTimestamp() > time()) return 'working';
        if ($state->lease !== null && !CmsLease::valid($state, $guard) && $state->pending === null) {
            $status = $client->request('V4-14', ['path' => ['work_id' => $state->lease->work->work_id]]);
            if (is_wp_error($status)) return $status;
            if ($status->work->work_id !== $state->lease->work->work_id || $status->mapping->mapping_id !== $job->mapping->mapping_id
                || $status->mapping->resource_id !== $job->mapping->resource_id || $status->mapping->canonical_url !== $job->mapping->canonical_url) return self::error('work_status');
            if (in_array($status->work->state, ['attention', 'superseded', 'cancelled'], true)) {
                $state->attention_statuses ??= new stdClass(); $state->attention_statuses->{$status->work->work_id} = $status->work->state;
                $state->attention_jobs->{$status->work->work_id} = $job; return 'done';
            }
            if ($status->work->state === 'verified') {
                if ($job->verification === null || $status->mapping->publicly_verified_at !== $job->verification->checked_at) return self::error('work_status');
                $state->mappings->{$job->mapping->source_id} = $status->mapping; return 'done';
            }
            if (in_array($status->work->state, ['leased', 'synchronized'], true) && $status->lease_expires_at !== null
                && (new \DateTimeImmutable($status->lease_expires_at))->getTimestamp() > time()) return 'working';
            $state->lease = null; $state->lease_deadline = 0; $job->mapping = $status->mapping; $job->retry_at = $status->work->retry_at;
            if (!$save()) return self::error('stale_worker');
            if ($job->retry_at !== null && (new \DateTimeImmutable($job->retry_at))->getTimestamp() > time()) return 'working';
        }
        $source_id = $job->source?->source_id ?? $job->event?->source_id ?? $job->mapping->source_id;
        if ($job->mapping === null) $job->mapping = $state->mappings->$source_id ?? null;
        if ($job->source !== null && $state->lease === null && !$job->prepared) {
            if ($job->plan === null) {
                $existing = $job->mapping?->destination_native_id;
                $post = $existing === null ? null : get_post((int) $existing);
                if ($existing !== null && !$post instanceof \WP_Post) return self::error('native_identity');
                $selection = CmsNativeSelection::resolve($connection, $policy, $post); if (is_wp_error($selection)) return $selection;
                if ($job->mapping !== null) $job->plan = (object) ['canonical_url' => $job->mapping->canonical_url, 'slug' => $post?->post_name ?? ''];
                else {
                    $plan = CmsPermalink::plan($job->source, $selection['post']['post_type']); if (is_wp_error($plan)) return $plan;
                    $job->plan = (object) $plan;
                }
                if (!$save()) return self::error('stale_worker');
            }
            $external = $job->mapping?->external_id ?? ('from:' . Settings::site_id() . ':' . hash('sha256', $source_id));
            $prepared = CmsMutation::perform($client, 'V4-09', ['body' => (object) ['source_id' => $source_id,
                'source_revision' => $job->source->source_revision, 'policy_revision' => $policy->policy_revision,
                'external_id' => $external, 'canonical_url' => $job->plan->canonical_url]], $state, $save);
            if (is_wp_error($prepared)) return $prepared;
            if ($prepared->connection_id !== $connection->connection_id || $prepared->source_id !== $source_id
                || $prepared->external_id !== $external || $prepared->canonical_url !== $job->plan->canonical_url
                || $prepared->desired_source_revision !== $job->source->source_revision
                || ($job->mapping !== null && ($prepared->mapping_id !== $job->mapping->mapping_id || $prepared->resource_id !== $job->mapping->resource_id))) return self::error('mapping_intent');
            $job->mapping = $prepared; $job->prepared = true; $state->mappings->$source_id = $prepared; CmsMutation::settled($state);
            if (!$save()) return self::error('stale_worker');
        }
        if ($job->mapping === null) return self::error('revocation_mapping_reconciliation');
        if ($state->lease === null && !isset($job->recovery) && CmsLocalLimitation::limited($state, $job->mapping->mapping_id)) return 'working';
        if ($state->lease === null) {
            $lease = isset($job->recovery)
                ? CmsMutation::perform($client, 'V4-36', ['path' => ['recovery_id' => $job->recovery->recovery_id],
                    'body' => (object) ['work_id' => $job->recovery->work->work_id]], $state, $save)
                : CmsMutation::perform($client, 'V4-13', ['body' => (object) ['mapping_id' => $job->mapping->mapping_id]], $state, $save);
            if (is_wp_error($lease)) return $lease;
            if ($lease === NoContent::VALUE) {
                CmsMutation::settled($state);
                if (isset($job->recovery)) {
                    $observed = $client->request('V4-14', ['path' => ['work_id' => $job->recovery->work->work_id]]);
                    if (is_wp_error($observed)) return $observed;
                    if ($observed->work->work_id !== $job->recovery->work->work_id || $observed->mapping->mapping_id !== $job->mapping->mapping_id
                        || $observed->mapping->resource_id !== $job->mapping->resource_id) return self::error('recovery_claim_identity');
                    $job->recovery_status = $observed; $job->retry_at = $observed->work->retry_at;
                    if ($observed->work->state === 'attention') {
                        $state->attention_jobs->{$observed->work->work_id} = $job;
                        $state->attention_statuses ??= new stdClass(); $state->attention_statuses->{$observed->work->work_id} = 'attention';
                    }
                    // Empty claim is not disappearance of a known successor. Keep observing nonterminal work.
                    return $save() ? (in_array($observed->work->state, ['verified', 'attention', 'cancelled', 'superseded'], true) ? 'done' : 'working') : self::error('stale_worker');
                }
                return $save() ? 'done' : self::error('stale_worker');
            }
            if (isset($job->recovery) && ($lease->work->work_id !== $job->recovery->work->work_id
                || $lease->work->attempt_cycle !== $job->recovery->work->attempt_cycle)) return self::error('recovery_claim_identity');
            $current_mapping = $client->request('V4-11', ['path' => ['mapping_id' => $job->mapping->mapping_id]]);
            if (is_wp_error($current_mapping)) return $current_mapping;
            foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url'] as $field)
                if ($current_mapping->$field !== $job->mapping->$field) return self::error('mapping_identity');
            $job->mapping = $current_mapping;
            $deadline = CmsLease::deadline($lease, $state->pending->started_at);
            $valid = CmsLease::accept($client, $lease, $job->mapping, $policy, null, $deadline <= time()); if (is_wp_error($valid)) return $valid;
            if ($job->native_result !== null) {
                $receipt = get_post_meta((int) $job->native_result->native_id, CmsPresentation::META, true);
                if (!is_array($receipt) || $receipt['receipt']['last_lease_generation'] !== $lease->lease_generation
                    || ($receipt['work_id'] ?? null) !== $lease->work->work_id) {
                    $job->native_result = null; $job->synchronized = false; $job->verification = null;
                }
            }
            $state->lease = $lease; $state->lease_deadline = CmsLease::deadline($lease, $state->pending->started_at); CmsMutation::settled($state);
            if (!$save()) return self::error('stale_worker');
            if (!CmsLease::valid($state, $guard)) return 'working'; // Expired replay is status evidence, never native authority.
        }
        // Resolve an uncertain acknowledgement before attempting a different lease mutation.
        if ($state->pending === null || $state->pending->route === 'V4-15') {
            $renewed = CmsLease::renew($client, $state, $job->mapping, $policy, $save, $guard); if (is_wp_error($renewed)) return $renewed;
        } elseif ($state->pending->route !== 'V4-16') return self::error('mutation_pending');
        if ($state->lease->work->action === 'withdraw') { $job->source = null; $job->hydration = null; }
        elseif ($job->source === null || $job->source->source_revision !== $state->lease->work->source_revision) {
            $source_key = hash('sha256', $state->lease->work->source_id . "\0" . $state->lease->work->source_revision);
            $matching = $state->source_records->$source_key ?? null;
            foreach ($state->jobs as $queued) if ($queued->event?->event_type === 'publication'
                && $queued->event->source->source_id === $state->lease->work->source_id
                && $queued->event->source->source_revision === $state->lease->work->source_revision) {
                if ($matching !== null && $client->codec()->semanticIdentity($matching) !== $client->codec()->semanticIdentity($queued->event->source)) return self::error('source_revision_identity');
                $matching = $queued->event->source;
            }
            if ($matching === null) return $save() ? 'working' : self::error('stale_worker');
            $job->source = $matching; $job->hydration = null;
            if (!$save()) return self::error('stale_worker');
        }
        if ($job->native_result === null) {
            if ($job->source !== null) {
                $content = CmsSourceContinuation::content($client, $job->source, $job->hydration, $save);
                if (is_wp_error($content)) return $content->get_error_code() === 'discussionbridge_cms_content_pending' ? 'working' : $content;
            }
            $current_authority = CmsLease::renew($client, $state, $job->mapping, $policy, $save, $guard, true);
            if (is_wp_error($current_authority)) return $current_authority;
            $native = CmsNativeWriter::apply($client, $connection, $policy, $job, $state, $save, $guard); if (is_wp_error($native)) return $native;
        }
        if (!$job->synchronized) {
            if ($state->pending === null || $state->pending->route === 'V4-15') {
                $current_authority = CmsLease::renew($client, $state, $job->mapping, $policy, $save, $guard, true);
                if (is_wp_error($current_authority)) return $current_authority;
            }
            if (!CmsNativeWriter::confirm($client, $job, $state, $connection)) return self::error('native_receipt_reconciliation');
            $ack = CmsMutation::perform($client, 'V4-16', ['path' => ['work_id' => $state->lease->work->work_id], 'body' => (object) ['stage' => 'synchronized',
                'authority' => CmsLease::authority($state->lease), 'native_result' => $job->native_result]], $state, $save);
            if (is_wp_error($ack)) return $ack;
            if (!self::ack_matches($ack, $job, $state) || $ack->work->state !== 'synchronized') return self::error('synchronized_receipt');
            $job->mapping = $ack->mapping; $state->mappings->{$job->mapping->source_id} = $ack->mapping;
            $job->synchronized = true; CmsMutation::settled($state); if (!$save()) return self::error('stale_worker');
        }
        if ($job->verification === null) {
            $verified = CmsPublicVerifier::verify($client, $job->mapping, $job->native_result, $job->source,
                fn(): bool => CmsLease::valid($state, $guard, 15));
            if (is_wp_error($verified)) return $verified;
            $job->verification = $verified; if (!$save()) return self::error('stale_worker');
        }
        $ack = CmsMutation::perform($client, 'V4-16', ['path' => ['work_id' => $state->lease->work->work_id], 'body' => (object) ['stage' => 'verified',
            'authority' => CmsLease::authority($state->lease), 'verification' => $job->verification]], $state, $save);
        if (is_wp_error($ack)) return $ack;
        if (!self::ack_matches($ack, $job, $state) || $ack->work->state !== 'verified'
            || $ack->mapping->publicly_verified_at !== $job->verification->checked_at) return self::error('verified_receipt');
        $state->mappings->{$job->mapping->source_id} = $ack->mapping; CmsMutation::settled($state);
        update_post_meta((int) $job->native_result->native_id, '_discussionbridge_publicly_verified_at', $job->verification->checked_at);
        return 'done';
    }

    private static function ack_matches(stdClass $ack, stdClass $job, stdClass $state): bool
    {
        return $ack->work->work_id === $state->lease->work->work_id && $ack->work->mapping_id === $job->mapping->mapping_id
            && $ack->work->source_revision === $state->lease->work->source_revision && $ack->work->policy_revision === $state->lease->work->policy_revision
            && $ack->work->lease_generation === $state->lease->lease_generation && $ack->mapping->mapping_id === $job->mapping->mapping_id
            && $ack->mapping->resource_id === $job->mapping->resource_id && $ack->mapping->canonical_url === $job->mapping->canonical_url
            && $ack->mapping->external_id === $job->mapping->external_id && $ack->mapping->destination_native_id === $job->native_result->native_id;
    }

    public static function origin(string $url): string
    {
        $parts = parse_url($url);
        return ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '') . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    private static function failure(CmsClient $client, stdClass $job, stdClass $state, callable $save): string|WP_Error
    {
        $response = CmsMutation::perform($client, 'V4-17', ['path' => ['work_id' => $job->failure_request->authority->work_id],
            'body' => $job->failure_request], $state, $save);
        if (is_wp_error($response)) return $response;
        if ($response->work->work_id !== $job->failure_request->authority->work_id || $response->mapping->mapping_id !== $job->mapping->mapping_id
            || !in_array($response->work->state, ['retry_wait', 'attention'], true)) return self::error('failure_receipt');
        CmsMutation::settled($state); $state->lease = null; $state->lease_deadline = 0;
        $job->mapping = $response->mapping; $state->mappings->{$job->mapping->source_id} = $response->mapping;
        $job->retry_at = $response->work->retry_at;
        $code = $job->failure_request->code; unset($job->failure_request);
        if ($response->work->state === 'attention') {
            $state->attention_statuses ??= new stdClass(); $state->attention_statuses->{$response->work->work_id} = 'attention';
            $state->attention_jobs->{$response->work->work_id} = $job;
            if ($job->native_result !== null) {
                update_post_meta((int) $job->native_result->native_id, '_discussionbridge_last_reason', $code);
                update_post_meta((int) $job->native_result->native_id, '_discussionbridge_last_outcome', 'attention');
            }
            return 'done'; // Retained recovery state; no native administrator retry is inferred.
        }
        return $save() ? 'working' : self::error('stale_worker');
    }

    private static function failure_code(WP_Error $error): ?string
    {
        return match ($error->get_error_code()) {
            'discussionbridge_cms_public_verification_failed' => 'public_verification_failed',
            'discussionbridge_cms_metadata_unavailable' => 'metadata_unavailable',
            'discussionbridge_cms_native_native_drift', 'discussionbridge_cms_native_ownership',
            'discussionbridge_cms_native_identity', 'discussionbridge_cms_native_identity_census' => 'native_ownership_conflict',
            'discussionbridge_cms_transport' => 'transport_timeout',
            default => null,
        };
    }

    private static function reconcile(CmsClient $client, stdClass $state, string $scope, string $receiver_scope, callable $save): bool|WP_Error
    {
        $observation = null;
        if ($state->pending !== null) {
            $observation = $client->request($state->pending->route, (array) $state->pending->options);
            if (is_wp_error($observation)) {
                $data = $observation->get_error_data();
                if ($observation->get_error_code() !== 'discussionbridge_cms_rejected' || !is_array($data) || $data['retryable']) return $observation;
                $observation = (object) ['rejection' => $data['code']];
            } elseif ($observation === NoContent::VALUE) $observation = (object) ['bodyless_claim' => true];
        } elseif ($state->lease !== null) {
            $observation = $client->request('V4-14', ['path' => ['work_id' => $state->lease->work->work_id]]);
            if (is_wp_error($observation)) return $observation;
        }
        $state->retired[] = (object) ['scope' => $state->scope, 'receiver_scope' => $state->receiver_scope,
            'reason' => $state->sources->reconciliation ?? 'configuration_scope_changed', 'sources' => $state->sources,
            'pending' => $state->pending, 'lease' => $state->lease, 'jobs' => $state->jobs, 'local_issues' => $state->local_issues ?? new stdClass(), 'observation' => $observation];
        $state->scope = $scope; $state->receiver_scope = $receiver_scope; $state->sources = CmsSourceContinuation::fresh();
        $state->jobs = []; $state->mappings = new stdClass(); $state->mapping_cursor = null; $state->mapping_complete = false;
        $state->pending = null; $state->lease = null; $state->lease_deadline = 0;
        $state->local_issues = new stdClass(); $state->source_records = new stdClass();
        // The next invocation must inventory durable mappings before the explicit fresh initial snapshot.
        // Durable native fences, attention jobs and all retired uncertain evidence remain unchanged.
        return $save() ? true : self::error('stale_worker');
    }
    private static function error(string $reason): WP_Error { return new WP_Error('discussionbridge_cms_' . $reason, 'The current native CMS job needs reconciliation before continuing.'); }
}
