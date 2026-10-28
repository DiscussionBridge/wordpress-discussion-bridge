<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Exact private native limitation, never a receiver Failure or a publication gate. */
final class CmsLocalLimitation
{
    public static function hold(stdClass $job, stdClass $state, callable $save): bool
    {
        if (!isset($job->local_issue) || $state->lease === null || $state->pending !== null) return false;
        $issue = $job->local_issue;
        if ($issue->work->work_id !== $state->lease->work->work_id || $issue->mapping->mapping_id !== $job->mapping->mapping_id) return false;
        $state->local_issues ??= new stdClass();
        $state->local_issues->{$issue->work->work_id} = (object) ['job' => $job, 'lease' => $state->lease,
            'lease_deadline' => $state->lease_deadline, 'scope' => $state->scope, 'receiver_scope' => $state->receiver_scope];
        $state->lease = null; $state->lease_deadline = 0;
        return $save();
    }

    public static function limited(stdClass $state, string $mapping_id): bool
    {
        foreach (get_object_vars($state->local_issues ?? new stdClass()) as $held)
            if ($held->job->local_issue->mapping->mapping_id === $mapping_id) return true;
        return false;
    }

    public static function may_prepare(stdClass $state, stdClass $job, stdClass $mapping): bool
    {
        if ($job->source === null || $job->prepared) return false;
        foreach (get_object_vars($state->local_issues ?? new stdClass()) as $held)
            if ($held->job->local_issue->mapping->mapping_id === $mapping->mapping_id
                && $held->job->local_issue->work->source_revision === $job->source->source_revision) return false;
        return true;
    }

    /** One known Work/status and current mapping observation per invocation; no new claim or hook replay. */
    public static function observe(CmsClient $client, stdClass $state, stdClass $policy, callable $save): bool|WP_Error
    {
        $keys = array_keys(get_object_vars($state->local_issues ?? new stdClass()));
        if ($keys === []) return true;
        $index = ($state->local_issue_cursor ?? 0) % count($keys); $state->local_issue_cursor = $index + 1;
        $key = $keys[$index]; $held = $state->local_issues->$key; $issue = $held->job->local_issue;
        $status = $client->request('V4-14', ['path' => ['work_id' => $key]]);
        if (is_wp_error($status)) {
            $held->status_error = $status->get_error_code();
            return $save() ? true : self::stale();
        }
        foreach (['work_id', 'mapping_id', 'source_id', 'source_revision', 'policy_revision', 'action'] as $field)
            if ($status->work->$field !== $issue->work->$field) return self::identity();
        foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url'] as $field)
            if ($status->mapping->$field !== $issue->mapping->$field) return self::identity();
        $mapping = $client->request('V4-11', ['path' => ['mapping_id' => $issue->mapping->mapping_id]]);
        if (is_wp_error($mapping)) {
            $held->status = $status; $held->status_error = $mapping->get_error_code();
            return $save() ? true : self::stale();
        }
        foreach (['mapping_id', 'resource_id', 'connection_id', 'source_id', 'external_id', 'canonical_url'] as $field)
            if ($mapping->$field !== $issue->mapping->$field) return self::identity();
        $native_id = $mapping->destination_native_id;
        $native_revision = null; $native_receipt = null;
        if ($native_id !== null) {
            clean_post_cache((int) $native_id);
            $revision = CmsNativeFence::revision((int) $native_id);
            $native_revision = is_string($revision) ? $revision : null;
            $native_receipt = get_post_meta((int) $native_id, CmsPresentation::META, true);
        }
        $held->status = $status; $held->current_mapping = $mapping; $held->current_policy_revision = $policy->policy_revision;
        $held->native_observation = (object) ['native_id' => $native_id, 'revision' => $native_revision, 'receipt' => $native_receipt];
        $held->current_source_revisions = [];
        foreach ($state->jobs as $queued) if ($queued->source !== null && $queued->source->source_id === $issue->work->source_id)
            $held->current_source_revisions[] = $queued->source->source_revision;
        unset($held->status_error);
        $state->mappings->{$mapping->source_id} = $mapping;
        if (in_array($status->work->state, ['superseded', 'cancelled', 'attention'], true)) {
            $state->retired[] = (object) ['reason' => 'local_limitation_' . $status->work->state, 'held' => $held];
            if ($status->work->state === 'attention') $state->attention_jobs->$key = $held->job;
            unset($state->local_issues->$key);
        }
        // Waiting retains the immutable original cycle/count/time/lease evidence. No trial native write proves repair.
        return $save() ? true : self::stale();
    }

    private static function stale(): WP_Error { return new WP_Error('discussionbridge_stale_worker', 'This worker cannot persist the native limitation observation.'); }
    private static function identity(): WP_Error { return new WP_Error('discussionbridge_cms_local_issue_identity', 'Known native limitation identity needs reconciliation.'); }
}
