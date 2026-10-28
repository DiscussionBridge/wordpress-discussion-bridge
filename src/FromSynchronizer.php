<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

/** Bounded native scheduler; receiver-created work is the sole publication authority. */
final class FromSynchronizer
{
    public const HOOK = 'discussionbridge_sync_from_discourse';
    public const STATE_OPTION = 'discussionbridge_from_sync_state';
    private const CONTINUATION_SECONDS = 60;
    private const CYCLE_SECONDS = 300;

    public static function serialized(callable $operation): mixed { return (new NativeWriter())->run($operation); }

    private static function scope(): string
    {
        return hash('sha256', (string) wp_json_encode([home_url('/'), Settings::server_url(), Settings::connection_id(),
            hash('sha256', Settings::connection_secret()), Settings::lane(), Settings::post_types(), Settings::service_author_id()]));
    }

    private static function state(): mixed { wp_cache_delete(self::STATE_OPTION, 'options'); return get_option(self::STATE_OPTION, null); }
    private static function fresh(string $scope): array
    {
        return ['scope' => $scope, 'epoch' => wp_generate_uuid4(), 'status' => 'queued', 'failures' => 0,
            'reason' => '', 'last_attempt' => null, 'last_success' => null, 'next_due' => null, 'last_wake' => null];
    }

    /** Called only under the site writer. An existing earlier event is never postponed. */
    private static function schedule(array &$state, int $due, callable $guard): bool
    {
        if (!$guard()) return false;
        $existing = wp_next_scheduled(self::HOOK);
        if ($existing !== false && $existing <= $due) $due = $existing;
        else {
            if ($existing !== false && wp_clear_scheduled_hook(self::HOOK) === false) {
                return self::scheduling_unavailable($state, $guard);
            }
            if (!$guard() || !wp_schedule_single_event($due, self::HOOK)) {
                return self::scheduling_unavailable($state, $guard);
            }
        }
        if (!$guard()) return false;
        $state['next_due'] = $due;
        update_option(self::STATE_OPTION, $state, false);
        return $guard() && self::state() === $state;
    }

    private static function scheduling_unavailable(array &$state, callable $guard): bool
    {
        if (!$guard()) return false;
        $state['status'] = 'scheduling_unavailable'; $state['reason'] = 'scheduling_unavailable';
        $existing = wp_next_scheduled(self::HOOK);
        $state['next_due'] = $existing === false ? null : $existing;
        if ($guard()) update_option(self::STATE_OPTION, $state, false);
        return false;
    }

    private static function guarded(string $scope, string $epoch, callable $physical): callable
    {
        return fn(): bool => $physical() && Settings::ready() && Settings::service_author_id() > 0 && self::scope() === $scope
            && is_array(self::state()) && (self::state()['epoch'] ?? null) === $epoch;
    }

    public static function ensure_scheduled(): void
    {
        if (!Settings::ready() || Settings::service_author_id() <= 0) { wp_clear_scheduled_hook(self::HOOK); return; }
        self::serialized(function (callable $physical): void {
            $scope = self::scope(); $state = self::state();
            if (!is_array($state) || ($state['scope'] ?? null) !== $scope) $state = self::fresh($scope);
            // Legacy scheduler attention was a local read failure, never receiver Work authority.
            if (($state['status'] ?? '') === 'attention') $state['status'] = 'local_error';
            update_option(self::STATE_OPTION, $state, false);
            $guard = self::guarded($scope, $state['epoch'], $physical);
            if (($state['status'] ?? '') === 'configuration_required') return;
            $due = is_int($state['next_due'] ?? null) ? max(time() + 1, $state['next_due'])
                : time() + (($state['failures'] ?? 0) >= 3 ? self::CYCLE_SECONDS : 1);
            self::schedule($state, $due, $guard);
        });
    }

    public static function configuration_changed(string $option): void
    {
        if (!in_array($option, [Settings::SERVER_URL_OPTION, Settings::CONNECTION_ID_OPTION, Settings::CONNECTION_SECRET_OPTION,
            Settings::SERVICE_AUTHOR_OPTION, Settings::POST_TYPES_OPTION, Settings::LANE_OPTION, 'active_plugins'], true)) return;
        wp_clear_scheduled_hook(self::HOOK);
        // The protected CMS ledger is never deleted. Epoch invalidates live scheduler progress only.
        update_option(self::STATE_OPTION, self::fresh(self::scope()), false);
        if ($option !== 'active_plugins') self::ensure_scheduled();
    }

    public static function run(): void
    {
        if (!Settings::ready() || Settings::service_author_id() <= 0) { wp_clear_scheduled_hook(self::HOOK); return; }
        $scope = self::scope();
        // A contended callback may only continue the epoch it actually observed.
        $observed = self::state();
        $epoch = is_array($observed) && ($observed['scope'] ?? null) === $scope ? ($observed['epoch'] ?? null) : null;
        $result = self::serialized(function (callable $physical) use ($scope): mixed {
            $state = self::state();
            if (!is_array($state) || ($state['scope'] ?? null) !== $scope) {
                $state = self::fresh($scope);
                update_option(self::STATE_OPTION, $state, false);
            }
            $guard = self::guarded($scope, $state['epoch'], $physical);
            if (($state['status'] ?? '') === 'configuration_required' || !$guard()) return null;
            $state['last_attempt'] = time(); $state['next_due'] = null;
            update_option(self::STATE_OPTION, $state, false);
            try { $step = CmsCoordinator::step($scope, $guard); }
            catch (\Throwable) { $step = new WP_Error('discussionbridge_cms_native_unavailable', 'Native CMS execution needs reconciliation.'); }
            if (!$guard()) return null;
            if (is_wp_error($step)) {
                $state['failures'] = ($state['failures'] ?? 0) + 1; $state['reason'] = Settings::diagnostic_reason($step->get_error_code());
                $data = $step->get_error_data();
                $blocked = in_array($step->get_error_code(), ['discussionbridge_cms_configuration', 'discussionbridge_cms_connection_scope'], true)
                    || is_array($data) && in_array($data['status'] ?? null, [401, 403], true);
                $state['status'] = $blocked ? 'configuration_required' : 'local_error';
            } else { $state['status'] = $step === 'disabled' ? 'healthy' : $step; $state['receiver_disabled'] = $step === 'disabled'; $state['failures'] = 0; $state['reason'] = ''; $state['last_success'] = time(); }
            update_option(self::STATE_OPTION, $state, false);
            if (!$guard() || self::state() !== $state) return null;
            if ($state['status'] === 'configuration_required') wp_clear_scheduled_hook(self::HOOK);
            else {
                $delay = $state['status'] === 'local_error' ? [1 => 60, 2 => 120][min($state['failures'], 2)] : ($state['status'] === 'healthy' ? self::CYCLE_SECONDS : self::CONTINUATION_SECONDS);
                if ($state['status'] === 'local_error' && $state['failures'] >= 3) $delay = self::CYCLE_SECONDS;
                self::schedule($state, time() + $delay, $guard);
            }
            return $step;
        });
        // This advisory wake grants no Work authority and writes no scheduler state.
        // A current blocked/new configuration must not inherit an old worker's event.
        if (is_wp_error($result) && $result->get_error_code() === 'discussionbridge_native_writer_busy') {
            $current = function () use ($scope, $epoch): bool {
                $state = self::state();
                return is_string($epoch) && Settings::ready() && Settings::service_author_id() > 0
                    && self::scope() === $scope && is_array($state) && ($state['scope'] ?? null) === $scope
                    && ($state['epoch'] ?? null) === $epoch && ($state['status'] ?? '') !== 'configuration_required';
            };
            if ($current() && wp_next_scheduled(self::HOOK) === false && $current())
                wp_schedule_single_event(time() + self::CONTINUATION_SECONDS, self::HOOK);
        }
    }

    /** Local scheduler wake only: it grants no receiver Work/retry authority. */
    public static function retry_synchronization(): string|WP_Error
    {
        if (!current_user_can('manage_options') || !Settings::ready() || Settings::service_author_id() <= 0)
            return new WP_Error('discussionbridge_cms_configuration', 'A configured connection and native administrator permission are required.');
        $actor = get_current_user_id();
        return self::serialized(function (callable $physical) use ($actor): string|WP_Error {
            if (!$physical() || get_current_user_id() !== $actor || !current_user_can('manage_options')
                || !Settings::ready() || Settings::service_author_id() <= 0)
                return new WP_Error('discussionbridge_cms_configuration', 'Current native administrator permission is required.');
            $scope = self::scope(); $state = self::state();
            if (!is_array($state) || ($state['scope'] ?? null) !== $scope) $state = self::fresh($scope);
            update_option(self::STATE_OPTION, $state, false);
            $base = self::guarded($scope, $state['epoch'], $physical);
            $guard = fn(): bool => $base() && get_current_user_id() === $actor && current_user_can('manage_options');
            if (!$guard()) return new WP_Error('discussionbridge_cms_configuration', 'Current native administrator permission is required.');
            $existing = wp_next_scheduled(self::HOOK); $due = time() + 1;
            $state['last_wake'] = time();
            // Retest credentials on a deliberate wake without erasing the incident or publication ledger.
            if (($state['status'] ?? '') === 'configuration_required') $state['status'] = 'queued';
            if (!self::schedule($state, $due, $guard))
                return new WP_Error('discussionbridge_cms_schedule', 'Synchronization could not be scheduled.');
            return $existing !== false && $existing <= $due && $state['next_due'] === $existing ? 'already_queued' : 'queued';
        });
    }

    public static function refresh_catalog(string $kind): array|WP_Error
    {
        if (!current_user_can('manage_options') || !Settings::ready()) return new WP_Error('discussionbridge_cms_catalog_configuration', 'A configured connection and native administrator permission are required.');
        $scope = self::scope();
        return self::serialized(fn(callable $guard) => CmsCatalog::refresh($kind, $scope, fn(): bool => $guard() && Settings::ready() && self::scope() === $scope));
    }

    public static function status(): array
    {
        $state = self::state(); $journal = (new CmsJournal())->read();
        $runnable = Settings::ready() && Settings::service_author_id() > 0;
        $scheduled = $runnable ? wp_next_scheduled(self::HOOK) : false;
        $due = $scheduled === false ? null : $scheduled;
        // A missing overdue event remains a health warning, not a claimed future check.
        $expected = $due ?? (is_array($state) ? ($state['next_due'] ?? null) : null);
        $statuses = is_object($journal) ? get_object_vars($journal->attention_statuses ?? new \stdClass()) : [];
        $held = is_object($journal) ? get_object_vars($journal->attention_jobs ?? new \stdClass()) : [];
        $actual = 0; $unknown = 0;
        foreach ($held as $key => $_) { if (($statuses[$key] ?? null) === 'attention') $actual++; elseif (!array_key_exists($key, $statuses)) $unknown++; }
        return ['status' => !$runnable ? 'disabled' : (is_array($state) ? ($state['status'] ?? 'local_error') : 'queued'),
            'reason' => is_array($state) ? Settings::diagnostic_reason($state['reason'] ?? '') : '',
            'local_failures' => is_array($state) ? ($state['failures'] ?? 0) : 0,
            'last_attempt' => is_array($state) ? ($state['last_attempt'] ?? null) : null,
            'last_success' => is_array($state) ? ($state['last_success'] ?? null) : null,
            'next_due' => $due, 'overdue' => $runnable && is_int($expected) && time() > $expected + 600,
            'attention_count' => $actual, 'attention_unknown_count' => $unknown,
            'local_limitation_count' => is_object($journal) && isset($journal->local_issues) ? count(get_object_vars($journal->local_issues)) : 0];
    }
}
