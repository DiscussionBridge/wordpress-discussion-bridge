<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

use stdClass;
use WP_Error;
use WP_Post;
use WP_Term;
use WP_User;

/** Translate already typed current CMS policy into actual native assignments; never grants scope. */
final class CmsNativeSelection
{
    private static function unavailable(string $message): WP_Error
    {
        return new WP_Error('discussionbridge_cms_metadata_unavailable', $message);
    }

    /** Empty selections omit update assignments; a new object uses the existing native default. */
    public static function resolve(stdClass $connection, stdClass $policy, ?WP_Post $existing = null): array|WP_Error
    {
        if ($connection->profile !== 'wordpress' || $policy->publication_kind !== 'dynamic'
            || $connection->native_capabilities->publication_kind !== 'dynamic') {
            return self::unavailable('The selected publication kind is not supported by this native WordPress installation.');
        }
        if (!in_array($policy->presentation_mode, ['simple', 'full', 'interactive'], true)
            || !in_array($policy->presentation_mode, $connection->native_capabilities->supported_presentations, true)) {
            return self::unavailable('The selected discussion mode is unavailable; select a supported mode in the forum policy.');
        }
        $post_type = $existing?->post_type ?? Settings::post_types()[0];
        $type = get_post_type_object($post_type);
        if ($type === null || !in_array($post_type, Settings::post_types(), true)) {
            return self::unavailable('The bound native post type is unavailable or outside the selected WordPress types.');
        }
        $post = ['post_type' => $post_type];
        if (count($policy->author_ids) > 1) {
            return self::unavailable('This WordPress post type supports one native author; reconcile the multiple selected policy authors.');
        }
        if ($policy->author_ids !== []) {
            $id = self::native_id($policy->author_ids[0]);
            $user = $id === null ? false : get_user_by('id', $id);
            if (!$user instanceof WP_User || (string) $user->ID !== $policy->author_ids[0]
                || !user_can($user, $type->cap->publish_posts)) {
                return self::unavailable('The selected native author is missing or cannot publish the selected native post type.');
            }
            $post['post_author'] = $user->ID;
        } elseif ($existing === null) {
            $author = get_user_by('id', Settings::service_author_id());
            if (!$author instanceof WP_User || !user_can($author, $type->cap->publish_posts)) {
                return self::unavailable('The configured default service author cannot publish the selected native post type.');
            }
            $post['post_author'] = $author->ID;
        }
        $terms = [];
        foreach ($policy->taxonomy_ids as $opaque_id) {
            $id = self::native_id($opaque_id);
            $term = $id === null ? false : get_term_by('term_taxonomy_id', $id);
            if (!$term instanceof WP_Term || (string) $term->term_taxonomy_id !== $opaque_id
                || !taxonomy_exists($term->taxonomy) || !is_object_in_taxonomy($post_type, $term->taxonomy)) {
                return self::unavailable('A selected native taxonomy identity is missing or unavailable for the bound post type.');
            }
            $terms[$term->taxonomy][] = (int) $term->term_id;
        }
        return ['post' => $post, 'terms' => $terms, 'presentation_mode' => $policy->presentation_mode];
    }

    /** Native catalog IDs are actual IDs rendered as text, not coercible names or wire CoreIds. */
    private static function native_id(string $id): ?int
    {
        if (preg_match('/\A[1-9][0-9]*\z/D', $id) !== 1 || (string) (int) $id !== $id) return null;
        return (int) $id;
    }
}
