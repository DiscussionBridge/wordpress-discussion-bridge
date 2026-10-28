<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Plugin
{
    private static ?NativeDeletion $deletion = null;

    public static function boot(): void
    {
        CmsNativeFence::register(); self::$deletion = CmsSourceWithdrawal::deletion_guard(); self::$deletion->register();
        add_filter('template_include', [CmsSourceArticleProfile::class, 'template'], PHP_INT_MAX);
        add_filter('the_content', [CmsSourceArticleProfile::class, 'content'], PHP_INT_MAX);
        add_action('admin_init', [Settings::class, 'register']);
        add_action('admin_menu', [Admin::class, 'register_menu']);
        add_action('admin_enqueue_scripts', [Admin::class, 'enqueue_assets']);
        add_action('wp_ajax_discussionbridge_refresh_catalogs', [CmsCatalog::class, 'ajax']);
        add_action('wp_ajax_discussionbridge_search_authors', [Admin::class, 'search_authors']);
        add_action('admin_post_discussionbridge_retry', [Admin::class, 'retry']);
        add_action('admin_post_discussionbridge_retry_synchronization', [Admin::class, 'retry_synchronization']);
        add_action('add_meta_boxes', [Admin::class, 'register_meta_box']);
        add_action('save_post', [Admin::class, 'save_meta_box'], 10, 2);
        add_action('wp_after_insert_post', [Publisher::class, 'post_saved'], 10, 4);
        add_action('transition_post_status', [CmsSourceWithdrawal::class, 'status_changed'], 10, 3);
        add_action(Publisher::DELIVERY_HOOK, [Publisher::class, 'deliver'], 10, 1);
        add_action('init', [FromSynchronizer::class, 'ensure_scheduled']);
        add_action('init', [Publisher::class, 'recover']);
        add_action(FromSynchronizer::HOOK, [FromSynchronizer::class, 'run']);
        foreach (['updated_option', 'added_option', 'deleted_option'] as $hook) add_action($hook, [FromSynchronizer::class, 'configuration_changed']);
        add_action('init', [Presentation::class, 'register_block']);
        add_action('wp_enqueue_scripts', [Presentation::class, 'enqueue_mapped_discussion']);
        add_filter('the_content', [Presentation::class, 'append_mapped_discussion']);
        add_filter('the_content', [CmsPresentation::class, 'wrap'], PHP_INT_MAX);
        add_filter('comments_open', [Presentation::class, 'native_comments_open'], 10, 2);
        add_filter('pings_open', [Presentation::class, 'native_comments_open'], 10, 2);
        add_filter('posts_clauses', [Materializer::class, 'source_time_query_clauses'], 10, 2);
        add_shortcode('discussionbridge_record', [Presentation::class, 'shortcode']);
    }

    public static function activate(): void
    {
        if (!get_option(Settings::SITE_ID_OPTION)) add_option(Settings::SITE_ID_OPTION, wp_generate_uuid4(), '', false);
        add_option(Settings::POST_TYPES_OPTION, ['post'], '', false); add_option(Settings::LANE_OPTION, '', '', false);
        add_option(Settings::COMMENTS_MODE_OPTION, 'interactive', '', false); add_option(Settings::CONNECTION_SECRET_OPTION, '', '', false);
        // Credentials and service author remain unconfigured; activation creates no publication work.
    }
}
