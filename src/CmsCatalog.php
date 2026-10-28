<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;

/** Explicit native administrator metadata producer; reuses protected journal and exact mutation codec. */
final class CmsCatalog
{
    public static function snapshot(CmsClient $client, string $kind): array|WP_Error
    {
        $items = [];
        if ($kind === 'authors') {
            foreach (get_users(['orderby' => 'ID', 'order' => 'ASC']) as $user) {
                if (!$user instanceof \WP_User || $user->ID <= 0) return self::failed();
                $available = false;
                foreach (Settings::post_types() as $type) { $native = get_post_type_object($type); if ($native !== null && user_can($user, $native->cap->publish_posts)) $available = true; }
                $items[] = (object) ['id' => (string) $user->ID, 'label' => sprintf('%s (@%s)', $user->display_name, $user->user_login), 'available' => $available];
            }
        } elseif ($kind === 'taxonomy') {
            $taxonomies = [];
            foreach (Settings::post_types() as $type) $taxonomies = array_merge($taxonomies, get_object_taxonomies($type, 'names'));
            $taxonomies = array_values(array_unique($taxonomies));
            $terms = $taxonomies === [] ? [] : get_terms(['taxonomy' => $taxonomies, 'hide_empty' => false]);
            if (is_wp_error($terms) || !is_array($terms)) return self::failed();
            foreach ($terms as $term) {
                if (!$term instanceof \WP_Term || $term->term_taxonomy_id <= 0) return self::failed();
                $available = false;
                foreach (Settings::post_types() as $type) if (taxonomy_exists($term->taxonomy) && is_object_in_taxonomy($type, $term->taxonomy)) $available = true;
                $items[] = (object) ['id' => (string) $term->term_taxonomy_id, 'label' => $term->name, 'available' => $available];
            }
        } else return self::failed();
        usort($items, static fn(stdClass $a, stdClass $b): int => strcmp($a->id, $b->id));
        $seen = [];
        try {
            foreach ($items as $item) {
                $client->codec()->validate('CatalogEntry', $item);
                if (isset($seen[$item->id])) return self::failed(); $seen[$item->id] = true;
            }
        } catch (\RuntimeException) { return self::failed(); }
        return $items;
    }

    /** One begin/page/finalize mutation per authenticated native request; interrupted requests replay unchanged. */
    public static function refresh(string $kind, string $scope, callable $guard): array|WP_Error
    {
        if (!in_array($kind, ['authors', 'taxonomy'], true) || !$guard()) return self::failed();
        $client = new CmsClient(); $connection = $client->request('V4-01'); if (is_wp_error($connection)) return $connection;
        if ($connection->connection_id !== Settings::connection_id() || $connection->profile !== 'wordpress'
            || $connection->publication_lane !== Settings::lane() || CmsCoordinator::origin($connection->destination_origin) !== CmsCoordinator::origin(home_url('/'))) return self::failed();
        $storage = new CmsJournal(); $state = $storage->read(); if (is_wp_error($state)) return $state;
        if ($state === null) $state = CmsCoordinator::fresh($scope);
        if (!isset($state->artifacts) || !CmsArtifacts::matches($state->artifacts)) return self::failed();
        $save = fn(): bool => $storage->write($state, $guard);
        if ($state->catalog === null) {
            $items = self::snapshot($client, $kind); if (is_wp_error($items)) return $items;
            $state->catalog = (object) ['kind' => $kind, 'scope' => $scope, 'native_revision' => hash('sha256', $client->codec()->encodeJson((object) ['types' => Settings::post_types(), 'items' => $items])),
                'items' => $items, 'index' => 0, 'page' => 0, 'upload' => null, 'pending' => null];
            if (!$save()) return self::failed();
        }
        $catalog = $state->catalog;
        if ($catalog->kind !== $kind || $catalog->scope !== $scope) return new WP_Error('discussionbridge_cms_catalog_reconciliation', 'An existing catalog refresh must be reconciled before replacing its snapshot.');
        $total = count($catalog->items);
        if ($catalog->upload === null) {
            $upload = CmsMutation::perform($client, 'V4-24', ['body' => (object) ['kind' => $kind, 'native_revision' => $catalog->native_revision, 'total_items' => $total]], $catalog, $save);
            if (is_wp_error($upload)) return self::response_error($upload, $state, $save);
            if (!self::upload_matches($upload, $catalog) || $upload->received_items !== 0 || $upload->next_page !== 0) return self::failed();
            $catalog->upload = $upload; CmsMutation::settled($catalog); if (!$save()) return self::failed();
            return ['done' => false, 'received' => 0, 'total' => $total];
        }
        if ($catalog->index < $total) {
            $page_items = [];
            foreach (array_slice($catalog->items, $catalog->index, 100) as $item) {
                $candidate = array_merge($page_items, [$item]);
                $probe = (object) ['mutation_id' => '00000000-0000-4000-8000-000000000000', 'page' => $catalog->page, 'items' => $candidate];
                if (strlen($client->codec()->encodeJson($probe)) > 65536) break;
                $page_items = $candidate;
            }
            if ($page_items === []) return self::failed();
            $upload = CmsMutation::perform($client, 'V4-25', ['path' => ['upload_id' => $catalog->upload->upload_id],
                'body' => (object) ['page' => $catalog->page, 'items' => $page_items]], $catalog, $save);
            if (is_wp_error($upload)) return self::response_error($upload, $state, $save);
            if (!self::upload_matches($upload, $catalog) || $upload->upload_id !== $catalog->upload->upload_id
                || $upload->received_items !== $catalog->index + count($page_items) || $upload->next_page !== $catalog->page + 1) return self::failed();
            $catalog->index = $upload->received_items; $catalog->page = $upload->next_page; $catalog->upload = $upload;
            CmsMutation::settled($catalog); if (!$save()) return self::failed();
            return ['done' => false, 'received' => $catalog->index, 'total' => $total];
        }
        $active = CmsMutation::perform($client, 'V4-26', ['path' => ['upload_id' => $catalog->upload->upload_id],
            'body' => (object) ['upload_id' => $catalog->upload->upload_id, 'expected_total_items' => $total]], $catalog, $save);
        if (is_wp_error($active)) return self::response_error($active, $state, $save);
        if ($active->kind !== $kind || $active->native_revision !== $catalog->native_revision || $active->total_items !== $total
            || $client->codec()->semanticIdentity($active->items) !== $client->codec()->semanticIdentity(array_slice($catalog->items, 0, count($active->items)))
            || ($active->next_cursor === null) !== (count($active->items) === $total)) return self::failed();
        if (!isset($state->catalog_receipts)) $state->catalog_receipts = new stdClass();
        $state->catalog_receipts->$kind = $active; $state->catalog = null;
        return $save() ? ['done' => true, 'received' => $total, 'total' => $total] : self::failed();
    }

    private static function upload_matches(stdClass $upload, stdClass $catalog): bool
    {
        return $upload->kind === $catalog->kind && $upload->native_revision === $catalog->native_revision && $upload->total_items === count($catalog->items);
    }

    public static function read(string $kind, ?string $cursor = null): stdClass|WP_Error
    {
        $query = (object) ['kind' => $kind]; if ($cursor !== null) $query->cursor = $cursor;
        return (new CmsClient())->request('V4-27', ['query' => $query]);
    }

    private static function response_error(WP_Error $error, stdClass $state, callable $save): WP_Error
    {
        $data = $error->get_error_data();
        if (is_array($data) && ($data['code'] ?? '') === 'catalog_upload_expired') {
            if (!isset($state->catalog_history)) $state->catalog_history = [];
            $state->catalog_history[] = $state->catalog; $state->catalog = null;
            if (!$save()) return self::failed();
            return new WP_Error('discussionbridge_cms_catalog_expired', 'The expired refresh was preserved. Select Refresh again to capture a new native snapshot; the previously active catalog remains unchanged.');
        }
        return $error;
    }

    public static function ajax(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Native administrator permission is required.'], 403);
        check_ajax_referer('discussionbridge_refresh_catalogs', 'nonce');
        $kind = isset($_POST['kind']) && is_string($_POST['kind']) ? wp_unslash($_POST['kind']) : '';
        $result = FromSynchronizer::refresh_catalog($kind);
        if (is_wp_error($result)) wp_send_json_error(['message' => $result->get_error_message()], 409);
        wp_send_json_success($result);
    }

    private static function failed(): WP_Error { return new WP_Error('discussionbridge_cms_catalog', 'The native catalog snapshot or upload needs attention. Preserve the pending refresh and retry it unchanged.'); }
}
