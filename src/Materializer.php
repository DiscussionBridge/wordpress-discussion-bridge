<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use WP_Error;

final class Materializer
{
    public const IMPORTED_META = '_discussionbridge_imported_from_discourse';
    private const META_PREFIX = '_discussionbridge_';
    private const MAX_PAGES = 100;

    /** @return array{created:int,updated:int,unchanged:int,failed:int} */
    public static function sync(): array
    {
        $totals = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'failed' => 0];
        $client = new Client();
        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $payload = $client->records($page);
            if (is_wp_error($payload) || !isset($payload['bridge_records'], $payload['pagination'])
                || !is_array($payload['bridge_records']) || !is_array($payload['pagination'])) {
                $totals['failed']++;
                break;
            }
            foreach ($payload['bridge_records'] as $record) {
                if (!is_array($record) || ($record['direction'] ?? null) !== 'from_discourse'
                    || !self::native_materialization_authorized($record)) {
                    continue;
                }
                $result = self::materialize($record);
                $totals[is_wp_error($result) ? 'failed' : $result]++;
            }
            $pages = isset($payload['pagination']['pages']) && is_int($payload['pagination']['pages'])
                ? $payload['pagination']['pages'] : 0;
            if ($pages < 1 || $pages > self::MAX_PAGES || $page >= $pages) {
                break;
            }
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
            if ((string) get_post_meta($post_id, self::META_PREFIX . 'canonical_url', true) !== $canonical_url) {
                return new WP_Error('discussionbridge_materialization_identity_drift', 'The Bridge Record canonical URL changed.');
            }
            if ((string) get_post_meta($post_id, self::META_PREFIX . 'source_revision', true) === $revision) {
                return 'unchanged';
            }
        } elseif (url_to_postid($canonical_url) > 0) {
            return new WP_Error('discussionbridge_materialization_url_collision', 'The requested WordPress URL already belongs to another post.');
        }

        $path = trim((string) wp_parse_url($canonical_url, PHP_URL_PATH), '/');
        if ($path === '' || str_contains($path, '/')) {
            return new WP_Error('discussionbridge_materialization_path', 'The first WordPress publisher profile requires one post slug.');
        }
        $creating = $post_id === 0;
        $service_author_id = Settings::service_author_id();
        if ($service_author_id <= 0) {
            return new WP_Error('discussionbridge_materialization_service_author', 'A valid WordPress service author is required before materialization.');
        }
        $content .= self::source_provenance($source, (string) $record['topic_url']);
        $postarr = [
            'ID' => $post_id,
            'post_type' => Settings::post_types()[0],
            'post_status' => $creating ? 'draft' : 'publish',
            'post_name' => sanitize_title($path),
            'post_title' => $title,
            'post_content' => $content,
            'post_author' => $service_author_id,
        ];
        $saved = wp_insert_post($postarr, true);
        if (is_wp_error($saved)) {
            return $saved;
        }
        $post_id = (int) $saved;
        if ($creating) {
            wp_update_post(['ID' => $post_id, 'post_status' => 'publish']);
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
        update_post_meta($post_id, Presentation::COMMENTS_MODE_META, 'fullInteractive');
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
            || !is_string($content) || trim($content) === '' || strlen($content) > 65536
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
