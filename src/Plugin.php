<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Plugin
{
    public static function boot(): void
    {
        add_action('admin_init', [Settings::class, 'register']);
        add_action('admin_menu', [Admin::class, 'register_menu']);
        add_action('admin_post_discussionbridge_retry', [Admin::class, 'retry']);
        add_action('add_meta_boxes', [Admin::class, 'register_meta_box']);
        add_action('save_post', [Admin::class, 'save_meta_box'], 10, 2);
        add_action('wp_after_insert_post', [Publisher::class, 'post_saved'], 10, 4);
        add_action(Publisher::DELIVERY_HOOK, [Publisher::class, 'deliver'], 10, 1);
        add_action('init', [Presentation::class, 'register_block']);
        add_shortcode('discussionbridge_record', [Presentation::class, 'shortcode']);
    }

    public static function activate(): void
    {
        if (!get_option(Settings::SITE_ID_OPTION)) {
            add_option(Settings::SITE_ID_OPTION, wp_generate_uuid4(), '', false);
        }
        add_option(Settings::POST_TYPES_OPTION, ['post'], '', false);
        add_option(Settings::LANE_OPTION, '', '', false);
    }
}
