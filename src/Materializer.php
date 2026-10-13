<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Materializer
{
    public const IMPORTED_META = '_discussionbridge_imported_from_discourse';
    private const META_PREFIX = '_discussionbridge_';
    private const MAX_PAGES = 10000;

    /** @return array{created:int,updated:int,unchanged:int,withdrawn:int,failed:int} */
    public static function sync(): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'withdrawn' => 0, 'failed' => 0];
        $client = new Client();
        $expected_pages = null;
        $expected_total = null;
        $snapshot = null;
        $seen_resources = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $client->records($page, $snapshot, true);
            if (is_wp_error($payload) || !isset($payload['bridge_records'], $payload['pagination'])
                || !is_array($payload['bridge_records']) || !is_array($payload['pagination'])) {
                $totals['failed']++;
                break;
            }
            $reported_page = $payload['pagination']['page'] ?? null;
            $pages = $payload['pagination']['pages'] ?? null;
            $total = $payload['pagination']['total'] ?? null;
            $reported_snapshot = $payload['pagination']['snapshot'] ?? null;
            if (!is_int($reported_page) || $reported_page !== $page || !is_int($pages)
                || $pages < 1 || $pages > self::MAX_PAGES
                || !is_int($total) || $total < 0 || !is_string($reported_snapshot)
                || $reported_snapshot === '' || strlen($reported_snapshot) > 8192
                || ($expected_pages !== null && $pages !== $expected_pages)
                || ($expected_total !== null && $total !== $expected_total)
                || ($snapshot !== null && $reported_snapshot !== $snapshot)) {
                $totals['failed']++;
                break;
            }
            $expected_pages ??= $pages;
            $expected_total ??= $total;
            $snapshot ??= $reported_snapshot;
            foreach ($payload['bridge_records'] as $record) {
                $resource_id = is_array($record) ? strtolower((string) ($record['resource_id'] ?? '')) : '';
                if (!wp_is_uuid($resource_id) || isset($seen_resources[$resource_id])) {
                    $totals['failed']++;
                    break 2;
                }
                $seen_resources[$resource_id] = true;
                if (!is_array($record) || ($record['direction'] ?? null) !== 'from_discourse') {
                    continue;
                }
                if (($record['source']['status'] ?? null) === 'withdrawn') {
                    $result = self::withdraw($record);
                } else {
                    if (!self::native_materialization_authorized($record)) {
                        continue;
                    }
                    $hydrated = $client->publication_content($record);
                    $result = is_wp_error($hydrated) ? $hydrated : (($hydrated['source']['status'] ?? null) === 'withdrawn'
                        ? self::withdraw($hydrated) : self::materialize($hydrated));
                }
                $totals[is_wp_error($result) ? 'failed' : $result]++;
            }
            if ($page >= $pages) {
                break;
            }
        }
        if ($expected_total !== null && count($seen_resources) !== $expected_total) {
            $totals['failed']++;
        }
        return $totals;
    }

    /** @return 'created'|'updated'|'unchanged'|WP_Error */
    public static function materialize(array $record): string|WP_Error
    {
        $validated = self::validate($record);
        if (is_wp_error($validated)) {
            return $validated;
        }
        [$resource_id, $revision, $canonical_url, $title, $content, $source] = $validated;
        $service_author_id = Settings::service_author_id();
        if ($service_author_id <= 0) {
            return new WP_Error('discussionbridge_materialization_service_author', 'A valid WordPress service author is required before materialization.');
        }
        $materialized_content = $content . self::source_provenance($source, (string) $record['topic_url']);
        $existing = self::resource_claims($resource_id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if (count($existing) > 1) {
            return new WP_Error('discussionbridge_materialization_collision', 'Multiple WordPress posts claim this Bridge Record.');
        }
        $post_id = $existing ? (int) $existing[0] : 0;
        $previous_post = null;
        $restoring = false;
        $adopting = false;
        if ($post_id > 0) {
            if (get_post_meta($post_id, self::IMPORTED_META, true) !== '1'
                || (int) get_post_meta($post_id, self::META_PREFIX . 'topic_id', true) !== $record['topic_id']) {
                return new WP_Error('discussionbridge_materialization_ownership', 'The existing native post does not have this publication ownership.');
            }
            if ((string) get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true) !== $canonical_url) {
                return new WP_Error('discussionbridge_materialization_identity_drift', 'The Bridge Record canonical URL changed.');
            }
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post || (int) $post->ID !== $post_id) {
                return new WP_Error('discussionbridge_materialization_ownership', 'The claimed native publication no longer exists with its bound identity. Reconcile it before synchronization.');
            }
            if (!in_array($post->post_type, Settings::post_types(), true)) {
                return new WP_Error('discussionbridge_materialization_post_type', 'The existing publication is outside the selected native post types. Reconcile it before synchronization.');
            }
            $restoring = get_post_meta($post_id, self::META_PREFIX . 'source_withdrawn', true) === '1'
                && get_post_meta($post_id, self::META_PREFIX . 'withdrawal_previous_status', true) === 'publish'
                && $post instanceof \WP_Post && $post->post_status === 'draft';
            if (!$post instanceof \WP_Post || ($post->post_status !== 'publish' && !$restoring)) {
                return new WP_Error('discussionbridge_materialization_unpublished', 'The owned native publication has been withdrawn. Restore it explicitly before synchronization.');
            }
            if (!$restoring && untrailingslashit((string) get_permalink($post_id)) !== untrailingslashit($canonical_url)) {
                return new WP_Error('discussionbridge_materialization_permalink', 'The owned native publication no longer has its authorized canonical URL.');
            }
            $previous_post = clone $post;
            $managed = (string) get_post_meta($post_id, self::META_PREFIX . 'managed_native_sha256', true);
            if ($managed !== '' && !hash_equals($managed, self::native_fingerprint($post))) {
                return new WP_Error('discussionbridge_materialization_native_drift', 'The native post has changed locally. Reconcile it before source synchronization.');
            }
            if ($managed === '') {
                if ((string) $post->post_title !== $title || (int) $post->post_author !== $service_author_id
                    || (string) $post->post_content !== $materialized_content) {
                    return new WP_Error('discussionbridge_materialization_legacy_reconciliation',
                        'The legacy native publication does not exactly match this source. Reconcile it before synchronization.');
                }
                $adopting = true;
            }
            $stored_edition = (string) get_post_meta($post_id, self::META_PREFIX . 'source_edition', true);
            $edition_matches = !isset($source['edition']) || $stored_edition === $source['edition'];
            if ((string) get_post_meta($post_id, self::META_PREFIX . 'source_revision', true) === $revision
                && $edition_matches && !$restoring
                && (string) get_post_meta($post_id, self::META_PREFIX . 'source_updated_at', true) === $source['updated_at']
                && (!isset($source['created_at']) || (string) get_post_meta($post_id, self::META_PREFIX . 'source_created_at', true) === $source['created_at'])
                && (string) $post->post_title === $title
                && (int) $post->post_author === $service_author_id
                && (string) $post->post_content === $materialized_content) {
                if ($adopting && !self::persist_metadata($post_id, self::META_PREFIX . 'managed_native_sha256', self::native_fingerprint($post))) {
                    return new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not persist the verified legacy ownership proof.');
                }
                if (!self::persist_metadata($post_id, self::META_PREFIX . 'source_updated_at_timestamp',
                    self::source_time_number($source['updated_at']))) {
                    return new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not retain the precise source modification time.');
                }
                return 'unchanged';
            }
        } elseif (url_to_postid($canonical_url) > 0) {
            return new WP_Error('discussionbridge_materialization_url_collision', 'The requested WordPress URL already belongs to another post.');
        }

        $path = trim((string) wp_parse_url($canonical_url, PHP_URL_PATH), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $slug = sanitize_title(rawurldecode((string) end($segments)));
        if ($slug === '') {
            return new WP_Error('discussionbridge_materialization_path', 'The authorized WordPress URL must end with a valid post slug.');
        }
        $creating = $post_id === 0;
        $postarr = [
            'ID' => $post_id,
            'post_type' => $previous_post?->post_type ?? Settings::post_types()[0],
            'post_status' => $creating ? 'draft' : 'publish',
            'post_name' => $slug,
            'post_title' => $title,
            'post_content' => $materialized_content,
            'post_author' => $service_author_id,
        ];
        $meta = [
            'imported_from_discourse' => '1',
            'resource_id' => $resource_id,
            'topic_id' => (string) $record['topic_id'],
            'topic_url' => $record['topic_url'],
            'canonical_url' => $canonical_url,
            'source_revision' => $revision,
            'source_edition' => $source['edition'] ?? '',
            'source_created_at' => $source['created_at'] ?? '',
            'source_updated_at' => $source['updated_at'],
            'source_updated_at_timestamp' => self::source_time_number($source['updated_at']),
            'source_forum_name' => $source['forum_name'] ?? '',
            'source_author_username' => $source['author']['username'],
            'source_author_name' => $source['author']['name'],
            'source_author_profile_url' => $source['author']['profile_url'],
            'last_outcome' => $creating ? 'materialized' : 'updated',
            'last_reason' => '',
            'last_attempt_at' => gmdate('c'),
            'synchronized_at' => gmdate('c'),
            'source_withdrawn' => '',
            'withdrawal_previous_status' => '',
            'managed_native_sha256' => hash('sha256', (string) wp_json_encode([$postarr['post_name'], $postarr['post_title'],
                $postarr['post_content'], $postarr['post_author']], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
        ];
        $previous_meta = [];
        if (!$creating) {
            foreach (array_merge(array_keys($meta), ['status']) as $key) {
                $name = self::META_PREFIX . $key;
                $previous_meta[$name] = [metadata_exists('post', $post_id, $name), get_post_meta($post_id, $name, true)];
            }
        }
        $saved = $adopting ? $post_id : wp_insert_post(wp_slash($postarr), true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        if ((!$creating && (int) $saved !== $post_id) || (int) $saved <= 0) {
            // An unexpected returned ID never authorizes touching that other object.
            return $creating ? new WP_Error('discussionbridge_materialization_identity', 'WordPress did not confirm the new native identity.')
                : self::recover_failed_write($post_id, $previous_post, $previous_meta,
                    new WP_Error('discussionbridge_materialization_identity', 'WordPress returned a different native publication identity.'));
        }
        $post_id = (int) $saved;
        $saved_post = get_post($post_id);
        if (!$saved_post instanceof \WP_Post || (int) $saved_post->ID !== $post_id
            || $saved_post->post_type !== $postarr['post_type'] || $saved_post->post_status !== $postarr['post_status']
            || $saved_post->post_name !== $postarr['post_name'] || $saved_post->post_title !== $postarr['post_title']
            || $saved_post->post_content !== $postarr['post_content'] || (int) $saved_post->post_author !== $postarr['post_author']) {
            return self::recover_failed_write($post_id, $previous_post, $previous_meta,
                new WP_Error('discussionbridge_materialization_native_result', 'WordPress changed the intended native publication while saving.'));
        }
        foreach ($meta as $key => $value) {
            if (!self::persist_metadata($post_id, self::META_PREFIX . $key, $value)) {
                return self::recover_failed_write($post_id, $previous_post, $previous_meta,
                    new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not persist the native publication mapping.'));
            }
        }
        $comments_mode = Settings::comments_mode();
        if ($creating) {
            foreach ($record['bindings'] as $binding) {
                if (($binding['role'] ?? null) === 'presentation' && ($binding['state'] ?? null) === 'active'
                    && in_array($binding['presentation_mode'] ?? null, ['simple', 'full', 'interactive'], true)) {
                    $comments_mode = $binding['presentation_mode'];
                }
            }
        }
        if ($creating && !self::persist_metadata($post_id, Presentation::COMMENTS_MODE_META, $comments_mode)) {
            return self::recover_failed_write($post_id, null, [],
                new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not persist the selected discussion mode.'));
        }
        if ($creating && !self::persist_metadata($post_id, Presentation::RICH_CONTENT_META, 'site')) {
            return self::recover_failed_write($post_id, null, [],
                new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not persist the rich-content choice.'));
        }
        if ($creating) {
            $published = wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            if (is_wp_error($published) || (int) $published !== $post_id) {
                return self::recover_failed_write($post_id, null, [], is_wp_error($published) ? $published : new WP_Error(
                    'discussionbridge_materialization_publish',
                    'WordPress did not confirm the materialized post publication.',
                ));
            }
        }
        $persisted = get_post($post_id);
        if (!$persisted instanceof \WP_Post || (int) $persisted->ID !== $post_id || $persisted->post_status !== 'publish'
            || $persisted->post_type !== $postarr['post_type']
            || !hash_equals($meta['managed_native_sha256'], self::native_fingerprint($persisted))) {
            return self::recover_failed_write($post_id, $previous_post, $previous_meta, new WP_Error(
                'discussionbridge_materialization_publish',
                'WordPress did not preserve the intended native publication after publishing.',
            ));
        }
        if (untrailingslashit((string) get_permalink($post_id)) !== untrailingslashit($canonical_url)) {
            return self::recover_failed_write($post_id, $previous_post, $previous_meta,
                new WP_Error('discussionbridge_materialization_permalink', 'WordPress did not produce the authorized canonical URL.'));
        }
        if (!self::persist_metadata($post_id, self::META_PREFIX . 'status', 'healthy')) {
            return self::recover_failed_write($post_id, $previous_post, $previous_meta,
                new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not confirm the native publication state.'));
        }
        clean_post_cache($post_id);
        return $creating ? 'created' : 'updated';
    }

    private static function native_fingerprint(\WP_Post $post): string
    {
        return hash('sha256', (string) wp_json_encode([$post->post_name, $post->post_title, $post->post_content, $post->post_author],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @return list<int>|WP_Error Ownership is independent of registered types/statuses. */
    private static function resource_claims(string $resource_id): array|WP_Error
    {
        global $wpdb;
        try {
            $query = $wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT 2",
                self::META_PREFIX . 'resource_id', $resource_id,
            );
            if (!is_string($query) || $query === '') {
                throw new \RuntimeException('ownership_query_unavailable');
            }
            $claims = $wpdb->get_col($query);
            if ($wpdb->last_error !== '' || !is_array($claims) || count($claims) > 2) {
                throw new \RuntimeException('ownership_query_unverified');
            }
            $ids = [];
            foreach ($claims as $claim) {
                if ((!is_string($claim) && !is_int($claim)) || preg_match('/\A[1-9][0-9]*\z/D', (string) $claim) !== 1
                    || (string) (int) $claim !== (string) $claim) {
                    throw new \RuntimeException('ownership_identity_unverified');
                }
                $ids[] = (int) $claim;
            }
            if (count(array_unique($ids)) !== count($ids)) {
                throw new \RuntimeException('ownership_duplicate_result');
            }
            return $ids;
        } catch (\Throwable) {
            return new WP_Error('discussionbridge_materialization_census', 'WordPress could not verify the native publication ownership census. Retry after resolving the database condition.');
        }
    }

    /** Native queries opt in explicitly; ordinary WordPress archives keep their selection. */
    public static function source_time_query_clauses(array $clauses, \WP_Query $query): array
    {
        if (is_admin() || $query->get('discussionbridge_source_modified') !== true) {
            return $clauses;
        }
        global $wpdb;
        $clauses['join'] = ($clauses['join'] ?? '') . " LEFT JOIN (SELECT post_id, MAX(CAST(meta_value AS DECIMAL(23,9))) AS source_modified FROM {$wpdb->postmeta} WHERE meta_key = '_discussionbridge_source_updated_at_timestamp' GROUP BY post_id) AS discussionbridge_source_time ON discussionbridge_source_time.post_id = {$wpdb->posts}.ID";
        $clauses['orderby'] = "COALESCE(discussionbridge_source_time.source_modified, TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', {$wpdb->posts}.post_modified_gmt)) DESC, {$wpdb->posts}.ID DESC";
        return $clauses;
    }

    /** @return 'withdrawn'|'unchanged'|WP_Error */
    public static function withdraw(array $record): string|WP_Error
    {
        $id = $record['resource_id'] ?? null;
        $edition = $record['source']['edition'] ?? null;
        if (($record['direction'] ?? null) !== 'from_discourse' || !is_string($id) || !wp_is_uuid($id)
            || ($record['source']['status'] ?? null) !== 'withdrawn' || !is_string($edition)
            || preg_match('/\A[a-f0-9]{64}\z/D', $edition) !== 1 || !is_int($record['topic_id'] ?? null)
            || $record['topic_id'] <= 0 || !is_array($record['bindings'] ?? null)
            || !is_string($record['topic_url'] ?? null) || !Settings::topic_url_matches($record['topic_url'], $record['topic_id'])) {
            return new WP_Error('discussionbridge_invalid_withdrawal', 'The source withdrawal is invalid.');
        }
        $existing = self::resource_claims($id);
        if (is_wp_error($existing)) {
            return $existing;
        }
        if ($existing === []) {
            return 'unchanged';
        }
        if (count($existing) !== 1) {
            return new WP_Error('discussionbridge_materialization_collision', 'Multiple WordPress posts claim the withdrawn source.');
        }
        $post_id = (int) $existing[0];
        $post = get_post($post_id);
        if (!$post instanceof \WP_Post || (int) $post->ID !== $post_id || get_post_meta($post_id, self::IMPORTED_META, true) !== '1'
            || (string) get_post_meta($post_id, self::META_PREFIX . 'topic_id', true) !== (string) $record['topic_id']) {
            return new WP_Error('discussionbridge_withdrawal_ownership', 'The withdrawn source does not own this native post.');
        }
        if (!in_array($post->post_type, Settings::post_types(), true)) {
            return new WP_Error('discussionbridge_materialization_post_type', 'The existing publication is outside the selected native post types. Reconcile it before withdrawal.');
        }
        $canonical = (string) get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true);
        $matches = false;
        $active_presentations = 0;
        foreach ($record['bindings'] as $binding) {
            if (($binding['role'] ?? null) === 'presentation' && ($binding['state'] ?? null) === 'active') {
                $active_presentations++;
                if (($binding['canonical_url'] ?? null) === $canonical && self::same_site_url($canonical)
                    && is_bool($binding['native_materialization'] ?? null)) {
                    $matches = true;
                }
            }
        }
        if (!$matches || $active_presentations !== 1) {
            return new WP_Error('discussionbridge_withdrawal_ownership', 'The withdrawn presentation identity is inconsistent.');
        }
        if (get_post_meta($post_id, self::META_PREFIX . 'source_withdrawn', true) === '1'
            && get_post_meta($post_id, self::META_PREFIX . 'source_edition', true) === $edition
            && $post->post_status !== 'publish'
            && get_post_meta($post_id, self::META_PREFIX . 'status', true) === 'paused') {
            return 'unchanged';
        }
        $retained_status = (string) get_post_meta($post_id, self::META_PREFIX . 'withdrawal_previous_status', true);
        $previous_status = in_array($retained_status, ['publish', 'draft', 'private', 'pending', 'future', 'trash'], true)
            ? $retained_status : $post->post_status;
        $retained = self::persist_metadata($post_id, self::META_PREFIX . 'withdrawal_previous_status', $previous_status);
        $retained = self::persist_metadata($post_id, self::META_PREFIX . 'source_withdrawn', '1') && $retained;
        if ($post->post_status === 'publish') {
            $saved = wp_update_post(['ID' => $post_id, 'post_status' => 'draft']);
            if (is_wp_error($saved) || (int) $saved !== $post_id || get_post($post_id)?->post_status === 'publish') {
                return new WP_Error('discussionbridge_withdrawal_failed', 'WordPress did not withdraw the owned publication.');
            }
        }
        if (!$retained) {
            self::persist_metadata($post_id, self::META_PREFIX . 'status', 'attention');
            self::persist_metadata($post_id, self::META_PREFIX . 'last_reason', 'withdrawal_recovery_metadata');
            return new WP_Error('discussionbridge_materialization_metadata', 'The source was withdrawn but its recovery metadata needs attention.');
        }
        foreach (['source_edition' => $edition, 'status' => 'paused', 'last_outcome' => 'withdrawn',
            'last_reason' => 'source_unavailable', 'last_attempt_at' => gmdate('c'), 'synchronized_at' => gmdate('c')] as $key => $value) {
            if (!self::persist_metadata($post_id, self::META_PREFIX . $key, $value)) {
                return new WP_Error('discussionbridge_materialization_metadata', 'WordPress did not confirm the withdrawn publication state.');
            }
        }
        clean_post_cache($post_id);
        return 'withdrawn';
    }

    private static function persist_metadata(int $post_id, string $key, mixed $value): bool
    {
        update_post_meta($post_id, $key, wp_slash($value));
        return metadata_exists('post', $post_id, $key) && (string) get_post_meta($post_id, $key, true) === (string) $value;
    }

    /** @param array<string,array{bool,mixed}> $previous_meta */
    private static function recover_failed_write(int $post_id, ?\WP_Post $previous_post, array $previous_meta, WP_Error $error): WP_Error
    {
        if ($previous_post === null) {
            wp_delete_post($post_id, true);
            return get_post($post_id) === null ? $error : new WP_Error(
                'discussionbridge_materialization_recovery',
                'Native publication failed and WordPress could not remove the incomplete new post.',
            );
        }
        $restored = wp_insert_post(wp_slash([
            'ID' => $post_id,
            'post_type' => $previous_post->post_type,
            'post_status' => $previous_post->post_status,
            'post_name' => $previous_post->post_name,
            'post_title' => $previous_post->post_title,
            'post_content' => $previous_post->post_content,
            'post_author' => $previous_post->post_author,
        ]), true);
        $recovered = !is_wp_error($restored) && (int) $restored === $post_id;
        foreach ($previous_meta as $key => [$existed, $value]) {
            if ($existed) {
                $recovered = self::persist_metadata($post_id, $key, $value) && $recovered;
            } else {
                delete_post_meta($post_id, $key);
                $recovered = !metadata_exists('post', $post_id, $key) && $recovered;
            }
        }
        clean_post_cache($post_id);
        $actual = get_post($post_id);
        $recovered = $actual instanceof \WP_Post && $actual->post_status === $previous_post->post_status
            && $actual->post_type === $previous_post->post_type && $actual->post_name === $previous_post->post_name
            && $actual->post_title === $previous_post->post_title && $actual->post_content === $previous_post->post_content
            && (int) $actual->post_author === (int) $previous_post->post_author && $recovered;
        return $recovered ? $error : new WP_Error(
            'discussionbridge_materialization_recovery',
            'Native publication failed and WordPress could not fully restore the preceding publication. Its identity has been retained for recovery.',
        );
    }

    /** @return array{string,string,string,string,string,array<string,mixed>}|WP_Error */
    private static function validate(array $record): array|WP_Error
    {
        $resource_id = $record['resource_id'] ?? null;
        $title = $record['title'] ?? null;
        $content = $record['content_html'] ?? null;
        $source = $record['source'] ?? null;
        $bindings = $record['bindings'] ?? null;
        if (($record['direction'] ?? null) !== 'from_discourse' || ($record['state'] ?? null) !== 'healthy' || !is_string($resource_id) || !wp_is_uuid($resource_id)
            || !is_string($title) || trim($title) === '' || strlen($title) > 1024
            || !is_string($content) || trim($content) === ''
            || !is_int($record['topic_id'] ?? null) || $record['topic_id'] <= 0
            || !is_string($record['topic_url'] ?? null) || !Settings::topic_url_matches($record['topic_url'], $record['topic_id'])
            || !is_array($source) || !is_array($bindings)) {
            return new WP_Error('discussionbridge_materialization_record', 'The From Discourse record is invalid.');
        }
        if (($source['platform'] ?? null) !== 'discourse' || ($source['origin'] ?? null) !== Settings::server_url()
            || ($source['topic_id'] ?? null) !== $record['topic_id'] || ($source['topic_url'] ?? null) !== $record['topic_url']
            || !is_int($source['post_id'] ?? null) || $source['post_id'] <= 0
            || !is_int($source['post_version'] ?? null) || $source['post_version'] <= 0
            || !is_string($source['revision'] ?? null)
            || $source['revision'] !== 'post:' . $source['post_id'] . ':version:' . $source['post_version']
            || self::source_time_parts($source['updated_at'] ?? null) === null
            || !is_array($source['author'] ?? null)
            || !is_string($source['author']['username'] ?? null) || trim($source['author']['username']) === ''
            || !is_string($source['author']['name'] ?? null) || trim($source['author']['name']) === ''
            || !is_string($source['author']['profile_url'] ?? null)) {
            return new WP_Error('discussionbridge_materialization_source', 'The Discourse source provenance is invalid.');
        }
        if ((isset($source['edition']) && (!is_string($source['edition']) || preg_match('/\A[a-f0-9]{64}\z/D', $source['edition']) !== 1))
            || (isset($source['created_at']) && self::source_time_parts($source['created_at']) === null)
            || (isset($source['forum_name']) && (!is_string($source['forum_name']) || trim($source['forum_name']) === ''
                || strlen($source['forum_name']) > 200 || preg_match('/[\x00-\x1F\x7F]/', $source['forum_name'])))) {
            return new WP_Error('discussionbridge_materialization_source', 'The source edition, creation time or forum name is invalid.');
        }
        if (isset($source['created_at']) && array_slice(self::source_time_parts($source['updated_at']), 0, 2) < array_slice(self::source_time_parts($source['created_at']), 0, 2)) {
            return new WP_Error('discussionbridge_materialization_source', 'The source update time precedes its creation time.');
        }
        $canonical_url = null;
        foreach ($bindings as $binding) {
            if (is_array($binding) && ($binding['role'] ?? null) === 'presentation' && ($binding['state'] ?? null) === 'active') {
                if ($canonical_url !== null || !is_string($binding['canonical_url'] ?? null)) {
                    return new WP_Error('discussionbridge_materialization_binding', 'The presentation binding is ambiguous.');
                }
                if (($binding['native_materialization'] ?? null) !== true) {
                    return new WP_Error('discussionbridge_materialization_not_authorized', 'Native materialization is not authorized for this binding.');
                }
                if (isset($binding['presentation_mode']) && !in_array($binding['presentation_mode'], ['simple', 'full', 'interactive'], true)) {
                    return new WP_Error('discussionbridge_materialization_binding', 'The selected discussion presentation is invalid.');
                }
                $canonical_url = $binding['canonical_url'];
            }
        }
        if (!is_string($canonical_url) || !self::same_site_url($canonical_url)) {
            return new WP_Error('discussionbridge_materialization_binding', 'The presentation URL is outside this WordPress site.');
        }
        $clean = trim(str_replace('[discotoc]', '', wp_kses_post($content)));
        if ($clean === '') {
            return new WP_Error('discussionbridge_materialization_content', 'The source content is empty after sanitization.');
        }
        return [$resource_id, $source['revision'], $canonical_url, trim($title), $clean, $source];
    }

    /** @return array{int,string,string}|null */
    private static function source_time_parts(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/\A(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.(\d{1,9}))?(Z|[+-]\d{2}:\d{2})\z/D', $value, $parts) !== 1
            || !checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23 || (int) $parts[5] > 59 || (int) $parts[6] > 59
            || ($parts[8] !== 'Z' && ((int) substr($parts[8], 1, 2) > 23 || (int) substr($parts[8], 4, 2) > 59))) {
            return null;
        }
        try {
            // Parse whole seconds only: PHP's parser loses or refuses declared
            // nanoseconds. Keep the original fraction independently for exact
            // source chronology and selected native Latest ordering.
            $date = new \DateTimeImmutable($parts[1].'-'.$parts[2].'-'.$parts[3].'T'.$parts[4].':'.$parts[5].':'.$parts[6].$parts[8]);
        } catch (\Throwable) {
            return null;
        }
        $fraction = $parts[7] ?? '';
        return [(int) $date->format('U'), str_pad($fraction, 9, '0'), $fraction];
    }

    private static function source_time_number(string $value): string
    {
        [$seconds, $fraction, $original] = self::source_time_parts($value);
        $precision = max(6, strlen($original));
        if ($seconds < 0 && (int) $fraction !== 0) {
            // DateTime's Unix seconds are floored. A positive fractional second
            // before the epoch reduces the absolute value of that negative second.
            return '-'.(abs($seconds) - 1).'.'.substr(str_pad((string) (1000000000 - (int) $fraction), 9, '0', STR_PAD_LEFT), 0, $precision);
        }
        return $seconds.'.'.substr($fraction, 0, $precision);
    }

    private static function same_site_url(string $value): bool
    {
        $url = wp_parse_url($value);
        $home = wp_parse_url(home_url('/'));
        return is_array($url) && is_array($home) && ($url['scheme'] ?? '') === 'https'
            && Settings::valid_url_path((string) ($url['path'] ?? ''))
            && strtolower((string) ($url['host'] ?? '')) === strtolower((string) ($home['host'] ?? ''))
            && (int) ($url['port'] ?? 443) === (int) ($home['port'] ?? 443)
            && empty($url['user']) && empty($url['pass']) && empty($url['query']) && empty($url['fragment']);
    }

    private static function native_materialization_authorized(array $record): bool
    {
        $bindings = $record['bindings'] ?? null;
        if (!is_array($bindings)) {
            return false;
        }
        foreach ($bindings as $binding) {
            if (is_array($binding) && ($binding['role'] ?? null) === 'presentation'
                && ($binding['state'] ?? null) === 'active'
                && ($binding['native_materialization'] ?? null) === true) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string,mixed> $source */
    private static function source_provenance(array $source, string $topic_url): string
    {
        $author = $source['author'];
        $profile = esc_url((string) $author['profile_url']);
        $name = esc_html((string) $author['name']);
        $author_markup = $profile !== '' ? '<a href="' . $profile . '">' . $name . '</a>' : $name;
        $forum_name = isset($source['forum_name']) ? trim((string) $source['forum_name']) : (string) wp_parse_url(Settings::server_url(), PHP_URL_HOST);
        return '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'
            . esc_url($topic_url) . '">' . esc_html($forum_name) . '</a></strong></p><p>Source author: '
            . $author_markup . ' · Revision ' . esc_html((string) $source['revision'])
            . ' · DiscussionBridge for WordPress ' . esc_html(DISCUSSIONBRIDGE_WORDPRESS_VERSION)
            . '</p></aside>';
    }
}
