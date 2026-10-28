<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Observation is never native publication authority. The only new authority is the targeted fresh lease. */
final class CmsAttentionRecovery
{
    public const PROFILE = 'wordpress-dynamic-1';

    public static function fresh(): stdClass
    {
        return (object) ['inspection_after' => 0, 'recovery_after' => 0, 'inspection_page' => [], 'recovery_page' => [],
            'report' => null, 'status' => 'waiting', 'issue' => null, 'history' => new stdClass(), 'attention_cursor' => 0];
    }

    /** Called under the same native site writer as the ordinary coordinator; no nested writer or remote commands. */
    public static function step(CmsClient $client, stdClass $connection, stdClass $policy, stdClass $state, callable $save, callable $guard): bool|WP_Error
    {
        if (!$guard()) return self::stale();
        $state->attention_recovery ??= self::fresh(); $recovery = $state->attention_recovery;
        $state->attention_statuses ??= new stdClass();
        $capability = $client->request('V4-32');
        if (is_wp_error($capability) || $capability->protocol_revision !== 'attention-recovery-1'
            || !in_array(self::PROFILE, $capability->inspection_profiles, true)) {
            $recovery->status = 'unsupported'; $recovery->issue = 'unsupported_profile';
            return $save() ? true : self::stale();
        }
        $recovery->status = 'available'; $recovery->issue = null;
        // Observe one retained item regardless of local_issues/source-feed activity. A container is not current Work state.
        $keys = array_keys(get_object_vars($state->attention_jobs));
        if ($keys !== []) {
            $key = $keys[$recovery->attention_cursor % count($keys)]; $recovery->attention_cursor++;
            $status = $client->request('V4-14', ['path' => ['work_id' => $key]]);
            if (!is_wp_error($status) && $status->work->work_id === $key
                && $status->mapping->mapping_id === $state->attention_jobs->$key->mapping->mapping_id) {
                $state->attention_statuses->$key = $status->work->state;
                if (in_array($status->work->state, ['superseded', 'cancelled', 'verified'], true)) {
                    $recovery->history->$key = (object) ['job' => $state->attention_jobs->$key, 'status' => $status];
                    unset($state->attention_jobs->$key);
                }
            }
        }
        // A retained report replays only this diagnostic mutation, never state->pending publication mutation.
        if ($recovery->report !== null) {
            $sent = self::send_report($client, $recovery, $save);
            if (is_wp_error($sent)) { $recovery->issue = 'collector_unavailable'; return $save() ? true : self::stale(); }
        } else {
            if ($recovery->inspection_page === []) {
                $page = $client->request('V4-33', ['query' => (object) ['after' => $recovery->inspection_after]]);
                if (!is_wp_error($page) && self::page($page, $recovery->inspection_after)) {
                    $recovery->inspection_page = $page->items;
                    if (!$save()) return self::stale(); // Durable page before cursor/report.
                }
            }
            if ($recovery->inspection_page !== []) {
                $inspection = $recovery->inspection_page[0];
                $observation = self::inspect($client, $connection, $policy, $inspection, $state, $guard);
                if (!$guard()) return self::stale();
                $recovery->report = (object) ['inspection' => $inspection, 'pending' => null, 'observation' => $observation];
                if (!$save()) return self::stale();
                self::send_report($client, $recovery, $save);
            }
        }
        if ($recovery->recovery_page === []) {
            $page = $client->request('V4-35', ['query' => (object) ['after' => $recovery->recovery_after]]);
            if (!is_wp_error($page) && self::page($page, $recovery->recovery_after)) {
                $recovery->recovery_page = $page->items;
                if (!$save()) return self::stale();
            }
        }
        if ($recovery->recovery_page !== []) {
            $event = $recovery->recovery_page[0];
            $installed = self::install($client, $connection, $policy, $event, $state, $guard);
            if ($installed === true) { $recovery->recovery_after = $event->sequence; array_shift($recovery->recovery_page); }
            elseif (is_wp_error($installed)) $recovery->issue = 'native_conflict';
        }
        return $save() ? true : self::stale();
    }

    private static function page(stdClass $page, int $after): bool
    {
        $last = $after;
        foreach ($page->items as $item) { if ($item->sequence <= $last) return false; $last = $item->sequence; }
        return $page->next_after === $last && !($page->items === [] && $page->has_more);
    }

    private static function send_report(CmsClient $client, stdClass $recovery, callable $save): bool|WP_Error
    {
        $report = $recovery->report;
        $result = CmsMutation::perform($client, 'V4-34', ['path' => ['inspection_id' => $report->inspection->inspection_id],
            'body' => (object) ['context_sha256' => $report->inspection->context_sha256, 'observation' => $report->observation]], $report, $save);
        if (is_wp_error($result)) return $result;
        if ($result->inspection_id !== $report->inspection->inspection_id || $result->context_sha256 !== $report->inspection->context_sha256) return self::stale();
        $recovery->inspection_after = $report->inspection->sequence;
        $recovery->inspection_results ??= new stdClass(); $recovery->inspection_results->{$result->inspection_id} = $result;
        array_shift($recovery->inspection_page); $recovery->report = null;
        return $save() ? true : self::stale();
    }

    /** Actual native reads only. No apply/reconcile/hook/publication mutation/error-option writes. */
    public static function inspect(CmsClient $client, stdClass $connection, stdClass $policy, stdClass $inspection, stdClass $state, callable $guard): stdClass
    {
        global $wpdb;
        $mapping = $inspection->mapping; $work = $inspection->work;
        $uncertain = self::pending_for($state, $mapping->mapping_id, $mapping->source_id);
        $out = (object) ['profile' => self::PROFILE, 'observed_at' => (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.v\Z'),
            'native_state' => 'unknown', 'object_exists' => null, 'native_id' => null, 'native_revision' => null,
            'ownership' => null, 'native_result' => null, 'verification' => null, 'source_revision' => null,
            'managed_content_sha256' => null, 'native_command' => 'none', 'pending_mutation' => $uncertain ? 'uncertain' : 'none',
            'prepared_transaction_id' => $inspection->prepared_transaction?->transaction_id, 'journal_sha256' => hash('sha256', $client->codec()->semanticIdentity($state)),
            'baseline_sha256' => null, 'candidate_sha256' => null, 'current_sha256' => null, 'restoration_operation_id' => null, 'issue' => 'baseline_missing'];
        if (!$guard() || $work->state !== 'attention' || $connection->connection_id !== $mapping->connection_id || $work->mapping_id !== $mapping->mapping_id
            || $work->source_id !== $mapping->source_id || $work->policy_revision !== $policy->policy_revision || !$policy->enabled) { $out->issue = 'context_changed'; return $out; }
        if ($uncertain) { $out->issue = 'uncertain_mutation'; return $out; }
        if ($inspection->prepared_transaction !== null) { $out->issue = 'unsupported_profile'; $out->native_state = 'unsupported'; return $out; }
        $fence = $state->fences->{$mapping->mapping_id} ?? null;
        $old = $state->attention_jobs->{$work->work_id} ?? $state->attention_recovery->history->{$work->work_id}->job ?? null;
        if (($fence->unresolved_create ?? null) !== null || ($old->creation_attempt ?? null) !== null && !($old->creation_attempt->committed ?? false)) {
            $out->pending_mutation = 'uncertain'; $out->issue = 'uncertain_mutation'; return $out;
        }
        $claims = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT 2',
            '_discussionbridge_resource_id', $mapping->resource_id));
        if (!is_array($claims) || $wpdb->last_error !== '' || count($claims) > 1) { $out->issue = 'native_conflict'; return $out; }
        $native_id = $mapping->destination_native_id;
        if ($native_id === null && $claims !== []) {
            // A committed private create can precede the receiver's lost synchronized acknowledgement.
            // Recover only that exact known owned ID; this is never adoption from a bare resource tag.
            $known = $old->native_result->native_id ?? (($old->creation_attempt->committed ?? false) ? ($old->creation_attempt->native_id ?? null) : null);
            if (!is_string($known) || $claims !== [$known]) { $out->object_exists = true; $out->issue = 'native_conflict'; return $out; }
            $native_id = $known;
        }
        if ($native_id === null) {
            $out->object_exists = $claims !== [];
            if ($claims !== [] || $inspection->native_result !== null || $inspection->verified_native_result !== null
                || url_to_postid($mapping->canonical_url) > 0 || $old === null || $work->action !== 'publish_revision' || $inspection->source === null) {
                $out->issue = 'native_conflict'; return $out;
            }
            $topics = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT 2',
                '_discussionbridge_topic_id', $inspection->source->topic_id));
            if (!is_array($topics) || $wpdb->last_error !== '' || $topics !== [] || !$guard()) { $out->issue = 'native_conflict'; return $out; }
            if (is_wp_error(CmsNativeSelection::resolve($connection, $policy))) { $out->issue = 'metadata_unavailable'; return $out; }
            $out->native_state = 'never_applied'; $out->issue = 'none'; return $out;
        }
        $id = (int) $native_id; $out->native_id = $native_id;
        if ((string) $id !== $native_id || $claims !== [$native_id]) { $out->issue = 'native_conflict'; return $out; }
        clean_post_cache($id); $native = get_post($id); $metadata = get_post_meta($id, CmsPresentation::META, true);
        $out->object_exists = $native instanceof \WP_Post;
        if (!$native instanceof \WP_Post || !is_array($metadata)) { $out->issue = 'native_conflict'; return $out; }
        if ($work->action === 'publish_revision' && is_wp_error(CmsNativeSelection::resolve($connection, $policy, $native))) { $out->issue = 'metadata_unavailable'; return $out; }
        $receipt = $metadata['receipt'] ?? [];
        foreach (['connection_id', 'mapping_id', 'resource_id', 'source_id', 'external_id', 'canonical_url'] as $field)
            if (($receipt[$field] ?? null) !== $mapping->$field) { $out->issue = 'native_conflict'; return $out; }
        if (($receipt['native_id'] ?? null) !== $native_id || ($receipt['forum_origin'] ?? null) !== Settings::server_url()) { $out->issue = 'native_conflict'; return $out; }
        $revision = CmsNativeFence::revision($id, false, null, true);
        if (!is_string($revision) || ($metadata['managed_revision'] ?? null) !== $revision || !$guard()) { $out->issue = 'native_conflict'; return $out; }
        try {
            $receipt_hash = $client->codec()->nativeOwnershipReceiptSha256((object) $receipt);
            $result = (object) ['kind' => $metadata['action'], 'native_id' => (string) $id, 'external_id' => $mapping->external_id,
                'canonical_url' => $mapping->canonical_url, 'native_revision' => $revision, 'ownership_receipt_sha256' => $receipt_hash];
            if ($metadata['action'] === 'publish_revision') $result->representation = (object) ['kind' => 'full', 'source_content_sha256' => $metadata['source_sha256']];
            $client->codec()->validate('SyncResult', $result);
        } catch (\Throwable) { $out->issue = 'native_conflict'; return $out; }
        $out->native_revision = $revision; $out->source_revision = $receipt['source_revision']; $out->native_result = $result;
        $out->ownership = (object) ['resource_id' => $mapping->resource_id, 'external_id' => $mapping->external_id,
            'native_id' => (string) $id, 'canonical_url' => $mapping->canonical_url, 'source_revision' => $receipt['source_revision'], 'ownership_receipt_sha256' => $receipt_hash];
        $out->managed_content_sha256 = hash('sha256', $native->post_content); $out->current_sha256 = $revision;
        $candidate = ($metadata['work_id'] ?? null) === $work->work_id && $receipt['source_revision'] === $work->source_revision && $metadata['action'] === $work->action;
        if ($candidate && ($inspection->source === null || ($metadata['source_sha256'] ?? null) === $inspection->source->content_sha256)) {
            $out->native_state = 'candidate_applied'; $out->candidate_sha256 = $revision; $out->issue = 'none';
        } elseif ($mapping->destination_native_revision === $revision) {
            $out->native_state = 'baseline'; $out->baseline_sha256 = $revision; $out->issue = 'none';
        } else { $out->native_state = 'conflict'; $out->issue = 'native_conflict'; }
        return $out;
    }

    private static function install(CmsClient $client, stdClass $connection, stdClass $policy, stdClass $event, stdClass $state, callable $guard): bool|WP_Error
    {
        $recovery = $state->attention_recovery;
        if (isset($recovery->installed->{$event->recovery_id})) return true;
        if ($event->mode !== 'retry' || $event->mapping->connection_id !== $connection->connection_id || $event->work->mapping_id !== $event->mapping->mapping_id
            || $event->work->policy_revision !== $policy->policy_revision || $event->work->work_id === $event->predecessor_work_id) return self::stale();
        // An obsolete event is historical discovery, not permission to retry a superseded desired edition.
        $status = $client->request('V4-14', ['path' => ['work_id' => $event->work->work_id]]);
        if (is_wp_error($status)) return $status;
        if ($status->work->work_id !== $event->work->work_id || $status->mapping->mapping_id !== $event->mapping->mapping_id) return self::stale();
        if (in_array($status->work->state, ['superseded', 'cancelled', 'verified'], true)) {
            $recovery->installed ??= new stdClass(); $recovery->installed->{$event->recovery_id} = (object) ['event' => $event, 'disposition' => $status->work->state]; return true;
        }
        foreach ($state->jobs as $job) if (($job->recovery->recovery_id ?? null) === $event->recovery_id) return true;
        $old = $state->attention_jobs->{$event->predecessor_work_id} ?? $recovery->history->{$event->predecessor_work_id}->job ?? null;
        if ($old === null || self::pending_for($state, $event->mapping->mapping_id, $event->mapping->source_id)) return self::stale();
        foreach (['connection_id', 'mapping_id', 'resource_id', 'source_id', 'external_id', 'canonical_url', 'destination_native_id'] as $field)
            if ($old->mapping->$field !== $event->mapping->$field) return self::stale();
        if ($event->source !== null) {
            if ($event->source->source_id !== $event->work->source_id || $event->source->source_revision !== $event->work->source_revision) return self::stale();
            $known = $state->source_records->{hash('sha256', $event->source->source_id . "\0" . $event->source->source_revision)} ?? $old->source;
            if ($known !== null && $known->source_revision === $event->source->source_revision
                && $client->codec()->semanticIdentity($known) !== $client->codec()->semanticIdentity($event->source)) return self::stale();
        }
        $state->jobs[] = (object) ['event' => null, 'source' => $event->source, 'plan' => $old->plan, 'mapping' => $event->mapping,
            'prepared' => true, 'hydration' => null, 'native_result' => null, 'synchronized' => false, 'verification' => null, 'recovery' => $event];
        $recovery->history->{$event->predecessor_work_id} ??= (object) ['job' => $old, 'status' => null];
        unset($state->attention_jobs->{$event->predecessor_work_id});
        $state->attention_statuses->{$event->predecessor_work_id} = 'superseded';
        if ($event->source !== null) $state->source_records->{hash('sha256', $event->source->source_id . "\0" . $event->source->source_revision)} = $event->source;
        $recovery->installed ??= new stdClass(); $recovery->installed->{$event->recovery_id} = (object) ['event' => $event, 'disposition' => 'queued'];
        return $guard() ? true : self::stale();
    }

    private static function stale(): WP_Error { return new WP_Error('discussionbridge_cms_attention_reconciliation', 'Current native recovery identity needs reconciliation.'); }

    private static function pending_for(stdClass $state, string $mapping_id, string $source_id): bool
    {
        if ($state->pending === null) return false;
        $body = $state->pending->options->body ?? null;
        if (is_string($body->mapping_id ?? null)) return $body->mapping_id === $mapping_id;
        if (is_string($body->source_id ?? null)) return $body->source_id === $source_id;
        if ($state->lease !== null && ($body->authority->work_id ?? null) === $state->lease->work->work_id)
            return $state->lease->work->mapping_id === $mapping_id;
        if (is_string($body->work_id ?? null)) foreach ($state->jobs as $job)
            if (($job->recovery->work->work_id ?? null) === $body->work_id) return $job->mapping->mapping_id === $mapping_id;
        return true; // Missing historical scope stays unknown rather than being inferred harmless.
    }
}
