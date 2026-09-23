<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class PlatformCatalog
{
    private const MAX_CATALOG_BYTES = 240 * 1024;

    /** @return array<string, mixed>|\WP_Error */
    public static function build(): array|\WP_Error
    {
        $containers = [];
        foreach (get_post_types(['public' => true], 'names') as $key => $value) {
            $name = is_string($key) && !is_int($key) ? $key : (string) $value;
            if ($name === '' || $name === 'attachment') {
                continue;
            }
            $object = function_exists('get_post_type_object') ? get_post_type_object($name) : null;
            $label = is_object($object) && isset($object->labels->singular_name)
                ? (string) $object->labels->singular_name
                : ucwords(str_replace(['-', '_'], ' ', $name));
            $containers[] = [
                'id' => 'post_type:' . $name,
                'label' => $label,
                'kind' => 'post_type',
                'taxonomy_ids' => function_exists('get_object_taxonomies')
                    ? array_values(array_intersect(['category', 'post_tag'], get_object_taxonomies($name, 'names')))
                    : [],
            ];
        }
        if (count($containers) > 500) {
            return new \WP_Error('discussionbridge_platform_container_limit');
        }

        $taxonomies = [];
        $remaining_terms = 5000;
        $terms_complete = true;
        $terms_observed = 0;
        foreach (['category', 'post_tag'] as $taxonomy) {
            $terms = [];
            if (function_exists('get_terms')) {
                $items = get_terms([
                    'taxonomy' => $taxonomy,
                    'hide_empty' => false,
                    'number' => $remaining_terms + 1,
                    'orderby' => 'term_id',
                    'order' => 'ASC',
                ]);
                if (is_wp_error($items) || !is_array($items)) {
                    return new \WP_Error('discussionbridge_platform_taxonomy_inventory_failed');
                }
                foreach ($items as $term) {
                    $terms_observed++;
                    if ($remaining_terms <= 0) {
                        $terms_complete = false;
                        break;
                    }
                    if (!is_object($term) || !isset($term->term_id, $term->name)) {
                        return new \WP_Error('discussionbridge_platform_taxonomy_inventory_failed');
                    }
                    $item = [
                        'id' => $taxonomy . ':' . (int) $term->term_id,
                        'label' => (string) $term->name,
                        'kind' => 'term',
                    ];
                    if ((int) ($term->parent ?? 0) > 0) {
                        $item['parent_id'] = $taxonomy . ':' . (int) $term->parent;
                    }
                    $terms[] = $item;
                    $remaining_terms--;
                }
            }
            $taxonomies[] = [
                'id' => $taxonomy,
                'label' => $taxonomy === 'category' ? 'Categories' : 'Tags',
                'kind' => 'taxonomy',
                'terms' => $terms,
            ];
        }
        if (!$terms_complete) {
            return new \WP_Error('discussionbridge_platform_term_inventory_too_large');
        }

        $authors = [];
        $authors_complete = true;
        if (function_exists('get_users')) {
            $users = get_users([
                'capability' => 'publish_posts',
                'fields' => ['ID', 'display_name', 'user_login'],
                'number' => 501,
                'orderby' => 'ID',
                'order' => 'ASC',
            ]);
            $users = is_array($users) ? $users : [];
            $authors_complete = count($users) <= 500;
            if (!$authors_complete) {
                return new \WP_Error('discussionbridge_platform_author_inventory_too_large');
            }
            foreach (array_slice($users, 0, 500) as $user) {
                if (!$user instanceof \WP_User || !user_can($user, 'publish_posts')) {
                    continue;
                }
                $label = trim((string) $user->display_name);
                $authors[] = [
                    'id' => 'user:' . $user->ID,
                    'label' => $label !== '' ? $label : (string) $user->user_login,
                    'kind' => 'author',
                ];
            }
        }

        $service_author_id = Settings::service_author_id();
        $service_key = 'user:' . $service_author_id;
        if ($service_author_id > 0 && !in_array($service_key, array_column($authors, 'id'), true)) {
            $service_user = get_user_by('id', $service_author_id);
            if ($service_user instanceof \WP_User && user_can($service_user, 'publish_posts')) {
                if (count($authors) >= 500) {
                    return new \WP_Error('discussionbridge_platform_author_inventory_too_large');
                }
                $label = trim((string) $service_user->display_name);
                $authors[] = [
                    'id' => $service_key,
                    'label' => $label !== '' ? $label : (string) $service_user->user_login,
                    'kind' => 'author',
                ];
            }
        }

        $catalog = [
            'schema_version' => 1,
            'platform' => 'wordpress',
            'containers' => $containers,
            'taxonomies' => $taxonomies,
            'authors' => $authors,
            'service_author_id' => $service_key,
            'presentation_modes' => ['simple', 'full', 'fullInteractive', 'native'],
            'capabilities' => [
                'updates' => true,
                'unpublish' => true,
                'drafts' => true,
            ],
            'limits' => [
                'content_bytes' => ForumPublisher::MAX_FORUM_PUBLICATION_HTML_BYTES,
                'title_bytes' => 1000,
                'slug_bytes' => 200,
            ],
            'inventory' => [
                'authors_complete' => $authors_complete,
                'terms_complete' => $terms_complete,
                'authors_observed' => count($users ?? []),
                'terms_observed' => $terms_observed,
            ],
        ];
        $encoded = wp_json_encode($catalog);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_CATALOG_BYTES) {
            return new \WP_Error('discussionbridge_platform_catalog_too_large');
        }
        return $catalog;
    }
}
