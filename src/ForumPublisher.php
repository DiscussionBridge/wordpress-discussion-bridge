<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class ForumPublisher
{
    public const SYNC_HOOK = 'discussionbridge_forum_sync_batch';
    public const POLL_HOOK = 'discussionbridge_forum_sync_poll';
    public const STATE_OPTION = 'discussionbridge_forum_sync_state';
    private const LOCK_OPTION = 'discussionbridge_forum_sync_lock';
    private const MAX_AUTOMATIC_ATTEMPTS = 3;
    private const MAX_FAILURE_QUEUE = 1000;
    private const LOCK_TTL_SECONDS = 900;
    private const POLL_INTERVAL_SECONDS = 300;
    private const MAX_INCREMENTAL_WORK_PER_POLL = 20;
    private const INTEGRITY_AUDIT_INTERVAL_SECONDS = 86400;
    private const META_PREFIX = '_discussionbridge_forum_';
    private const ADOPTION_RESOURCE_META = '_discussionbridge_forum_adoption_resource_id';
    private const ADOPTION_TOPIC_META = '_discussionbridge_forum_adoption_topic_id';
    private static ?string $active_lock_token = null;

    /** @return array<string, mixed> */
    public static function initial_state(): array
    {
        return [
            'status' => 'idle',
            'run_id' => null,
            'scope_identity' => null,
            'phase' => 'topics',
            'cursor' => null,
            'revocation_cursor' => null,
            'processed' => 0,
            'created' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'held' => 0,
            'unpublished' => 0,
            'failed' => 0,
            'retry_events' => 0,
            'topic_failures' => [],
            'revocation_failures' => [],
            'resume_scan_after_failures' => false,
            'failure_codes' => [],
            'started_at' => null,
            'completed_at' => null,
            'initial_backfill_complete' => false,
            'incremental_processed' => 0,
            'last_incremental_poll_at' => null,
            'last_incremental_error' => null,
        ];
    }

    /** @return array<string, mixed>|WP_Error */
    public static function start(): array|WP_Error
    {
        $lock_token = wp_generate_uuid4();
        if (!self::acquire_lock($lock_token, 'start')) {
            return new WP_Error('discussionbridge_forum_sync_running', 'A DiscussionBridge forum synchronization batch is already running.');
        }
        try {
            if (!Settings::ready()) {
                return new WP_Error('discussionbridge_not_configured', 'DiscussionBridge is not configured.');
            }
            $client = new Client();
            $previous = self::state();
            $scope_identity = self::scope_identity();
            if (($previous['scope_identity'] ?? null) !== $scope_identity) {
                $previous = self::initial_state();
            }
            $current_catalog = $client->platform_catalog_status();
            if (is_wp_error($current_catalog)) {
                self::store_attention($current_catalog->get_error_code());
                return $current_catalog;
            }
            $expected_catalog_revision = self::bounded_string(
                $current_catalog['catalog_revision'] ?? null,
                64
            );
            $platform_catalog = PlatformCatalog::build();
            if (is_wp_error($platform_catalog)) {
                self::store_attention($platform_catalog->get_error_code());
                return $platform_catalog;
            }
            $catalog = $client->update_platform_catalog(
                $platform_catalog,
                $expected_catalog_revision !== '' ? $expected_catalog_revision : null
            );
            if (is_wp_error($catalog)) {
                self::store_attention($catalog->get_error_code());
                return $catalog;
            }

            $state = self::initial_state();
            $state['scope_identity'] = $scope_identity;
            $state['run_id'] = wp_generate_uuid4();
            $state['topic_failures'] = self::restartable_failures($previous['topic_failures']);
            $state['revocation_failures'] = self::restartable_failures($previous['revocation_failures']);
            if ($state['topic_failures'] !== []) {
                $state['phase'] = 'topic_retries';
                $state['resume_scan_after_failures'] = true;
            } elseif ($state['revocation_failures'] !== []) {
                $state['phase'] = 'revocation_retries';
                $state['resume_scan_after_failures'] = true;
            }
            $state['started_at'] = gmdate('c');
            $state['catalog_revision'] = self::bounded_string($catalog['catalog_revision'] ?? null, 64);
            if (($catalog['destination_mapping_state'] ?? null) !== 'current') {
                $state['status'] = 'awaiting_mapping';
                self::store($state);
                return $state;
            }
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return $state;
        } finally {
            self::release_lock($lock_token);
        }
    }

    /** @return array<string, mixed> */
    public static function state(): array
    {
        $state = get_option(self::STATE_OPTION, self::initial_state());
        return is_array($state) ? array_replace(self::initial_state(), $state) : self::initial_state();
    }

    public static function ensure_poll_scheduled(): void
    {
        if (!wp_next_scheduled(self::POLL_HOOK)) {
            wp_schedule_single_event(time() + self::POLL_INTERVAL_SECONDS, self::POLL_HOOK);
        }
    }

    public static function poll(): void
    {
        try {
            $state = self::state();
            if (!Settings::ready()) {
                return;
            }
            if (in_array($state['status'], ['queued', 'running'], true)) {
                $run_id = is_string($state['run_id'] ?? null) ? $state['run_id'] : '';
                if (!($state['initial_backfill_complete'] ?? false) && $run_id !== '') {
                    self::schedule($run_id);
                }
                return;
            }
            if (!($state['initial_backfill_complete'] ?? false)) {
                self::start();
                return;
            }
            self::run_incremental_queue();
        } finally {
            self::ensure_poll_scheduled();
        }
    }

    private static function run_incremental_queue(): void
    {
        $token = wp_generate_uuid4();
        if (!self::acquire_lock($token, 'incremental')) {
            return;
        }
        self::$active_lock_token = $token;
        $client = new Client();
        $state = self::state();
        try {
            $state['last_incremental_poll_at'] = gmdate('c');
            $state['last_incremental_error'] = null;
            for ($processed = 0; $processed < self::MAX_INCREMENTAL_WORK_PER_POLL; $processed++) {
                self::renew_lock();
                $claimed = $client->claim_publication_work();
                if (is_wp_error($claimed)) {
                    $state['last_incremental_error'] = $claimed->get_error_code();
                    break;
                }
                $work = $claimed['publication_work'] ?? null;
                if ($work === null) {
                    break;
                }

                $result = self::process_claimed_work($client, $work);
                if (is_wp_error($result)) {
                    $code = sanitize_key($result->get_error_code());
                    $detail = substr($result->get_error_message(), 0, 1000);
                    $reported = $client->fail_publication_work(
                        $code !== '' ? $code : 'wordpress_delivery_failed',
                        $detail
                    );
                    $state['last_incremental_error'] = is_wp_error($reported)
                        ? $reported->get_error_code()
                        : ($code !== '' ? $code : 'wordpress_delivery_failed');
                    $client->clear_publication_lease();
                    break;
                }

                $client->clear_publication_lease();
                $state['incremental_processed']++;
                $state['processed']++;
                $outcome = $result['outcome'] ?? 'unchanged';
                if (isset($state[$outcome]) && is_int($state[$outcome])) {
                    $state[$outcome]++;
                }
            }
            self::store($state);
        } finally {
            $client->clear_publication_lease();
            self::release_lock($token);
            self::$active_lock_token = null;
        }
    }

    /** @param array<string, mixed> $work
     *  @return array{outcome:string,post_id:int}|WP_Error
     */
    private static function process_claimed_work(Client $client, array $work): array|WP_Error
    {
        $action = $work['action'] ?? null;
        $topic_id = is_int($work['topic_id'] ?? null) ? $work['topic_id'] : 0;
        if ($topic_id <= 0 || !in_array($action, ['publish', 'unpublish'], true)) {
            return new WP_Error(
                'discussionbridge_invalid_publication_work',
                'DiscussionBridge returned invalid publication work.'
            );
        }

        if ($action === 'publish') {
            $detail = $client->source_topic($topic_id);
            if (is_wp_error($detail)) {
                return $detail;
            }
            $source = is_array($detail['source_topic'] ?? null) ? $detail['source_topic'] : null;
            return $source !== null
                ? self::materialize_topic($client, $source, $source)
                : new WP_Error(
                    'discussionbridge_source_topic_unavailable',
                    'The claimed Discourse topic is no longer available for publication.'
                );
        }

        $resource_id = is_string($work['resource_id'] ?? null) ? $work['resource_id'] : '';
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error(
                'discussionbridge_invalid_publication_work',
                'The claimed publication does not identify a valid Bridge Record.'
            );
        }
        $detail = $client->source_revocation($resource_id);
        if (is_wp_error($detail)) {
            return $detail;
        }
        $revocation = is_array($detail['publication_revocation'] ?? null)
            ? $detail['publication_revocation']
            : null;
        return $revocation !== null
            ? self::apply_revocation($client, $revocation)
            : new WP_Error(
                'discussionbridge_revocation_unavailable',
                'The claimed publication withdrawal is no longer available.'
            );
    }

    /** @param list<string> $args @param array<string, mixed> $assoc_args */
    public static function cli_sync(array $args = [], array $assoc_args = []): void
    {
        unset($args, $assoc_args);
        $result = self::start();
        if (is_wp_error($result)) {
            \WP_CLI::error($result->get_error_code());
        }
        \WP_CLI::success('DiscussionBridge forum synchronization queued.');
    }

    public static function run_batch(?string $scheduled_run_id = null): void
    {
        $state = self::state();
        $run_id = is_string($state['run_id']) ? $state['run_id'] : '';
        if ($run_id === '' && in_array($state['status'], ['queued', 'running'], true)
            && $scheduled_run_id === null) {
            $run_id = wp_generate_uuid4();
            $state['run_id'] = $run_id;
            self::store($state);
        }
        if ($run_id === '' || ($scheduled_run_id !== null && $scheduled_run_id !== $run_id)) {
            return;
        }
        $token = wp_generate_uuid4();
        if (!self::acquire_lock($token, $run_id)) {
            self::schedule($run_id);
            return;
        }
        self::$active_lock_token = $token;
        try {
            $state = self::state();
            if (($state['run_id'] ?? null) !== $run_id) {
                return;
            }
            if (!in_array($state['status'], ['queued', 'running'], true)) {
                return;
            }
            $state['status'] = 'running';
            self::store($state);
            $client = new Client();
            $catalog = $client->platform_catalog_status();
            if (is_wp_error($catalog)) {
                self::fail_run($state, $catalog->get_error_code());
                return;
            }
            if (($catalog['destination_mapping_state'] ?? null) !== 'current') {
                $state['status'] = 'awaiting_mapping';
                self::store($state);
                return;
            }
            $state['mapping_revision'] = self::bounded_string($catalog['destination_mapping_revision'] ?? null, 64);
            switch ($state['phase']) {
                case 'topic_retries':
                    self::run_topic_retries($client, $state);
                    break;
                case 'revocations':
                    self::run_revocations($client, $state);
                    break;
                case 'revocation_retries':
                    self::run_revocation_retries($client, $state);
                    break;
                default:
                    self::run_topics($client, $state);
            }
        } finally {
            self::release_lock($token);
            self::$active_lock_token = null;
        }
    }

    /** @param array<string, mixed> $state */
    private static function run_topics(Client $client, array $state): void
    {
        $payload = $client->source_topics(self::nullable_cursor($state['cursor']));
        if (is_wp_error($payload)) {
            self::fail_run($state, $payload->get_error_code());
            return;
        }
        $topics = $payload['source_topics'] ?? null;
        $pagination = $payload['pagination'] ?? null;
        if (!is_array($topics) || !is_array($pagination)) {
            self::fail_run($state, 'discussionbridge_invalid_source_feed');
            return;
        }

        foreach ($topics as $topic) {
            self::renew_lock();
            $topic_id = is_array($topic) ? (int) ($topic['topic_id'] ?? 0) : 0;
            $result = $topic_id > 0 ? self::materialize_topic($client, $topic) :
                new WP_Error('discussionbridge_invalid_source_topic');
            if (is_wp_error($result)) {
                self::queue_topic_failure($state, $topic_id, $result->get_error_code());
                continue;
            }
            self::remove_topic_failure($state, $topic_id);
            $state['processed']++;
            $outcome = $result['outcome'] ?? 'unchanged';
            if (isset($state[$outcome]) && is_int($state[$outcome])) {
                $state[$outcome]++;
            }
        }

        $next = self::bounded_cursor($pagination['next_cursor'] ?? null);
        if ($next !== null) {
            $state['cursor'] = $next;
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        $state['phase'] = $state['topic_failures'] === [] ? 'revocations' : 'topic_retries';
        $state['cursor'] = null;
        $state['status'] = 'queued';
        self::store($state);
        self::schedule((string) $state['run_id']);
    }

    /** @param array<string, mixed> $state */
    private static function run_topic_retries(Client $client, array $state): void
    {
        $index = self::next_retry_index($state['topic_failures']);
        if ($index === null) {
            if ($state['resume_scan_after_failures']) {
                $state['phase'] = 'topics';
                $state['resume_scan_after_failures'] = false;
            } else {
                $state['phase'] = 'revocations';
            }
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        $failure = $state['topic_failures'][$index];
        self::renew_lock();
        $topic_id = (int) ($failure['topic_id'] ?? 0);
        $detail = $topic_id > 0 ? $client->source_topic($topic_id) : new WP_Error('discussionbridge_invalid_source_topic');
        if (!is_wp_error($detail) && ($detail['eligible'] ?? null) === false) {
            array_splice($state['topic_failures'], $index, 1);
            $state['resume_scan_after_failures'] = true;
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        $source = is_array($detail) && is_array($detail['source_topic'] ?? null)
            ? $detail['source_topic']
            : null;
        $result = is_wp_error($detail)
            ? $detail
            : ($source !== null
                ? self::materialize_topic($client, $source, $source)
                : new WP_Error('discussionbridge_invalid_source_topic'));
        if (is_wp_error($result)) {
            self::advance_failure($state, 'topic_failures', $index, $result->get_error_code());
        } else {
            array_splice($state['topic_failures'], $index, 1);
            $state['processed']++;
            $outcome = $result['outcome'] ?? 'unchanged';
            if (isset($state[$outcome]) && is_int($state[$outcome])) {
                $state[$outcome]++;
            }
        }
        $state['status'] = 'queued';
        self::store($state);
        self::schedule((string) $state['run_id']);
    }

    /** @param array<string, mixed> $state */
    private static function run_revocations(Client $client, array $state): void
    {
        $payload = $client->source_revocations(self::nullable_cursor($state['revocation_cursor']));
        if (is_wp_error($payload)) {
            self::fail_run($state, $payload->get_error_code());
            return;
        }
        $revocations = $payload['publication_revocations'] ?? null;
        $pagination = $payload['pagination'] ?? null;
        if (!is_array($revocations) || !is_array($pagination)) {
            self::fail_run($state, 'discussionbridge_invalid_revocation_feed');
            return;
        }
        foreach ($revocations as $revocation) {
            self::renew_lock();
            $result = is_array($revocation) ? self::apply_revocation($client, $revocation) :
                new WP_Error('discussionbridge_invalid_revocation');
            if (is_wp_error($result)) {
                self::queue_revocation_failure($state, $revocation, $result->get_error_code());
            } elseif (($result['outcome'] ?? null) === 'unpublished') {
                $state['unpublished']++;
            }
        }
        $next = self::bounded_cursor($pagination['next_cursor'] ?? null);
        if ($next !== null) {
            $state['revocation_cursor'] = $next;
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        $state['revocation_cursor'] = null;
        if ($state['revocation_failures'] !== []) {
            $state['phase'] = 'revocation_retries';
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        self::complete_run($state);
    }

    /** @param array<string, mixed> $state */
    private static function run_revocation_retries(Client $client, array $state): void
    {
        $index = self::next_retry_index($state['revocation_failures']);
        if ($index === null) {
            if ($state['resume_scan_after_failures']) {
                $state['phase'] = 'topics';
                $state['resume_scan_after_failures'] = false;
                $state['status'] = 'queued';
                self::store($state);
                self::schedule((string) $state['run_id']);
                return;
            }
            self::complete_run($state);
            return;
        }
        $failure = $state['revocation_failures'][$index];
        self::renew_lock();
        $item = is_array($failure['item'] ?? null) ? $failure['item'] : null;
        $resource_id = self::bounded_string($failure['resource_id'] ?? null, 64);
        $current = wp_is_uuid($resource_id) ? $client->source_revocation($resource_id)
            : new WP_Error('discussionbridge_invalid_revocation');
        if (!is_wp_error($current) && ($current['revoked'] ?? null) === false) {
            array_splice($state['revocation_failures'], $index, 1);
            $state['resume_scan_after_failures'] = true;
            $state['status'] = 'queued';
            self::store($state);
            self::schedule((string) $state['run_id']);
            return;
        }
        $fresh_item = !is_wp_error($current) && is_array($current['publication_revocation'] ?? null)
            ? $current['publication_revocation']
            : $item;
        $result = is_wp_error($current)
            ? $current
            : ($fresh_item !== null
                ? self::apply_revocation($client, $fresh_item)
                : new WP_Error('discussionbridge_invalid_revocation'));
        if (is_wp_error($result)) {
            self::advance_failure($state, 'revocation_failures', $index, $result->get_error_code());
        } else {
            array_splice($state['revocation_failures'], $index, 1);
            if (($result['outcome'] ?? null) === 'unpublished') {
                $state['unpublished']++;
            }
        }
        $state['status'] = 'queued';
        self::store($state);
        self::schedule((string) $state['run_id']);
    }

    /** @param array<string, mixed> $item
     *  @return array{outcome:string,post_id:int}|WP_Error
     */
    private static function materialize_topic(Client $client, array $item, ?array $supplied_source = null): array|WP_Error
    {
        $topic_id = (int) ($item['topic_id'] ?? 0);
        $source_revision = self::bounded_string($item['source_revision'] ?? null, 255);
        $publication_revision = self::bounded_string($item['publication_revision'] ?? null, 64);
        $destination = $item['destination'] ?? null;
        $publication = is_array($item['publication'] ?? null) ? $item['publication'] : [];
        if ($topic_id <= 0 || $source_revision === '' || $publication_revision === '' || !is_array($destination)) {
            return new WP_Error('discussionbridge_invalid_source_topic');
        }
        if (($destination['state'] ?? null) !== 'ready') {
            return self::hold_unmappable_publication(
                $client,
                $topic_id,
                $source_revision,
                $publication_revision,
                $destination,
                $publication
            );
        }
        $skipped_post_id = $supplied_source === null ? self::skippable_post_id(
            $topic_id,
            $source_revision,
            $publication_revision,
            $destination,
            $publication
        ) : null;
        if ($skipped_post_id !== null) {
            return ['outcome' => 'unchanged', 'post_id' => $skipped_post_id];
        }
        if ($supplied_source === null) {
            $detail = $client->source_topic($topic_id);
            if (is_wp_error($detail) || !is_array($detail['source_topic'] ?? null)) {
                return is_wp_error($detail) ? $detail : new WP_Error('discussionbridge_invalid_source_topic');
            }
            $source = $detail['source_topic'];
        } else {
            $source = $supplied_source;
        }
        if (($source['source_revision'] ?? null) !== $source_revision
            || ($source['publication_revision'] ?? null) !== $publication_revision
            || !self::same_value($source['destination'] ?? null, $destination)
            || !self::same_value(
                is_array($source['publication'] ?? null) ? $source['publication'] : [],
                $publication
            )) {
            return new WP_Error('discussionbridge_source_topic_changed');
        }
        $publication = is_array($source['publication'] ?? null) ? $source['publication'] : [];

        $post_type = self::post_type($destination['destination_container_id'] ?? null);
        $title = self::bounded_string($source['title'] ?? null, 1000);
        $content = is_string($source['content_html'] ?? null) ? (string) $source['content_html'] : '';
        if ($post_type === '' || $title === '' || $content === '' || strlen($content) > 49152) {
            return new WP_Error('discussionbridge_invalid_source_content');
        }
        $content = wp_kses_post($content);
        $topic_url = self::bounded_url($source['topic_url'] ?? null);
        $source_author = self::source_author($source['author'] ?? null);
        if ($content === '') {
            return new WP_Error('discussionbridge_source_content_empty_after_sanitize');
        }
        if ($topic_url === '' || !Settings::topic_url_matches($topic_url, $topic_id)
            || $source_author === null) {
            return new WP_Error('discussionbridge_invalid_source_content');
        }
        $existing = self::find_post($topic_id, $publication);
        if (is_wp_error($existing)) {
            return $existing;
        }
        $creating = $existing === null;
        if ($creating && $publication !== []) {
            return new WP_Error('discussionbridge_native_publication_missing');
        }
        $post_id = $existing instanceof \WP_Post ? $existing->ID : 0;
        $adopting_legacy = !$creating
            && get_post_meta($post_id, self::META_PREFIX . 'connection_id', true) === ''
            && in_array(
                $publication['publication_program'] ?? null,
                ['legacy', 'forum_sync_pending', 'forum_sync'],
                true
            );
        if (!$creating && !$adopting_legacy && self::url_contract_changed($post_id, $destination)) {
            return new WP_Error('discussionbridge_native_url_migration_required');
        }
        if ($adopting_legacy && (
            $existing->post_type !== self::post_type($destination['destination_container_id'] ?? null)
            || ($publication['external_id'] ?? null) !== 'wordpress:post:' . $post_id
            || ($publication['canonical_url'] ?? null) !== get_permalink($post_id)
        )) {
            return new WP_Error('discussionbridge_legacy_adoption_mismatch');
        }
        $author_id = self::destination_author_id($destination);
        if ($author_id <= 0) {
            return new WP_Error('discussionbridge_destination_author_unavailable');
        }
        $mapping_revision = self::bounded_string($destination['mapping_revision'] ?? null, 64);
        $comments_mode = self::comments_mode($destination['presentation_mode'] ?? null);
        if ($mapping_revision === '' || $comments_mode === null) {
            return new WP_Error('discussionbridge_invalid_destination_plan');
        }
        $receiver_healthy = ($publication['destination_state'] ?? null) === 'healthy'
            && ($publication['acknowledged_publication_revision'] ?? null) === $publication_revision;
        if (!$creating && $receiver_healthy && self::native_state_matches(
            $existing,
            $publication,
            $destination,
            $source_revision,
            $publication_revision,
            $mapping_revision,
            $title,
            $content,
            $author_id,
            $topic_url,
            $source_author,
            $comments_mode
        )) {
            update_post_meta($post_id, self::META_PREFIX . 'integrity_audited_at', (string) time());
            return ['outcome' => 'unchanged', 'post_id' => $post_id];
        }
        $snapshot = $creating ? null : self::native_snapshot($existing);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $data = [
            'ID' => $post_id,
            'post_type' => $post_type,
            'post_status' => $creating ? 'draft' : 'publish',
            'post_title' => $title,
            'post_content' => $content,
            'post_author' => $author_id,
        ];
        if ($creating) {
            $slug = self::initial_slug($destination, $title, $topic_id);
            if ($slug !== null) {
                $data['post_name'] = $slug;
            }
        }
        $saved = $creating ? wp_insert_post($data, true) : $post_id;
        if (is_wp_error($saved) || (int) $saved <= 0) {
            return is_wp_error($saved) ? $saved : new WP_Error('discussionbridge_native_write_failed');
        }
        $post_id = (int) $saved;
        update_post_meta($post_id, self::META_PREFIX . 'connection_id', Settings::connection_id());
        update_post_meta($post_id, self::META_PREFIX . 'topic_id', (string) $topic_id);
        if ($creating) {
            update_post_meta($post_id, self::META_PREFIX . 'destination', self::stable_json($destination));
            update_post_meta($post_id, self::META_PREFIX . 'status', 'pending');
        }
        $canonical_url = self::native_canonical_url($post_id);
        if ($canonical_url === '') {
            if (!$creating) {
                self::restore_native_snapshot($post_id, $snapshot);
            }
            return new WP_Error('discussionbridge_native_url_unavailable');
        }
        if ($adopting_legacy) {
            $adoption_resource_id = self::bounded_string($publication['resource_id'] ?? null, 64);
            if (!wp_is_uuid($adoption_resource_id)) {
                if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                    return new WP_Error('discussionbridge_native_rollback_failed');
                }
                return new WP_Error('discussionbridge_legacy_adoption_invalid');
            }
            update_post_meta($post_id, self::ADOPTION_RESOURCE_META, $adoption_resource_id);
            update_post_meta($post_id, self::ADOPTION_TOPIC_META, (string) $topic_id);
            if (get_post_meta($post_id, self::ADOPTION_RESOURCE_META, true) !== $adoption_resource_id
                || get_post_meta($post_id, self::ADOPTION_TOPIC_META, true) !== (string) $topic_id) {
                if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                    return new WP_Error('discussionbridge_native_rollback_failed');
                }
                return new WP_Error('discussionbridge_legacy_adoption_marker_failed');
            }
        }
        $resolve = $client->resolve_source_topic($topic_id, [
            'source_revision' => $source_revision,
            'publication_revision' => $publication_revision,
            'mapping_revision' => $mapping_revision,
            'destination' => $destination,
            'external_id' => 'wordpress:post:' . $post_id,
            'canonical_url' => $canonical_url,
            'lane' => Settings::lane(),
            'native_materialization' => true,
        ]);
        if (is_wp_error($resolve)) {
            if (!$creating && !self::restore_native_snapshot($post_id, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return $resolve;
        }
        $resource_id = self::bounded_string($resolve['resource_id'] ?? null, 64);
        $expected_resource_id = self::bounded_string($publication['resource_id'] ?? null, 64);
        if (!self::resolve_response_matches(
            $resolve,
            $resource_id,
            $publication_revision,
            $mapping_revision,
            $destination,
            'wordpress:post:' . $post_id,
            $canonical_url
        ) || ($publication !== [] && $resource_id !== $expected_resource_id)) {
            if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return new WP_Error('discussionbridge_invalid_resolve_response');
        }
        if (!$creating) {
            $saved = wp_update_post($data);
            if (is_wp_error($saved) || (int) $saved !== $post_id) {
                if (!self::restore_native_snapshot($post_id, $snapshot)) {
                    return new WP_Error('discussionbridge_native_rollback_failed');
                }
                return is_wp_error($saved) ? $saved : new WP_Error('discussionbridge_native_write_failed');
            }
        }
        $terms = self::apply_terms($post_id, $destination['destination_terms'] ?? []);
        if (is_wp_error($terms)) {
            if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return $terms;
        }
        self::store_pending_state(
            $post_id,
            $topic_id,
            $resource_id,
            $source_revision,
            $publication_revision,
            $mapping_revision,
            $destination,
            $canonical_url,
            $topic_url,
            $source_author,
            $comments_mode
        );
        if (!self::pending_state_matches(
            $post_id,
            $topic_id,
            $resource_id,
            $source_revision,
            $publication_revision,
            $mapping_revision,
            $destination,
            $canonical_url,
            $topic_url,
            $source_author,
            $comments_mode
        )) {
            if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return new WP_Error('discussionbridge_native_state_write_failed');
        }
        if ($creating) {
            $published = wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            if (is_wp_error($published) || (int) $published !== $post_id || get_permalink($post_id) !== $canonical_url) {
                if (!self::restore_after_failure($post_id, true, null)) {
                    return new WP_Error('discussionbridge_native_rollback_failed');
                }
                return new WP_Error('discussionbridge_native_publish_failed');
            }
        }
        $outcome = $creating ? 'created' : 'updated';
        $acknowledgement = $client->acknowledge_publication($resource_id, [
            'source_revision' => $source_revision,
            'publication_revision' => $publication_revision,
            'mapping_revision' => $mapping_revision,
            'destination' => $destination,
            'native_destination' => [
                'external_id' => 'wordpress:post:' . $post_id,
                'canonical_url' => $canonical_url,
            ],
            'outcome' => $outcome,
        ]);
        if (is_wp_error($acknowledgement) || !self::acknowledgement_response_matches(
            $acknowledgement,
            $resource_id,
            'healthy',
            $publication_revision,
            $source_revision,
            $mapping_revision
        )) {
            if (!self::restore_after_failure($post_id, $creating, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return is_wp_error($acknowledgement)
                ? $acknowledgement
                : new WP_Error('discussionbridge_invalid_acknowledgement_response');
        }
        update_post_meta($post_id, self::META_PREFIX . 'status', 'healthy');
        update_post_meta($post_id, self::META_PREFIX . 'integrity_audited_at', (string) time());
        delete_post_meta($post_id, self::ADOPTION_RESOURCE_META);
        delete_post_meta($post_id, self::ADOPTION_TOPIC_META);
        return ['outcome' => $outcome, 'post_id' => $post_id];
    }

    /** @param array<string, mixed> $item
     *  @return array{outcome:string,post_id:int}|WP_Error
     */
    private static function apply_revocation(Client $client, array $item): array|WP_Error
    {
        $resource_id = self::bounded_string($item['resource_id'] ?? null, 64);
        $topic_id = (int) ($item['topic_id'] ?? 0);
        $publication_revision = self::bounded_string($item['publication_revision'] ?? null, 64);
        if (!wp_is_uuid($resource_id) || $topic_id <= 0 || $publication_revision === '') {
            return new WP_Error('discussionbridge_invalid_revocation');
        }
        $post = self::find_post($topic_id, $item);
        if (is_wp_error($post)) {
            return $post;
        }
        if (!$post instanceof \WP_Post) {
            return new WP_Error('discussionbridge_revocation_post_missing');
        }
        $canonical_url = (string) get_post_meta($post->ID, self::META_PREFIX . 'canonical_url', true);
        if ($canonical_url === '' || get_permalink($post->ID) !== $canonical_url) {
            return new WP_Error('discussionbridge_native_url_unavailable');
        }
        if (($item['acknowledged_publication_revision'] ?? null) === $publication_revision
            && in_array(($item['last_delivery_outcome'] ?? null), ['held', 'unpublished'], true)
            && $post->post_status === 'draft') {
            return ['outcome' => 'unchanged', 'post_id' => $post->ID];
        }
        $snapshot = self::native_snapshot($post);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $updated = wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
        if (is_wp_error($updated) || (int) $updated !== $post->ID) {
            return new WP_Error('discussionbridge_unpublish_failed');
        }
        $ack = $client->acknowledge_publication($resource_id, [
            'publication_revision' => $publication_revision,
            'native_destination' => [
                'external_id' => 'wordpress:post:' . $post->ID,
                'canonical_url' => $canonical_url,
            ],
            'outcome' => 'unpublished',
        ]);
        if (is_wp_error($ack) || !self::acknowledgement_response_matches(
            $ack,
            $resource_id,
            'held',
            $publication_revision
        )) {
            if (!self::restore_native_snapshot($post->ID, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return is_wp_error($ack) ? $ack : new WP_Error('discussionbridge_invalid_acknowledgement_response');
        }
        update_post_meta($post->ID, self::META_PREFIX . 'status', 'held');
        return ['outcome' => 'unpublished', 'post_id' => $post->ID];
    }

    /** @param array<string, mixed> $destination
     *  @param array<string, mixed> $publication
     *  @return array{outcome:string,post_id:int}|WP_Error
     */
    private static function hold_unmappable_publication(
        Client $client,
        int $topic_id,
        string $source_revision,
        string $publication_revision,
        array $destination,
        array $publication
    ): array|WP_Error {
        if (($publication['publication_program'] ?? null) === 'legacy') {
            return ['outcome' => 'held', 'post_id' => 0];
        }
        if ($publication === []) {
            return ['outcome' => 'held', 'post_id' => 0];
        }
        $post = self::find_post($topic_id, $publication);
        if (is_wp_error($post)) {
            return $post;
        }
        if (!$post instanceof \WP_Post) {
            return new WP_Error('discussionbridge_native_publication_missing');
        }
        $resource_id = self::bounded_string($publication['resource_id'] ?? null, 64);
        $canonical_url = self::bounded_url($publication['canonical_url'] ?? null);
        $mapping_revision = self::bounded_string($destination['mapping_revision'] ?? null, 64);
        if (!wp_is_uuid($resource_id) || $canonical_url === '' || $mapping_revision === ''
            || get_permalink($post->ID) !== $canonical_url) {
            return new WP_Error('discussionbridge_invalid_publication_hold');
        }
        if (($publication['acknowledged_publication_revision'] ?? null) === $publication_revision
            && ($publication['last_delivery_outcome'] ?? null) === 'held'
            && $post->post_status === 'draft') {
            return ['outcome' => 'unchanged', 'post_id' => $post->ID];
        }
        $snapshot = self::native_snapshot($post);
        if (is_wp_error($snapshot)) {
            return $snapshot;
        }
        $updated = wp_update_post(['ID' => $post->ID, 'post_status' => 'draft']);
        if (is_wp_error($updated) || (int) $updated !== $post->ID) {
            return new WP_Error('discussionbridge_hold_failed');
        }
        $ack = $client->acknowledge_publication($resource_id, [
            'source_revision' => $source_revision,
            'publication_revision' => $publication_revision,
            'mapping_revision' => $mapping_revision,
            'destination' => $destination,
            'native_destination' => [
                'external_id' => 'wordpress:post:' . $post->ID,
                'canonical_url' => $canonical_url,
            ],
            'outcome' => 'held',
        ]);
        if (is_wp_error($ack) || !self::acknowledgement_response_matches(
            $ack,
            $resource_id,
            'held',
            $publication_revision,
            $source_revision,
            $mapping_revision
        )) {
            if (!self::restore_native_snapshot($post->ID, $snapshot)) {
                return new WP_Error('discussionbridge_native_rollback_failed');
            }
            return is_wp_error($ack) ? $ack : new WP_Error('discussionbridge_invalid_acknowledgement_response');
        }
        update_post_meta($post->ID, self::META_PREFIX . 'status', 'held');
        return ['outcome' => 'held', 'post_id' => $post->ID];
    }

    /** @param array<string, mixed> $publication */
    private static function find_post(int $topic_id, array $publication = []): \WP_Post|null|WP_Error
    {
        $ids = get_posts([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 3,
            'fields' => 'ids',
            'meta_key' => self::META_PREFIX . 'topic_id',
            'meta_value' => (string) $topic_id,
        ]);
        $matches = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ((string) get_post_meta($id, self::META_PREFIX . 'connection_id', true) === Settings::connection_id()) {
                $matches[] = $id;
            }
        }
        if (count($matches) > 1) {
            return new WP_Error('discussionbridge_native_identity_collision');
        }
        if ($matches !== []) {
            return get_post($matches[0]);
        }
        $program = $publication['publication_program'] ?? null;
        if (!in_array($program, ['legacy', 'forum_sync_pending', 'forum_sync'], true)) {
            return null;
        }
        $resource_id = self::bounded_string($publication['resource_id'] ?? null, 64);
        if (!wp_is_uuid($resource_id)) {
            return new WP_Error('discussionbridge_legacy_adoption_invalid');
        }
        $meta_key = $program === 'legacy' ? '_discussionbridge_resource_id' : self::ADOPTION_RESOURCE_META;
        $legacy_ids = get_posts([
            'post_type' => 'any',
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 3,
            'fields' => 'ids',
            'meta_key' => $meta_key,
            'meta_value' => $resource_id,
        ]);
        if (count($legacy_ids) > 1) {
            return new WP_Error('discussionbridge_native_identity_collision');
        }
        if ($legacy_ids === []) {
            return null;
        }
        $legacy_id = (int) $legacy_ids[0];
        if (in_array($program, ['forum_sync_pending', 'forum_sync'], true) &&
            get_post_meta($legacy_id, self::ADOPTION_TOPIC_META, true) !== (string) $topic_id) {
            return new WP_Error('discussionbridge_legacy_adoption_invalid');
        }
        return get_post($legacy_id);
    }

    /** @param mixed $raw_terms */
    private static function apply_terms(int $post_id, mixed $raw_terms): true|WP_Error
    {
        $grouped = self::grouped_terms($raw_terms);
        if (is_wp_error($grouped)) {
            return $grouped;
        }
        if (function_exists('wp_set_object_terms')) {
            foreach (['category', 'post_tag'] as $taxonomy) {
                $result = wp_set_object_terms($post_id, $grouped[$taxonomy], $taxonomy, false);
                if (is_wp_error($result)) {
                    return $result;
                }
            }
        }
        return true;
    }

    /** @return array{category:list<int>,post_tag:list<int>}|WP_Error */
    private static function grouped_terms(mixed $raw_terms): array|WP_Error
    {
        if (!is_array($raw_terms)) {
            return new WP_Error('discussionbridge_invalid_destination_terms');
        }
        $grouped = ['category' => [], 'post_tag' => []];
        foreach ($raw_terms as $term) {
            if (!is_array($term)) {
                return new WP_Error('discussionbridge_invalid_destination_terms');
            }
            $taxonomy = (string) ($term['destination_taxonomy_id'] ?? '');
            $term_id = (string) ($term['destination_term_id'] ?? '');
            if (!in_array($taxonomy, ['category', 'post_tag'], true)
                || preg_match('/^' . preg_quote($taxonomy, '/') . ':([1-9][0-9]*)$/', $term_id, $match) !== 1) {
                return new WP_Error('discussionbridge_invalid_destination_terms');
            }
            $grouped[$taxonomy][] = (int) $match[1];
        }
        foreach ($grouped as &$ids) {
            $ids = array_values(array_unique($ids));
            sort($ids);
        }
        unset($ids);
        return $grouped;
    }

    /** @param array<string, mixed> $destination */
    private static function store_pending_state(
        int $post_id,
        int $topic_id,
        string $resource_id,
        string $source_revision,
        string $publication_revision,
        string $mapping_revision,
        array $destination,
        string $canonical_url,
        string $topic_url,
        array $source_author,
        string $comments_mode
    ): void {
        update_post_meta($post_id, self::META_PREFIX . 'connection_id', Settings::connection_id());
        update_post_meta($post_id, self::META_PREFIX . 'topic_id', (string) $topic_id);
        update_post_meta($post_id, self::META_PREFIX . 'resource_id', $resource_id);
        update_post_meta($post_id, self::META_PREFIX . 'source_revision', $source_revision);
        update_post_meta($post_id, self::META_PREFIX . 'publication_revision', $publication_revision);
        update_post_meta($post_id, self::META_PREFIX . 'mapping_revision', $mapping_revision);
        update_post_meta($post_id, self::META_PREFIX . 'destination', self::stable_json($destination));
        update_post_meta($post_id, self::META_PREFIX . 'canonical_url', $canonical_url);
        update_post_meta($post_id, self::META_PREFIX . 'topic_url', $topic_url);
        update_post_meta($post_id, self::META_PREFIX . 'source_author', self::stable_json($source_author));
        update_post_meta($post_id, self::META_PREFIX . 'source_profile_url', $source_author['profile_url']);
        update_post_meta($post_id, Presentation::COMMENTS_MODE_META, $comments_mode);
        update_post_meta($post_id, self::META_PREFIX . 'status', 'pending');
    }

    private static function post_type(mixed $value): string
    {
        if (!is_string($value) || preg_match('/^post_type:([a-z0-9_-]{1,20})$/', $value, $match) !== 1) {
            return '';
        }
        $available = array_values(array_map('strval', get_post_types(['public' => true], 'names')));
        return in_array($match[1], $available, true) && $match[1] !== 'attachment' ? $match[1] : '';
    }

    /** @param array<string, mixed> $destination */
    private static function destination_author_id(array $destination): int
    {
        $value = $destination['destination_author_id'] ?? null;
        if (!in_array(($destination['authorship_policy'] ?? null), ['fixed', 'service_author'], true)
            || !is_string($value) || preg_match('/^user:([1-9][0-9]*)$/', $value, $match) !== 1) {
            return 0;
        }
        $user = get_user_by('id', (int) $match[1]);
        return $user instanceof \WP_User && user_can($user, 'publish_posts') ? (int) $user->ID : 0;
    }

    /** @param array<string, mixed> $destination */
    private static function initial_slug(array $destination, string $title, int $topic_id): ?string
    {
        return match ((string) ($destination['slug_policy'] ?? 'platform_default')) {
            'source_title' => sanitize_title($title),
            'topic_id' => 'discourse-topic-' . $topic_id,
            'platform_default' => null,
            default => null,
        };
    }

    /** @param array<string, mixed> $destination */
    private static function url_contract_changed(int $post_id, array $destination): bool
    {
        $stored = json_decode(
            (string) get_post_meta($post_id, self::META_PREFIX . 'destination', true),
            true
        );
        if (!is_array($stored)) {
            return true;
        }
        return ($stored['destination_container_id'] ?? null) !== ($destination['destination_container_id'] ?? null)
            || ($stored['slug_policy'] ?? 'platform_default') !== ($destination['slug_policy'] ?? 'platform_default');
    }

    private static function comments_mode(mixed $value): ?string
    {
        return match ($value) {
            'simple', 'full' => $value,
            'fullInteractive' => 'interactive',
            'native' => 'none',
            default => null,
        };
    }

    private static function bounded_url(mixed $value): string
    {
        return is_string($value) && strlen($value) <= 2048 ? $value : '';
    }

    private static function native_canonical_url(int $post_id): string
    {
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post) {
            return '';
        }
        if (!in_array($post->post_status, ['auto-draft', 'draft', 'pending', 'future'], true)) {
            return self::bounded_url(get_permalink($post));
        }

        if (!function_exists('get_sample_permalink')) {
            require_once ABSPATH . 'wp-admin/includes/post.php';
        }
        $sample = get_sample_permalink($post);
        if (!is_array($sample) || count($sample) !== 2
            || !is_string($sample[0]) || !is_string($sample[1]) || $sample[1] === '') {
            return '';
        }
        $url = str_replace(['%postname%', '%pagename%'], $sample[1], $sample[0]);
        return self::bounded_url($url);
    }

    /** @return array{username:string,name:string,profile_url:string}|null */
    private static function source_author(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }
        $username = self::bounded_string($value['username'] ?? null, 255);
        $name = self::bounded_string($value['name'] ?? null, 255);
        $profile_url = self::bounded_url($value['profile_url'] ?? null);
        return $username !== '' && $name !== '' && $profile_url !== ''
            ? ['username' => $username, 'name' => $name, 'profile_url' => $profile_url]
            : null;
    }

    /** @param array<string, mixed> $publication
     *  @param array<string, mixed> $destination
     *  @param array{username:string,name:string} $source_author
     */
    private static function native_state_matches(
        \WP_Post $post,
        array $publication,
        array $destination,
        string $source_revision,
        string $publication_revision,
        string $mapping_revision,
        string $title,
        string $content,
        int $author_id,
        string $topic_url,
        array $source_author,
        string $comments_mode
    ): bool {
        $external_id = 'wordpress:post:' . $post->ID;
        $canonical_url = (string) ($publication['canonical_url'] ?? '');
        $resource_id = (string) ($publication['resource_id'] ?? '');
        return ($publication['external_id'] ?? null) === $external_id
            && wp_is_uuid($resource_id)
            && $post->post_status === 'publish'
            && $post->post_type === self::post_type($destination['destination_container_id'] ?? null)
            && $post->post_title === $title
            && $post->post_content === $content
            && $post->post_author === $author_id
            && get_permalink($post->ID) === $canonical_url
            && get_post_meta($post->ID, self::META_PREFIX . 'connection_id', true) === Settings::connection_id()
            && get_post_meta($post->ID, self::META_PREFIX . 'resource_id', true) === $resource_id
            && get_post_meta($post->ID, self::META_PREFIX . 'source_revision', true) === $source_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'publication_revision', true) === $publication_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'mapping_revision', true) === $mapping_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'destination', true) === self::stable_json($destination)
            && get_post_meta($post->ID, self::META_PREFIX . 'canonical_url', true) === $canonical_url
            && get_post_meta($post->ID, self::META_PREFIX . 'topic_url', true) === $topic_url
            && get_post_meta($post->ID, self::META_PREFIX . 'source_author', true) === self::stable_json($source_author)
            && get_post_meta($post->ID, self::META_PREFIX . 'source_profile_url', true) === $source_author['profile_url']
            && get_post_meta($post->ID, Presentation::COMMENTS_MODE_META, true) === $comments_mode
            && get_post_meta($post->ID, self::META_PREFIX . 'status', true) === 'healthy'
            && self::native_terms_match($post->ID, $destination['destination_terms'] ?? []);
    }

    /** @param array<string, mixed> $destination @param array<string, mixed> $publication */
    private static function skippable_post_id(
        int $topic_id,
        string $source_revision,
        string $publication_revision,
        array $destination,
        array $publication
    ): ?int {
        if (($publication['publication_program'] ?? null) !== 'forum_sync'
            || ($publication['destination_state'] ?? null) !== 'healthy'
            || ($publication['acknowledged_publication_revision'] ?? null) !== $publication_revision) {
            return null;
        }
        $post = self::find_post($topic_id, $publication);
        if (!$post instanceof \WP_Post) {
            return null;
        }
        $audited_at = (int) get_post_meta($post->ID, self::META_PREFIX . 'integrity_audited_at', true);
        if ($audited_at < time() - self::INTEGRITY_AUDIT_INTERVAL_SECONDS) {
            return null;
        }
        $mapping_revision = self::bounded_string($destination['mapping_revision'] ?? null, 64);
        $matches = get_post_meta($post->ID, self::META_PREFIX . 'status', true) === 'healthy'
            && get_post_meta($post->ID, self::META_PREFIX . 'source_revision', true) === $source_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'publication_revision', true) === $publication_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'mapping_revision', true) === $mapping_revision
            && get_post_meta($post->ID, self::META_PREFIX . 'destination', true) === self::stable_json($destination)
            && get_post_meta($post->ID, self::META_PREFIX . 'resource_id', true) === ($publication['resource_id'] ?? null)
            && get_post_meta($post->ID, self::META_PREFIX . 'canonical_url', true) === ($publication['canonical_url'] ?? null);
        return $matches ? $post->ID : null;
    }

    /** @param array<string, mixed> $destination @param array<string, string> $source_author */
    private static function pending_state_matches(
        int $post_id,
        int $topic_id,
        string $resource_id,
        string $source_revision,
        string $publication_revision,
        string $mapping_revision,
        array $destination,
        string $canonical_url,
        string $topic_url,
        array $source_author,
        string $comments_mode
    ): bool {
        return get_post_meta($post_id, self::META_PREFIX . 'connection_id', true) === Settings::connection_id()
            && get_post_meta($post_id, self::META_PREFIX . 'topic_id', true) === (string) $topic_id
            && get_post_meta($post_id, self::META_PREFIX . 'resource_id', true) === $resource_id
            && get_post_meta($post_id, self::META_PREFIX . 'source_revision', true) === $source_revision
            && get_post_meta($post_id, self::META_PREFIX . 'publication_revision', true) === $publication_revision
            && get_post_meta($post_id, self::META_PREFIX . 'mapping_revision', true) === $mapping_revision
            && get_post_meta($post_id, self::META_PREFIX . 'destination', true) === self::stable_json($destination)
            && get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true) === $canonical_url
            && get_post_meta($post_id, self::META_PREFIX . 'topic_url', true) === $topic_url
            && get_post_meta($post_id, self::META_PREFIX . 'source_author', true) === self::stable_json($source_author)
            && get_post_meta($post_id, self::META_PREFIX . 'source_profile_url', true) === $source_author['profile_url']
            && get_post_meta($post_id, Presentation::COMMENTS_MODE_META, true) === $comments_mode
            && get_post_meta($post_id, self::META_PREFIX . 'status', true) === 'pending';
    }

    private static function native_terms_match(int $post_id, mixed $raw_terms): bool
    {
        $expected = self::grouped_terms($raw_terms);
        if (is_wp_error($expected)) {
            return false;
        }
        foreach (['category', 'post_tag'] as $taxonomy) {
            $actual = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($actual)) {
                return false;
            }
            $actual = array_values(array_unique(array_map('intval', is_array($actual) ? $actual : [])));
            sort($actual);
            if ($actual !== $expected[$taxonomy]) {
                return false;
            }
        }
        return true;
    }

    /** @return array<string, mixed>|WP_Error */
    private static function native_snapshot(\WP_Post $post): array|WP_Error
    {
        $meta_keys = [
            'connection_id', 'topic_id', 'resource_id', 'source_revision', 'publication_revision',
            'mapping_revision', 'destination', 'canonical_url', 'topic_url', 'source_author',
            'source_profile_url', 'integrity_audited_at', 'status',
        ];
        $meta = [];
        foreach ($meta_keys as $key) {
            $meta[$key] = get_post_meta($post->ID, self::META_PREFIX . $key, true);
        }
        $meta['comments_mode'] = get_post_meta($post->ID, Presentation::COMMENTS_MODE_META, true);
        $terms = [
            'category' => wp_get_object_terms($post->ID, 'category', ['fields' => 'ids']),
            'post_tag' => wp_get_object_terms($post->ID, 'post_tag', ['fields' => 'ids']),
        ];
        foreach ($terms as $value) {
            if (is_wp_error($value)) {
                return $value;
            }
        }
        return [
            'post' => clone $post,
            'meta' => $meta,
            'terms' => $terms,
        ];
    }

    /** @param array<string, mixed>|null $snapshot */
    private static function restore_after_failure(int $post_id, bool $creating, ?array $snapshot): bool
    {
        if ($creating) {
            $updated = wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            if (is_wp_error($updated) || (int) $updated !== $post_id) {
                return false;
            }
            update_post_meta($post_id, self::META_PREFIX . 'status', 'pending');
            return get_post($post_id)?->post_status === 'draft'
                && get_post_meta($post_id, self::META_PREFIX . 'status', true) === 'pending';
        }
        return self::restore_native_snapshot($post_id, $snapshot);
    }

    /** @param array<string, mixed>|null $snapshot */
    private static function restore_native_snapshot(int $post_id, ?array $snapshot): bool
    {
        if (!is_array($snapshot) || !$snapshot['post'] instanceof \WP_Post) {
            return false;
        }
        $post = $snapshot['post'];
        $updated = wp_update_post([
            'ID' => $post_id,
            'post_type' => $post->post_type,
            'post_status' => $post->post_status,
            'post_name' => $post->post_name,
            'post_title' => $post->post_title,
            'post_content' => $post->post_content,
            'post_author' => $post->post_author,
        ]);
        if (is_wp_error($updated) || (int) $updated !== $post_id) {
            return false;
        }
        foreach (['category', 'post_tag'] as $taxonomy) {
            $terms = is_array($snapshot['terms'][$taxonomy] ?? null) ? $snapshot['terms'][$taxonomy] : [];
            $expected_terms = array_map('intval', $terms);
            sort($expected_terms);
            $restored = wp_set_object_terms($post_id, $expected_terms, $taxonomy, false);
            if (is_wp_error($restored)) {
                return false;
            }
            $actual_terms = wp_get_object_terms($post_id, $taxonomy, ['fields' => 'ids']);
            if (is_wp_error($actual_terms)) {
                return false;
            }
            $actual_terms = array_map('intval', is_array($actual_terms) ? $actual_terms : []);
            sort($actual_terms);
            if ($actual_terms !== $expected_terms) {
                return false;
            }
        }
        foreach ($snapshot['meta'] as $key => $value) {
            $meta_key = $key === 'comments_mode' ? Presentation::COMMENTS_MODE_META : self::META_PREFIX . $key;
            if ($value === '') {
                delete_post_meta($post_id, $meta_key);
            } else {
                update_post_meta($post_id, $meta_key, $value);
            }
            if (get_post_meta($post_id, $meta_key, true) !== $value) {
                return false;
            }
        }
        $restored_post = get_post($post_id);
        return $restored_post instanceof \WP_Post
            && $restored_post->post_type === $post->post_type
            && $restored_post->post_status === $post->post_status
            && $restored_post->post_name === $post->post_name
            && $restored_post->post_title === $post->post_title
            && $restored_post->post_content === $post->post_content
            && (int) $restored_post->post_author === (int) $post->post_author;
    }

    private static function acquire_lock(string $token, string $run_id): bool
    {
        $existing = get_option(self::LOCK_OPTION, null);
        if (is_array($existing) && (int) ($existing['expires_at'] ?? 0) <= time()) {
            delete_option(self::LOCK_OPTION);
        }
        $lease = [
            'token' => $token,
            'run_id' => $run_id,
            'expires_at' => time() + self::LOCK_TTL_SECONDS,
        ];
        if (add_option(self::LOCK_OPTION, $lease, '', false)) {
            return true;
        }
        return false;
    }

    private static function release_lock(string $token): void
    {
        $lease = get_option(self::LOCK_OPTION, null);
        if (is_array($lease) && ($lease['token'] ?? null) === $token) {
            delete_option(self::LOCK_OPTION);
        }
    }

    private static function renew_lock(): void
    {
        $token = self::$active_lock_token;
        if ($token === null) {
            return;
        }
        $lease = get_option(self::LOCK_OPTION, null);
        if (is_array($lease) && ($lease['token'] ?? null) === $token) {
            $lease['expires_at'] = time() + self::LOCK_TTL_SECONDS;
            update_option(self::LOCK_OPTION, $lease, false);
        }
    }

    private static function schedule(string $run_id): void
    {
        $args = [$run_id];
        if (!wp_next_scheduled(self::SYNC_HOOK, $args)) {
            wp_schedule_single_event(time() + 1, self::SYNC_HOOK, $args);
        }
    }

    /** @param mixed $raw
     *  @return list<array<string, mixed>>
     */
    private static function restartable_failures(mixed $raw): array
    {
        $result = [];
        foreach (is_array($raw) ? $raw : [] as $failure) {
            if (!is_array($failure) || count($result) >= self::MAX_FAILURE_QUEUE) {
                continue;
            }
            $failure['attempts'] = 0;
            $failure['final'] = false;
            $result[] = $failure;
        }
        return $result;
    }

    /** @param array<string, mixed> $state */
    private static function queue_topic_failure(array &$state, int $topic_id, string $code): void
    {
        if ($topic_id <= 0 || count($state['topic_failures']) >= self::MAX_FAILURE_QUEUE) {
            self::record_failure($state, 'discussionbridge_topic_failure_queue_full');
            return;
        }
        foreach ($state['topic_failures'] as $index => $failure) {
            if ((int) ($failure['topic_id'] ?? 0) === $topic_id) {
                $state['topic_failures'][$index]['code'] = sanitize_key($code);
                return;
            }
        }
        $state['topic_failures'][] = [
            'topic_id' => $topic_id,
            'attempts' => 1,
            'code' => sanitize_key($code),
            'final' => false,
        ];
        $state['retry_events']++;
    }

    /** @param array<string, mixed> $state
     *  @param mixed $item
     */
    private static function queue_revocation_failure(array &$state, mixed $item, string $code): void
    {
        if (!is_array($item) || strlen(self::stable_json($item)) > 8192
            || count($state['revocation_failures']) >= self::MAX_FAILURE_QUEUE) {
            self::record_failure($state, 'discussionbridge_revocation_failure_queue_full');
            return;
        }
        $resource_id = (string) ($item['resource_id'] ?? '');
        foreach ($state['revocation_failures'] as $index => $failure) {
            if (($failure['resource_id'] ?? null) === $resource_id) {
                $state['revocation_failures'][$index]['code'] = sanitize_key($code);
                return;
            }
        }
        $state['revocation_failures'][] = [
            'resource_id' => $resource_id,
            'item' => $item,
            'attempts' => 1,
            'code' => sanitize_key($code),
            'final' => false,
        ];
        $state['retry_events']++;
    }

    /** @param array<string, mixed> $state */
    private static function remove_topic_failure(array &$state, int $topic_id): void
    {
        $state['topic_failures'] = array_values(array_filter(
            $state['topic_failures'],
            static fn(array $failure): bool => (int) ($failure['topic_id'] ?? 0) !== $topic_id
        ));
    }

    /** @param mixed $failures */
    private static function next_retry_index(mixed $failures): ?int
    {
        foreach (is_array($failures) ? $failures : [] as $index => $failure) {
            if (is_array($failure) && !($failure['final'] ?? false)) {
                return $index;
            }
        }
        return null;
    }

    /** @param array<string, mixed> $state */
    private static function advance_failure(array &$state, string $queue, int $index, string $code): void
    {
        $attempts = ((int) ($state[$queue][$index]['attempts'] ?? 0)) + 1;
        $state[$queue][$index]['attempts'] = $attempts;
        $state[$queue][$index]['code'] = sanitize_key($code);
        $state['retry_events']++;
        if ($attempts >= self::MAX_AUTOMATIC_ATTEMPTS) {
            $state[$queue][$index]['final'] = true;
            self::record_failure($state, $code);
        }
    }

    /** @param array<string, mixed> $state */
    private static function complete_run(array $state): void
    {
        $state['status'] = $state['failed'] > 0 ? 'complete_with_attention' : 'complete';
        $state['completed_at'] = gmdate('c');
        $state['initial_backfill_complete'] = true;
        self::store($state);
        self::ensure_poll_scheduled();
    }

    /** @param array<string, mixed> $state */
    private static function fail_run(array $state, string $code): void
    {
        self::record_failure($state, $code);
        $state['status'] = 'attention';
        self::store($state);
        self::ensure_poll_scheduled();
    }

    private static function store_attention(string $code): void
    {
        $state = self::initial_state();
        self::record_failure($state, $code);
        $state['status'] = 'attention';
        self::store($state);
    }

    /** @param array<string, mixed> $state */
    private static function record_failure(array &$state, string $code): void
    {
        $state['failed']++;
        $code = sanitize_key($code);
        if ($code !== '' && !in_array($code, $state['failure_codes'], true) && count($state['failure_codes']) < 10) {
            $state['failure_codes'][] = $code;
        }
    }

    /** @param array<string, mixed> $state */
    private static function store(array $state): void
    {
        update_option(self::STATE_OPTION, $state, false);
    }

    private static function nullable_cursor(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function bounded_cursor(mixed $value): ?string
    {
        return is_string($value) && $value !== '' && strlen($value) <= 8192 ? $value : null;
    }

    private static function bounded_string(mixed $value, int $max): string
    {
        return is_string($value) && $value !== '' && strlen($value) <= $max ? $value : '';
    }

    private static function scope_identity(): string
    {
        return hash(
            'sha256',
            Settings::server_url() . "\n" . Settings::connection_id() .
                "\nwordpress-discussion-bridge\n" . DISCUSSIONBRIDGE_WORDPRESS_VERSION
        );
    }

    private static function same_value(mixed $left, mixed $right): bool
    {
        return self::stable_json($left) === self::stable_json($right);
    }

    /** @param array<string, mixed> $response @param array<string, mixed> $destination */
    private static function resolve_response_matches(
        array $response,
        string $resource_id,
        string $publication_revision,
        string $mapping_revision,
        array $destination,
        string $external_id,
        string $canonical_url
    ): bool {
        return wp_is_uuid($resource_id)
            && in_array($response['outcome'] ?? null, ['created', 'resolved'], true)
            && ($response['external_id'] ?? null) === $external_id
            && ($response['canonical_url'] ?? null) === $canonical_url
            && ($response['pending_publication_revision'] ?? null) === $publication_revision
            && ($response['pending_mapping_revision'] ?? null) === $mapping_revision
            && self::same_value($response['pending_destination'] ?? null, $destination)
            && in_array($response['publication_program'] ?? null, ['forum_sync_pending', 'forum_sync'], true);
    }

    /** @param array<string, mixed> $response */
    private static function acknowledgement_response_matches(
        array $response,
        string $resource_id,
        string $destination_state,
        string $publication_revision,
        ?string $source_revision = null,
        ?string $mapping_revision = null
    ): bool {
        if (!in_array($response['outcome'] ?? null, ['acknowledged', 'resolved'], true)
            || ($response['resource_id'] ?? null) !== $resource_id
            || ($response['destination_state'] ?? null) !== $destination_state
            || ($response['acknowledged_publication_revision'] ?? null) !== $publication_revision) {
            return false;
        }
        if ($source_revision !== null && ($response['acknowledged_source_revision'] ?? null) !== $source_revision) {
            return false;
        }
        return $mapping_revision === null
            || ($response['acknowledged_mapping_revision'] ?? null) === $mapping_revision;
    }

    private static function stable_json(mixed $value): string
    {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value);
            }
            foreach ($value as $key => $item) {
                $value[$key] = is_array($item) ? json_decode(self::stable_json($item), true) : $item;
            }
        }
        $encoded = wp_json_encode($value, JSON_UNESCAPED_SLASHES);
        return is_string($encoded) ? $encoded : '';
    }
}
