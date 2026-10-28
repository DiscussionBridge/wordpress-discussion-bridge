<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;
use WP_Post;

/** Leased native writes, exact owned identity, persisted CAS and crash receipt in one native transaction. */
final class CmsNativeWriter
{
    public static function apply(CmsClient $client, stdClass $connection, stdClass $policy, stdClass $job, stdClass $journal,
        callable $save, callable $guard): stdClass|WP_Error
    {
        global $wpdb;
        $mapping = $job->mapping; $lease = $journal->lease; $work = $lease->work;
        if (!CmsLease::valid($journal, $guard, 30) || $work->mapping_id !== $mapping->mapping_id) return self::failed('lease');
        if ($work->action === 'publish_revision' && $mapping->presentation_mode !== $policy->presentation_mode) return self::failed('presentation_move_required');
        $key = $mapping->mapping_id;
        $fence = $journal->fences->$key ?? null;
        if ($fence !== null && ($fence->generation > $lease->lease_generation
            || ($fence->generation === $lease->lease_generation && $fence->work_id !== $work->work_id))) return self::failed('stale_generation');
        if ($fence === null || $fence->generation < $lease->lease_generation) {
            $journal->fences->$key = (object) ['generation' => $lease->lease_generation, 'work_id' => $work->work_id,
                'unresolved_create' => $fence?->unresolved_create ?? null];
            if (!$save()) return self::failed('fence_commit');
        }
        if (!isset($job->creation_attempt)) $job->creation_attempt = null;
        $body = null;
        if ($work->action === 'publish_revision') {
            $source = $job->source;
            if ($source === null || $source->source_revision !== $work->source_revision || $source->source_id !== $mapping->source_id || $job->hydration === null || !$job->hydration->complete) return self::failed('source');
            $original = base64_decode($job->hydration->bytes_base64, true);
            // Immutable source integrity precedes EVERY native conversion, including relative reference preparation.
            if (!is_string($original) || strlen($original) !== $source->content_bytes || !hash_equals(hash('sha256', $original), $source->content_sha256) || preg_match('//u', $original) !== 1) return self::failed('source');
            try { $client->codec()->validate('Source', $source); } catch (\Throwable) { return self::failed('source'); }
            $prepared = $job->native_preparation ?? null;
            if ($prepared !== null && $prepared->source->source_revision === $source->source_revision
                && $client->codec()->semanticIdentity($prepared->source) !== $client->codec()->semanticIdentity($source)) return self::failed('source');
            if ($prepared === null || $prepared->source->source_revision !== $source->source_revision) {
                $article = NativeContent::relative_targets($original, $source->topic_url);
                if (is_wp_error($article)) {
                    $job->local_issue = (object) ['observation' => (object) ['code' => $article->get_error_code(), 'detail' => $article->get_error_data()],
                        'authority' => CmsLease::authority($lease), 'work' => clone $work, 'mapping' => clone $mapping,
                        'prior_native_receipt' => $mapping->destination_native_id === null ? null : get_post_meta((int) $mapping->destination_native_id, CmsPresentation::META, true),
                        'prior_native_result' => $job->native_result, 'repair_proof' => 'unresolved_native_reference_preparation', 'native_write_started' => false];
                    if (!$guard() || !$save()) return self::failed('local_issue_commit');
                    return new WP_Error('discussionbridge_cms_native_body_limitation', 'Bridge cannot faithfully prepare this exact source reference form.');
                }
                $body = $article . CmsPresentation::provenance($connection, $source);
                $job->native_preparation = (object) ['source' => json_decode($client->codec()->encodeJson($source), false, 512, JSON_THROW_ON_ERROR), 'source_article_url' => $source->topic_url,
                    'article_base64' => base64_encode($article), 'article_bytes' => strlen($article), 'article_sha256' => hash('sha256', $article),
                    'body_base64' => base64_encode($body), 'body_sha256' => hash('sha256', $body)];
                if (!$guard() || !$save()) return self::failed('preparation_commit');
            } else {
                $body = base64_decode($prepared->body_base64, true);
                if (!is_string($body) || !hash_equals($prepared->body_sha256, hash('sha256', $body))) return self::failed('preparation');
            }
        }
        if (!CmsNativeFence::transactional_tables() || $wpdb->query('START TRANSACTION') === false) return self::failed('transaction');
        if (!CmsNativeFence::begin(true)) { $wpdb->query('ROLLBACK'); return self::failed('transaction'); }
        $native_id = 0; $committed = false; $prior_result = $job->native_result; $body_issue = null;
        try {
            $claims = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT 2',
                '_discussionbridge_resource_id', $mapping->resource_id));
            if (!is_array($claims) || $wpdb->last_error !== '' || count($claims) > 1) return self::failed('identity_census');
            $native_id = $mapping->destination_native_id === null ? ($claims === [] ? 0 : (int) $claims[0]) : (int) $mapping->destination_native_id;
            if ($mapping->destination_native_id !== null && ((string) $native_id !== $mapping->destination_native_id || $claims !== [(string) $native_id])) return self::failed('identity');
            if ($native_id > 0) clean_post_cache($native_id);
            $native = $native_id > 0 ? get_post($native_id) : null;
            $previous = $native_id > 0 ? get_post_meta($native_id, CmsPresentation::META, true) : null;
            $expected_token = null;
            if ($native_id > 0) {
                if (!$native instanceof WP_Post || !is_array($previous)
                    || $previous['receipt']['mapping_id'] !== $mapping->mapping_id || $previous['receipt']['resource_id'] !== $mapping->resource_id
                    || $previous['receipt']['source_id'] !== $mapping->source_id || $previous['receipt']['external_id'] !== $mapping->external_id
                    || $previous['receipt']['canonical_url'] !== $mapping->canonical_url || $previous['receipt']['native_id'] !== (string) $native_id
                    || $previous['receipt']['connection_id'] !== $connection->connection_id || $previous['receipt']['forum_origin'] !== Settings::server_url()) return self::failed('ownership');
                $current = CmsNativeFence::revision($native_id, true);
                if (!is_string($current) || !hash_equals($previous['managed_revision'] ?? '', $current)) return self::failed('native_drift');
                if ($previous['receipt']['last_lease_generation'] > $lease->lease_generation) return self::failed('stale_generation');
                if (($previous['work_id'] ?? null) === $work->work_id && $previous['receipt']['last_lease_generation'] === $lease->lease_generation
                    && ($previous['action'] ?? null) === $work->action && ($previous['receipt']['source_revision'] ?? null) === $work->source_revision) {
                    $result = self::result($client, $mapping, $previous, $current);
                    $job->native_result = $result;
                    if (!$save() || !CmsLease::valid($journal, $guard, 5) || !CmsNativeFence::commit_transaction()) return self::failed('crash_receipt');
                    $committed = true; return $result;
                }
                $expected_token = CmsNativeFence::token($native_id);
                if (!is_string($expected_token)) return self::failed('native_revision');
            } elseif ($work->action === 'withdraw') return self::failed('withdrawal_identity');
            $selection = $work->action === 'withdraw' ? ['post' => [], 'terms' => []] : CmsNativeSelection::resolve($connection, $policy, $native);
            if (is_wp_error($selection)) return $selection;
            if (!CmsLease::valid($journal, $guard, 30)) return self::failed('lease');
            if ($native_id === 0) {
                if ($job->creation_attempt !== null || ($journal->fences->$key->unresolved_create ?? null) !== null) return self::failed('creation_reconciliation');
                if (url_to_postid($mapping->canonical_url) > 0 || $job->plan === null || $job->plan->canonical_url !== $mapping->canonical_url) return self::failed('url_collision');
                // An existing native source claiming this topic is never converted or duplicated implicitly.
                $topic_claims = $wpdb->get_col($wpdb->prepare('SELECT DISTINCT post_id FROM ' . $wpdb->postmeta . ' WHERE meta_key = %s AND meta_value = %s ORDER BY post_id ASC LIMIT 2', '_discussionbridge_topic_id', $job->source->topic_id));
                if (!is_array($topic_claims) || $wpdb->last_error !== '' || $topic_claims !== []) return self::failed('existing_topic_reconciliation');
                $job->creation_attempt = (object) ['operation_id' => wp_generate_uuid4(), 'native_id' => null];
                $journal->fences->$key->unresolved_create = $job->creation_attempt->operation_id;
                // A separate durable marker precedes SQL; the transaction is restarted only before any native create.
                if (!CmsNativeFence::rollback_transaction()) return self::failed('creation_reconciliation');
                CmsNativeFence::finish(false);
                if (!$save() || $wpdb->query('START TRANSACTION') === false || !CmsNativeFence::begin(true)) return self::failed('creation_reconciliation');
                $saved = CmsNativeFence::with_body(0, $job->creation_attempt->operation_id, $body, fn() => wp_insert_post(wp_slash($selection['post'] + ['post_status' => 'draft', 'post_name' => $job->plan->slug,
                    'post_title' => $job->source->title, 'post_content' => $body, 'meta_input' => [Materializer::IMPORTED_META => '1',
                        '_discussionbridge_resource_id' => $mapping->resource_id, '_discussionbridge_cms_creation_operation' => $job->creation_attempt->operation_id]]), true));
                if (is_wp_error($saved) || !is_int($saved) || $saved <= 0) return self::failed('create');
                $native_id = $saved;
                $job->creation_attempt->native_id = (string) $native_id;
                if (!CmsNativeFence::current_connection() || !CmsLease::valid($journal, $guard, 25)) return self::failed('creation_reconciliation');
                if (CmsPermalink::actual_draft($native_id) !== $mapping->canonical_url) return self::failed('permalink');
            }
            $receipt = (object) ['schema_version' => 'db-native-ownership-1', 'forum_origin' => Settings::server_url(), 'connection_id' => $connection->connection_id,
                'mapping_id' => $mapping->mapping_id, 'resource_id' => $mapping->resource_id, 'source_id' => $mapping->source_id,
                'external_id' => $mapping->external_id, 'native_id' => (string) $native_id, 'canonical_url' => $mapping->canonical_url,
                'source_revision' => $work->source_revision, 'last_lease_generation' => $lease->lease_generation, 'move_id' => null, 'proof_nonce' => null];
            $client->codec()->validate('NativeOwnershipReceipt', $receipt);
            // The expectation precedes metadata changes. New owned drafts acquire their first expectation below.
            if ($expected_token === null) {
                $state = ['receipt' => (array) $receipt, 'action' => $work->action, 'work_id' => $work->work_id,
                    'mode' => $policy->presentation_mode, 'topic_id' => $job->source->topic_id, 'topic_url' => $job->source->topic_url,
                    'source_sha256' => $job->source->content_sha256];
                if (!self::meta($native_id, CmsPresentation::META, $state)) return self::failed('receipt');
                $expected_token = CmsNativeFence::token($native_id); if (!is_string($expected_token)) return self::failed('native_revision');
            }
            $post = $work->action === 'withdraw' ? ['ID' => $native_id, 'post_status' => 'draft']
                : $selection['post'] + ['ID' => $native_id, 'post_status' => 'publish', 'post_title' => $job->source->title, 'post_content' => $body];
            $write = fn() => CmsNativeFence::with_expected($native_id, $expected_token, fn() => wp_update_post(wp_slash($post), true));
            $saved = $work->action === 'publish_revision' ? CmsNativeFence::with_body($native_id, null, $body, $write) : $write();
            if (is_wp_error($saved) || $saved !== $native_id || !CmsLease::valid($journal, $guard, 20)) return self::failed('write');
            if ($work->action === 'publish_revision') {
                foreach ($selection['terms'] as $taxonomy => $terms) {
                    if (!$guard()) return self::failed('taxonomy');
                    $assigned = wp_set_object_terms($native_id, $terms, $taxonomy, false);
                    if (is_wp_error($assigned) || !is_array($assigned) || count($assigned) !== count($terms)) return self::failed('taxonomy');
                    foreach ($terms as $term_id) {
                        $term = get_term($term_id, $taxonomy);
                        if (!$term instanceof \WP_Term || !in_array((string) $term->term_taxonomy_id, array_map('strval', $assigned), true)) return self::failed('taxonomy');
                    }
                }
                if (get_permalink($native_id) !== $mapping->canonical_url) return self::failed('permalink');
                $actual = get_post($native_id);
                if (!$actual instanceof WP_Post || $actual->post_status !== 'publish' || $actual->post_content !== $body || $actual->post_title !== $job->source->title) return self::failed('native_content');
            }
            $state = ['receipt' => (array) $receipt, 'action' => $work->action, 'work_id' => $work->work_id,
                'mode' => $policy->presentation_mode, 'topic_id' => $job->source?->topic_id ?? $previous['topic_id'],
                'topic_url' => $job->source?->topic_url ?? $previous['topic_url'], 'source_sha256' => $job->source?->content_sha256 ?? $previous['source_sha256']];
            if (!self::meta($native_id, CmsPresentation::META, $state)) return self::failed('receipt');
            $metadata = ['imported_from_discourse' => '1', 'resource_id' => $mapping->resource_id, 'topic_id' => $state['topic_id'],
                'topic_url' => $state['topic_url'], 'canonical_url' => $mapping->canonical_url, 'source_revision' => $work->source_revision ?? '',
                'comments_mode' => $policy->presentation_mode, 'status' => $work->action === 'withdraw' ? 'paused' : 'healthy',
                'source_forum_name' => $connection->forum_name];
            if ($job->source !== null) {
                $metadata['source_created_at'] = $job->source->source_created_at ?? '';
                $metadata['source_updated_at'] = $job->source->source_updated_at ?? '';
                $metadata['source_updated_at_timestamp'] = $job->source->source_updated_at === null ? '' : self::timestamp($job->source->source_updated_at);
            }
            foreach ($metadata as $name => $value) if (!self::meta($native_id, '_discussionbridge_' . $name, $value)) return self::failed('metadata');
            $revision = CmsNativeFence::revision($native_id, true); if (!is_string($revision)) return self::failed('native_revision');
            $state['managed_revision'] = $revision;
            if (!self::meta($native_id, CmsPresentation::META, $state)) return self::failed('native_revision');
            $job->native_result = self::result($client, $mapping, $state, $revision);
            if ($job->creation_attempt !== null) $job->creation_attempt->committed = true;
            $journal->fences->$key->unresolved_create = null;
            if (!$save() || !CmsLease::valid($journal, $guard, 5) || !self::confirm($client, $job, $journal, $connection)
                || !CmsNativeFence::commit_transaction()) return self::failed('commit');
            $committed = true; return $job->native_result;
        } catch (CmsNativeBodyMismatch $error) {
            $body_issue = (object) ['observation' => (object) $error->observation,
                'authority' => CmsLease::authority($lease), 'work' => clone $work, 'mapping' => clone $mapping,
                'prior_native_receipt' => $previous ?? null, 'prior_native_result' => $prior_result,
                'repair_proof' => 'unresolved_final_native_hooks'];
            return new WP_Error('discussionbridge_cms_native_body_limitation', 'Native final preparation cannot faithfully preserve this exact complete source.');
        } catch (\Throwable) { return self::failed('native_exception'); }
        finally {
            if (!$committed) {
                $rolled_back = CmsNativeFence::rollback_transaction();
                wp_cache_delete((new CmsJournal())->option_name(), 'options'); $job->native_result = $prior_result;
                if ($body_issue !== null) {
                    $body_issue->rollback_proven = $rolled_back;
                    $body_issue->native_connection_retained = $guard();
                    $job->local_issue = $body_issue;
                }
                if ($rolled_back && $job->creation_attempt !== null) {
                    $job->creation_attempt = null; $journal->fences->$key->unresolved_create = null; $save();
                } elseif (!$rolled_back && $job->creation_attempt !== null) {
                    $journal->fences->$key->unresolved_create = $job->creation_attempt->operation_id;
                }
            }
            CmsNativeFence::finish($committed);
            if ($native_id > 0) clean_post_cache($native_id);
            if (isset($selection) && is_array($selection)) foreach ($selection['terms'] as $taxonomy => $terms) clean_term_cache($terms, $taxonomy);
            if ($body_issue !== null) {
                // Never manufacture rollback certainty, receiver failure, or a replacement creation.
                if (!$save()) return self::failed('local_issue_commit');
            }
        }
    }

    public static function confirm(CmsClient $client, stdClass $job, stdClass $journal, stdClass $connection): bool
    {
        if ($job->native_result === null) return false;
        $id = (int) $job->native_result->native_id; clean_post_cache($id); $native = get_post($id);
        $state = get_post_meta($id, CmsPresentation::META, true); $revision = CmsNativeFence::revision($id);
        if (!$native instanceof WP_Post || !is_array($state) || !is_string($revision)
            || $revision !== $job->native_result->native_revision || ($state['managed_revision'] ?? null) !== $revision
            || $state['receipt']['last_lease_generation'] !== $journal->lease->lease_generation || $state['work_id'] !== $journal->lease->work->work_id
            || $client->codec()->nativeOwnershipReceiptSha256((object) $state['receipt']) !== $job->native_result->ownership_receipt_sha256) return false;
        if ($job->native_result->kind === 'withdraw') return $native->post_status === 'draft';
        $source_bytes = base64_decode($job->hydration->bytes_base64, true);
        $prepared = $job->native_preparation ?? null;
        if (!$prepared instanceof stdClass || $client->codec()->semanticIdentity($prepared->source) !== $client->codec()->semanticIdentity($job->source)) return false;
        $body = base64_decode($prepared->body_base64, true);
        return is_string($source_bytes) && strlen($source_bytes) === $job->source->content_bytes && preg_match('//u', $source_bytes) === 1
            && hash_equals($job->source->content_sha256, hash('sha256', $source_bytes))
            && is_string($body) && hash_equals($prepared->body_sha256, hash('sha256', $body))
            && $native->post_status === 'publish' && $native->post_title === $job->source->title
            && $native->post_content === $body
            && get_permalink($id) === $job->mapping->canonical_url;
    }

    private static function result(CmsClient $client, stdClass $mapping, array $state, string $revision): stdClass
    {
        $result = (object) ['kind' => $state['action'], 'native_id' => $state['receipt']['native_id'], 'external_id' => $mapping->external_id,
            'canonical_url' => $mapping->canonical_url, 'native_revision' => $revision,
            'ownership_receipt_sha256' => $client->codec()->nativeOwnershipReceiptSha256((object) $state['receipt'])];
        if ($state['action'] === 'publish_revision') $result->representation = (object) ['kind' => 'full', 'source_content_sha256' => $state['source_sha256']];
        $client->codec()->validate('SyncResult', $result); return $result;
    }

    private static function meta(int $id, string $name, mixed $value): bool
    {
        update_post_meta($id, $name, wp_slash($value)); wp_cache_delete($id, 'post_meta');
        return get_post_meta($id, $name, true) === $value;
    }
    private static function timestamp(string $value): string { $instant = new \DateTimeImmutable($value); return $instant->format('U') . '.' . $instant->format('v'); }
    private static function failed(string $reason): WP_Error { return new WP_Error('discussionbridge_cms_native_' . $reason, 'The leased native publication requires reconciliation before completion.'); }
}
