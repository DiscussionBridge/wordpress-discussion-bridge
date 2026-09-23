<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Materializer
{
    public const IMPORTED_META = '_discussionbridge_imported_from_discourse';
    private const META_PREFIX = '_discussionbridge_';
    private const MAX_PAGES = 10000;

    /**
     * @param array<int,string>|null $failure_codes Receives bounded, non-secret error codes for the operator.
     * @return array{created:int,updated:int,unchanged:int,failed:int}
     */
    public static function sync(?array &$failure_codes = null): array
    {
        $failure_codes = [];
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $client = new Client();
        $expected_pages = null;
        $expected_total = null;
        $snapshot = null;
        $seen_resources = [];
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $client->records($page, $snapshot);
            if (is_wp_error($payload) || !isset($payload['bridge_records'], $payload['pagination'])
                || !is_array($payload['bridge_records']) || !is_array($payload['pagination'])) {
                $totals['failed']++;
                self::record_failure($failure_codes, is_wp_error($payload) ? $payload->get_error_code() : 'invalid_record_feed');
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
                self::record_failure($failure_codes, 'invalid_record_pagination');
                break;
            }
            $expected_pages ??= $pages;
            $expected_total ??= $total;
            $snapshot ??= $reported_snapshot;
            foreach ($payload['bridge_records'] as $record) {
                $resource_id = is_array($record) ? strtolower((string) ($record['resource_id'] ?? '')) : '';
                if (!wp_is_uuid($resource_id) || isset($seen_resources[$resource_id])) {
                    $totals['failed']++;
                    self::record_failure($failure_codes, 'invalid_or_duplicate_resource_id');
                    break 2;
                }
                $seen_resources[$resource_id] = true;
                if (!is_array($record) || ($record['direction'] ?? null) !== 'from_discourse'
                    || ($record['publication_program'] ?? 'legacy') !== 'legacy'
                    || !self::native_materialization_authorized($record)) {
                    continue;
                }
                $result = self::materialize($record);
                $totals[is_wp_error($result) ? 'failed' : $result]++;
                if (is_wp_error($result)) {
                    self::record_failure($failure_codes, $result->get_error_code());
                }
            }
            if ($page >= $pages) {
                break;
            }
        }
        if ($expected_total !== null && count($seen_resources) !== $expected_total) {
            $totals['failed']++;
            self::record_failure($failure_codes, 'record_count_mismatch');
        }
        return $totals;
    }

    /** @param array<int,string> $failure_codes */
    private static function record_failure(array &$failure_codes, string $code): void
    {
        $safe_code = substr(sanitize_key($code), 0, 80);
        if ($safe_code !== '' && !in_array($safe_code, $failure_codes, true) && count($failure_codes) < 5) {
            $failure_codes[] = $safe_code;
        }
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
        $existing = get_posts([
            'post_type' => Settings::post_types(),
            'post_status' => ['publish', 'draft', 'private'],
            'posts_per_page' => 2,
            'meta_key' => self::META_PREFIX . 'resource_id',
            'meta_value' => $resource_id,
            'fields' => 'ids',
        ]);
        if (count($existing) > 1) {
            return new WP_Error('discussionbridge_materialization_collision', 'Multiple WordPress posts claim this Bridge Record.');
        }
        $post_id = $existing ? (int) $existing[0] : 0;
        if ($post_id > 0) {
            $previous_url = (string) get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true);
            if ($previous_url !== $canonical_url
                && (!self::verified_url_migration($record, $previous_url, $canonical_url)
                    || untrailingslashit((string) get_permalink($post_id)) !== untrailingslashit($canonical_url))) {
                return new WP_Error('discussionbridge_materialization_identity_drift', 'The Bridge Record canonical URL changed without a verified migration of this WordPress post.');
            }
            $post = get_post($post_id);
            if (!$post instanceof \WP_Post) {
                return new WP_Error('discussionbridge_materialization_missing_post', 'The mapped WordPress post no longer exists.');
            }
            if ($previous_url === $canonical_url
                && (string) get_post_meta($post_id, self::META_PREFIX . 'source_revision', true) === $revision
                && (int) $post->post_author === $service_author_id
                && (string) $post->post_content === $materialized_content) {
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
            'post_type' => Settings::post_types()[0],
            'post_status' => $creating ? 'draft' : 'publish',
            'post_name' => $slug,
            'post_title' => $title,
            'post_content' => $materialized_content,
            'post_author' => $service_author_id,
        ];
        if (!$creating) {
            // A dated permalink is part of the authorized identity. WordPress may
            // otherwise replace the date with "now" when updating an existing post.
            $postarr['post_date'] = $post->post_date;
            $postarr['post_date_gmt'] = $post->post_date_gmt;
        }
        $saved = wp_insert_post($postarr, true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $post_id = (int) $saved;
        if ($creating) {
            $published = wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
            if (is_wp_error($published) || (int) $published !== $post_id) {
                wp_delete_post($post_id, true);
                return is_wp_error($published) ? $published : new WP_Error(
                    'discussionbridge_materialization_publish',
                    'WordPress did not confirm the materialized post publication.',
                );
            }
        }
        $persisted = get_post($post_id);
        if (!$persisted instanceof \WP_Post || $persisted->post_status !== 'publish') {
            if ($creating) {
                wp_delete_post($post_id, true);
            }
            return new WP_Error(
                'discussionbridge_materialization_publish',
                'The materialized WordPress post is not published.',
            );
        }
        if (untrailingslashit((string) get_permalink($post_id)) !== untrailingslashit($canonical_url)) {
            if ($creating) {
                wp_delete_post($post_id, true);
            }
            return new WP_Error('discussionbridge_materialization_permalink', 'WordPress did not produce the authorized canonical URL.');
        }

        $meta = [
            'imported_from_discourse' => '1',
            'status' => 'healthy',
            'resource_id' => $resource_id,
            'topic_id' => (string) $record['topic_id'],
            'topic_url' => $record['topic_url'],
            'canonical_url' => $canonical_url,
            'source_revision' => $revision,
            'source_updated_at' => $source['updated_at'],
            'source_author_username' => $source['author']['username'],
            'source_author_name' => $source['author']['name'],
            'source_author_profile_url' => $source['author']['profile_url'],
            'last_outcome' => $creating ? 'materialized' : 'updated',
            'last_reason' => '',
            'last_attempt_at' => gmdate('c'),
        ];
        foreach ($meta as $key => $value) {
            update_post_meta($post_id, self::META_PREFIX . $key, $value);
        }
        update_post_meta($post_id, Presentation::COMMENTS_MODE_META, 'interactive');
        clean_post_cache($post_id);
        return $creating ? 'created' : 'updated';
    }

    /** @return array{string,string,string,string,string,array<string,mixed>}|WP_Error */
    private static function validate(array $record): array|WP_Error
    {
        $resource_id = $record['resource_id'] ?? null;
        $title = $record['title'] ?? null;
        $content = $record['content_html'] ?? null;
        $source = $record['source'] ?? null;
        $bindings = $record['bindings'] ?? null;
        if (($record['state'] ?? null) !== 'healthy' || !is_string($resource_id) || !wp_is_uuid($resource_id)
            || !is_string($title) || trim($title) === '' || strlen($title) > 1024
            || !is_string($content) || trim($content) === ''
            || strlen($content) > ForumPublisher::MAX_FORUM_PUBLICATION_HTML_BYTES
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
            || !is_string($source['updated_at'] ?? null) || strtotime($source['updated_at']) === false
            || !is_array($source['author'] ?? null)
            || !is_string($source['author']['username'] ?? null) || trim($source['author']['username']) === ''
            || !is_string($source['author']['name'] ?? null) || trim($source['author']['name']) === ''
            || !is_string($source['author']['profile_url'] ?? null)) {
            return new WP_Error('discussionbridge_materialization_source', 'The Discourse source provenance is invalid.');
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

    private static function same_site_url(string $value): bool
    {
        $url = wp_parse_url($value);
        $home = wp_parse_url(home_url('/'));
        return is_array($url) && is_array($home) && ($url['scheme'] ?? '') === 'https'
            && strtolower((string) ($url['host'] ?? '')) === strtolower((string) ($home['host'] ?? ''))
            && (int) ($url['port'] ?? 443) === (int) ($home['port'] ?? 443)
            && empty($url['user']) && empty($url['pass']) && empty($url['query']) && empty($url['fragment']);
    }

    private static function verified_url_migration(array $record, string $old_url, string $new_url): bool
    {
        foreach ($record['bindings'] ?? [] as $binding) {
            if (!is_array($binding) || ($binding['role'] ?? null) !== 'presentation' || ($binding['state'] ?? null) !== 'active') {
                continue;
            }
            $proof = $binding['url_migration'] ?? null;
            return is_array($proof)
                && ($proof['old_url'] ?? null) === $old_url
                && ($proof['new_url'] ?? null) === $new_url
                && in_array($proof['redirect_status'] ?? null, [301, 308], true)
                && is_string($proof['verified_at'] ?? null)
                && strtotime($proof['verified_at']) !== false
                && self::same_site_url($old_url)
                && self::same_site_url($new_url);
        }
        return false;
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
        return '<hr><aside class="discussionbridge-publication"><p><strong>Published from <a href="'
            . esc_url($topic_url) . '">The Bridge</a></strong></p><p>Source author: '
            . $author_markup . ' · Revision ' . esc_html((string) $source['revision'])
            . ' · DiscussionBridge for WordPress ' . esc_html(DISCUSSIONBRIDGE_WORDPRESS_VERSION)
            . '</p></aside>';
    }
}
