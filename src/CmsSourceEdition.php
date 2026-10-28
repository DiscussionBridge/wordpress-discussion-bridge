<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Actual published native source edition; configuration or representation never manufactures an edition. */
final class CmsSourceEdition
{
    public static function capture(int $id, CmsClient $client): array|WP_Error
    {
        try {
            $journal = (new CmsJournal($id))->read(); if (is_wp_error($journal)) return $journal;
            $ancestry = $journal->source_ancestry ?? null;
            $snapshot = NativeSourceSnapshot::capture($id, $ancestry === null ? null : ($journal->current_native_source ?? null));
            if (is_wp_error($snapshot)) return $snapshot;
            if (!in_array($snapshot['post_type'], Settings::post_types(), true)
                || get_post_meta($id, Materializer::IMPORTED_META, true) === '1'
                || get_post_meta($id, Publisher::ENABLED_META, true) !== '1') return self::unavailable('not_published_source');
            if (WpDiscourseGuard::conflicts($id)) return self::unavailable('wp_discourse_conflict');
            $authors = []; $primary = null;
            if ($snapshot['author'] !== null) {
                $primary = 'wordpress-user:' . Settings::site_id() . ':' . $snapshot['author']['native_id'];
                $authors[] = $client->codec()->validate('SourceAuthor', (object) ['id' => $primary, 'name' => $snapshot['author']['name']]);
            }
            $native = (object) ['title' => $snapshot['title'], 'content_sha256' => $snapshot['complete_sha256'], 'content_bytes' => $snapshot['complete_bytes'],
                'source_created_at' => $snapshot['created_at'], 'source_updated_at' => $snapshot['updated_at'], 'source_authors' => $authors];
            if ($primary !== null) $native->primary_source_author_id = $primary;
            $original = (object) ['article' => $snapshot['original_article'], 'article_url' => $snapshot['source_article_url'],
                'native_row_sha256' => $snapshot['row_sha256'], 'native_row' => (object) $snapshot['row'],
                'prepared_article' => $snapshot['complete_html'], 'prepared_bytes' => $snapshot['complete_bytes'], 'prepared_sha256' => $snapshot['complete_sha256'],
                'semantic_source' => $native];
            $revision = self::revision($client, $native, $ancestry);
            // The complete body stays native and internal until the one SDK selects full/generated delivery.
            $body = (object) ['direction' => 'to_discourse', 'external_id' => 'post:' . Settings::site_id() . ':' . $id,
                'canonical_url' => $snapshot['url'], 'title' => $snapshot['title'], 'content_html' => $snapshot['complete_html'], 'published' => true,
                'source_revision' => $revision, 'expected_source_revision' => null,
                'source_created_at' => $snapshot['created_at'], 'source_updated_at' => $snapshot['updated_at'],
                'representation' => (object) ['kind' => 'full'], 'source_content_sha256' => $snapshot['complete_sha256'],
                'source_content_bytes' => $snapshot['complete_bytes'], 'source_authors' => $authors];
            if ($primary !== null) $body->primary_source_author_id = $primary;
            if (Settings::lane() !== '') $body->lane = Settings::lane();
            foreach (['external_id' => 'OpaqueId', 'canonical_url' => 'Url', 'title' => 'Title', 'source_revision' => 'Revision'] as $field => $type)
                $client->codec()->validate($type, $body->$field);
            $proof = (object) ['native_id' => (string) $id, 'external_id' => $body->external_id, 'canonical_url' => $body->canonical_url,
                'native_row_sha256' => $snapshot['row_sha256'], 'source_revision' => $revision,
                'source_content_sha256' => $snapshot['complete_sha256'], 'source_content_bytes' => $snapshot['complete_bytes']];
            return ['body' => $body, 'proof' => $proof, 'native_source' => $original, 'complete_content' => $snapshot['complete_html']];
        } catch (\Throwable) { return self::unavailable('native_capture_unrepresentable'); }
    }

    public static function revision(CmsClient $client, stdClass $native, ?stdClass $ancestry = null): string
    {
        return 'wp-source:' . hash('sha256', $client->codec()->semanticIdentity($ancestry === null ? $native : (object) ['source' => $native, 'url_commit' => $ancestry]));
    }

    public static function delivery(array $capture, CmsClient $client, ?string $predecessor, ?string $existing_topic = null): stdClass|WP_Error
    {
        $body = clone $capture['body']; $body->expected_source_revision = $predecessor;
        if ($existing_topic !== null) $body->existing_topic_id = $existing_topic;
        $codec = $client->codec();
        try {
            $full_size = $codec->measureToFullEnvelope($body, $capture['complete_content']);
            if (strlen($capture['complete_content']) <= 49152 && $full_size <= 65536) return $codec->validateGeneratedEnvelope($body);
            $budget = 49152 - strlen($codec->generatedReadMore($body->canonical_url));
            while ($budget > 0) {
                $article = NativeContent::article_prefix($capture['complete_content'], $budget);
                if (is_wp_error($article)) return $article;
                try { return $codec->createGeneratedExcerpt($body, $capture['complete_content'], $article); }
                catch (\DiscussionBridge\CmsV4\ValidationException $error) {
                    if ($error->validationCode !== 'body_too_large') return self::unavailable('generated_excerpt_unrepresentable');
                    $budget = intdiv($budget, 2);
                }
            }
        } catch (\Throwable) { return self::unavailable('generated_excerpt_unrepresentable'); }
        return self::unavailable('generated_excerpt_unrepresentable');
    }

    private static function unavailable(string $reason): WP_Error
    {
        return new WP_Error('discussionbridge_cms_source_' . $reason, 'The actual native source cannot be represented safely; preserve its content, binding and current request.');
    }
}
