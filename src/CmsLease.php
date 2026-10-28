<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Receiver authority remains distinct from the native connection lock and durable mapping fence. */
final class CmsLease
{
    public static function authority(stdClass $lease): stdClass
    {
        return (object) ['work_id' => $lease->work->work_id, 'lease_token' => $lease->lease_token,
            'lease_generation' => $lease->lease_generation, 'source_revision' => $lease->work->source_revision,
            'policy_revision' => $lease->work->policy_revision];
    }

    public static function accept(CmsClient $client, stdClass $lease, stdClass $mapping, stdClass $policy, ?stdClass $prior = null, bool $status_only = false): bool|WP_Error
    {
        $client->codec()->validate('Lease', $lease);
        $work = $lease->work;
        if ($work->mapping_id !== $mapping->mapping_id || $work->source_id !== $mapping->source_id
            || $work->policy_revision !== $policy->policy_revision || $work->lease_generation !== $lease->lease_generation
            || !in_array($work->state, ['leased', 'synchronized'], true)
            || ($work->action === 'publish_revision' && $work->source_revision !== $mapping->desired_source_revision)
            || ($work->action === 'withdraw' && $work->source_revision !== null)) return self::invalid();
        if ($prior !== null && ($prior->work->work_id !== $work->work_id || $prior->lease_token !== $lease->lease_token
            || $prior->lease_generation !== $lease->lease_generation || $prior->work->source_revision !== $work->source_revision
            || $prior->work->policy_revision !== $work->policy_revision)) return self::invalid();
        $server = new \DateTimeImmutable($lease->server_time);
        $expires = new \DateTimeImmutable($lease->expires_at);
        if ($expires <= $server || $expires->getTimestamp() - $server->getTimestamp() > 300
            || !$status_only && abs($server->getTimestamp() - time()) > 300) return self::invalid();
        return true;
    }

    public static function deadline(stdClass $lease, int $request_started_at): int
    {
        // Use the first attempt's local start for replay too. Server clock skew cannot extend ownership.
        return $request_started_at + (new \DateTimeImmutable($lease->expires_at))->getTimestamp()
            - (new \DateTimeImmutable($lease->server_time))->getTimestamp() - 20;
    }

    public static function valid(stdClass $state, callable $native_guard, int $reserve_seconds = 0): bool
    {
        return $state->lease !== null && $native_guard() && $state->lease_deadline > time() + $reserve_seconds;
    }

    public static function renew(CmsClient $client, stdClass $state, stdClass $mapping, stdClass $policy, callable $save, callable $guard, bool $force = false): bool|WP_Error
    {
        if (!self::valid($state, $guard)) return self::invalid();
        if (!$force && self::valid($state, $guard, 90) && $state->pending === null) return true;
        $renewed = CmsMutation::perform($client, 'V4-15', ['path' => ['work_id' => $state->lease->work->work_id],
            'body' => (object) ['authority' => self::authority($state->lease)]], $state, $save);
        if (is_wp_error($renewed)) return $renewed;
        $valid = self::accept($client, $renewed, $mapping, $policy, $state->lease);
        if (is_wp_error($valid)) return $valid;
        $state->lease = $renewed; $state->lease_deadline = self::deadline($renewed, $state->pending->started_at); CmsMutation::settled($state);
        return $save() ? true : new WP_Error('discussionbridge_stale_worker', 'The renewed CMS lease could not be committed.');
    }

    private static function invalid(): WP_Error { return new WP_Error('discussionbridge_cms_lease', 'Current CMS lease authority is unavailable; reconcile the work before a native write.'); }
}
