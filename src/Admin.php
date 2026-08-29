<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

final class Admin
{
    public static function register_menu(): void
    {
        add_options_page(
            'DiscussionBridge',
            'DiscussionBridge',
            'manage_options',
            'discussionbridge',
            [self::class, 'render']
        );
    }

    public static function register_meta_box(): void
    {
        foreach (Settings::post_types() as $post_type) {
            add_meta_box(
                'discussionbridge-publish',
                'DiscussionBridge',
                [self::class, 'render_meta_box'],
                $post_type,
                'side',
                'default'
            );
        }
    }

    public static function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('discussionbridge_post_' . $post->ID, 'discussionbridge_post_nonce');
        $enabled = get_post_meta($post->ID, Publisher::ENABLED_META, true) === '1';
        ?>
        <label><input type="checkbox" name="discussionbridge_enabled" value="1" <?php checked($enabled); ?>> <?php echo esc_html__('Create or retain this post’s DiscussionBridge record when it is first published.', 'discussionbridge'); ?></label>
        <p class="description"><?php echo esc_html__('Later edits do not rewrite the Discourse topic. Use the status page for an exact retry.', 'discussionbridge'); ?></p>
        <?php
    }

    public static function save_meta_box(int $post_id, \WP_Post $post): void
    {
        if (!in_array($post->post_type, Settings::post_types(), true)
            || wp_is_post_revision($post_id)
            || wp_is_post_autosave($post_id)
            || !isset($_POST['discussionbridge_post_nonce'])
            || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['discussionbridge_post_nonce'])), 'discussionbridge_post_' . $post_id)
            || !current_user_can('edit_post', $post_id)) {
            return;
        }
        if (isset($_POST['discussionbridge_enabled']) && $_POST['discussionbridge_enabled'] === '1') {
            update_post_meta($post_id, Publisher::ENABLED_META, '1');
        } else {
            delete_post_meta($post_id, Publisher::ENABLED_META);
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to manage DiscussionBridge.', 'discussionbridge'));
        }
        $post_types = get_post_types(['public' => true], 'objects');
        $recent = get_posts([
            'post_type' => Settings::post_types(),
            'post_status' => 'publish',
            'posts_per_page' => 50,
            'meta_key' => '_discussionbridge_status',
            'orderby' => 'modified',
            'order' => 'DESC',
        ]);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('DiscussionBridge', 'discussionbridge'); ?></h1>
            <p><?php echo esc_html__('One WordPress installation, one independently scoped Content Connection.', 'discussionbridge'); ?></p>
            <?php settings_errors(); ?>
            <form method="post" action="options.php">
                <?php settings_fields('discussionbridge'); ?>
                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="discussionbridge_server_url"><?php echo esc_html__('Discourse origin', 'discussionbridge'); ?></label></th>
                        <td><input class="regular-text" type="url" id="discussionbridge_server_url" name="<?php echo esc_attr(Settings::SERVER_URL_OPTION); ?>" value="<?php echo esc_attr(Settings::server_url()); ?>" placeholder="https://forum.example" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="discussionbridge_connection_id"><?php echo esc_html__('Connection ID', 'discussionbridge'); ?></label></th>
                        <td><input class="regular-text" type="text" id="discussionbridge_connection_id" name="<?php echo esc_attr(Settings::CONNECTION_ID_OPTION); ?>" value="<?php echo esc_attr(Settings::connection_id()); ?>" pattern="dbc_[a-f0-9]{24}" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Connection secret', 'discussionbridge'); ?></th>
                        <td><strong><?php echo Settings::connection_secret() !== '' ? esc_html__('Available from protected server configuration', 'discussionbridge') : esc_html__('Missing', 'discussionbridge'); ?></strong><p class="description"><?php echo esc_html__('The secret is never stored in WordPress options. Configure DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE outside the webroot.', 'discussionbridge'); ?></p></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="discussionbridge_lane"><?php echo esc_html__('Lane', 'discussionbridge'); ?></label></th>
                        <td><input class="regular-text" type="text" id="discussionbridge_lane" name="<?php echo esc_attr(Settings::LANE_OPTION); ?>" value="<?php echo esc_attr(Settings::lane()); ?>"></td>
                    </tr>
                    <tr>
                        <th scope="row"><?php echo esc_html__('Published post types', 'discussionbridge'); ?></th>
                        <td>
                            <?php foreach ($post_types as $post_type) : if ($post_type->name === 'attachment') { continue; } ?>
                                <label><input type="checkbox" name="<?php echo esc_attr(Settings::POST_TYPES_OPTION); ?>[]" value="<?php echo esc_attr($post_type->name); ?>" <?php checked(in_array($post_type->name, Settings::post_types(), true)); ?>> <?php echo esc_html($post_type->labels->singular_name); ?></label><br>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                </table>
                <?php submit_button(); ?>
            </form>

            <h2><?php echo esc_html__('Published-content delivery', 'discussionbridge'); ?></h2>
            <p><code>[discussionbridge_record resource_id="…"]</code> <?php echo esc_html__('renders an authorized From Discourse record.', 'discussionbridge'); ?></p>
            <table class="widefat striped">
                <thead><tr><th><?php echo esc_html__('Post', 'discussionbridge'); ?></th><th><?php echo esc_html__('Status', 'discussionbridge'); ?></th><th><?php echo esc_html__('Resource', 'discussionbridge'); ?></th><th><?php echo esc_html__('Topic', 'discussionbridge'); ?></th><th><?php echo esc_html__('Last result', 'discussionbridge'); ?></th><th></th></tr></thead>
                <tbody>
                <?php if (!$recent) : ?>
                    <tr><td colspan="6"><?php echo esc_html__('No DiscussionBridge deliveries yet.', 'discussionbridge'); ?></td></tr>
                <?php endif; ?>
                <?php foreach ($recent as $post) : ?>
                    <tr>
                        <td><a href="<?php echo esc_url(get_edit_post_link($post)); ?>"><?php echo esc_html(get_the_title($post)); ?></a></td>
                        <td><?php echo esc_html((string) get_post_meta($post->ID, '_discussionbridge_status', true)); ?></td>
                        <td><code><?php echo esc_html((string) get_post_meta($post->ID, '_discussionbridge_resource_id', true)); ?></code></td>
                        <td><?php $topic_url = (string) get_post_meta($post->ID, '_discussionbridge_topic_url', true); ?><?php if ($topic_url !== '') : ?><a href="<?php echo esc_url($topic_url); ?>"><?php echo esc_html((string) get_post_meta($post->ID, '_discussionbridge_topic_id', true)); ?></a><?php endif; ?></td>
                        <td><?php echo esc_html((string) get_post_meta($post->ID, '_discussionbridge_last_outcome', true)); ?> / <?php echo esc_html((string) get_post_meta($post->ID, '_discussionbridge_last_reason', true)); ?></td>
                        <td><form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>"><input type="hidden" name="action" value="discussionbridge_retry"><input type="hidden" name="post_id" value="<?php echo (int) $post->ID; ?>"><?php wp_nonce_field('discussionbridge_retry_' . $post->ID); ?><?php submit_button(__('Retry', 'discussionbridge'), 'secondary small', 'submit', false); ?></form></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php
    }

    public static function retry(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to retry DiscussionBridge delivery.', 'discussionbridge'));
        }
        $post_id = isset($_POST['post_id']) ? absint($_POST['post_id']) : 0;
        check_admin_referer('discussionbridge_retry_' . $post_id);
        Publisher::queue($post_id, true);
        wp_safe_redirect(admin_url('options-general.php?page=discussionbridge'));
        exit;
    }
}
