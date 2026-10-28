<?php

declare(strict_types=1);

namespace DiscussionBridge\WordPress;

/** Existing native capability/nonce settings and per-source control surface. */
final class Admin
{
    public static function register_menu(): void { add_options_page('DiscussionBridge', 'DiscussionBridge', 'manage_options', 'discussionbridge', [self::class, 'render']); }

    public static function enqueue_assets(string $hook): void
    {
        if (!current_user_can('manage_options')) return;
        if ($hook === 'post.php') {
            wp_enqueue_script('discussionbridge-source-retry', plugins_url('resources/js/cms-source-retry.js', DISCUSSIONBRIDGE_WORDPRESS_FILE), [], DISCUSSIONBRIDGE_WORDPRESS_VERSION, true);
            return;
        }
        if ($hook !== 'settings_page_discussionbridge') return;
        wp_enqueue_script('discussionbridge-admin', plugins_url('assets/discussionbridge-admin.js', DISCUSSIONBRIDGE_WORDPRESS_FILE), ['jquery', 'jquery-ui-autocomplete'], DISCUSSIONBRIDGE_WORDPRESS_VERSION, true);
        wp_enqueue_style('discussionbridge-admin', plugins_url('assets/discussionbridge-admin.css', DISCUSSIONBRIDGE_WORDPRESS_FILE), [], DISCUSSIONBRIDGE_WORDPRESS_VERSION);
        wp_localize_script('discussionbridge-admin', 'DiscussionBridgeAdmin', ['ajaxUrl' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('discussionbridge_search_authors')]);
        wp_enqueue_script('discussionbridge-catalog', plugins_url('resources/js/cms-catalog-refresh.js', DISCUSSIONBRIDGE_WORDPRESS_FILE), [], DISCUSSIONBRIDGE_WORDPRESS_VERSION, true);
        wp_add_inline_script('discussionbridge-catalog', 'window.DiscussionBridgeCatalog=' . wp_json_encode(['url' => admin_url('admin-ajax.php'), 'nonce' => wp_create_nonce('discussionbridge_refresh_catalogs')]), 'before');
    }

    public static function search_authors(): void
    {
        if (!current_user_can('manage_options')) wp_send_json_error(['message' => 'Native administrator permission is required.'], 403);
        check_ajax_referer('discussionbridge_search_authors', 'nonce');
        $term = isset($_GET['term']) && is_string($_GET['term']) ? sanitize_text_field(wp_unslash($_GET['term'])) : '';
        $args = ['capability' => 'publish_posts', 'number' => 20, 'orderby' => 'display_name', 'order' => 'ASC'];
        if ($term !== '') { $args['search'] = '*' . $term . '*'; $args['search_columns'] = ['user_login', 'display_name']; }
        $items = [];
        foreach (get_users($args) as $user) if ($user instanceof \WP_User && user_can($user, 'publish_posts'))
            $items[] = ['label' => sprintf('%s (@%s)', $user->display_name, $user->user_login), 'value' => $user->user_login];
        wp_send_json($items);
    }

    public static function register_meta_box(): void
    {
        foreach (Settings::post_types() as $type) add_meta_box('discussionbridge-source', 'DiscussionBridge', [self::class, 'render_meta_box'], $type, 'side');
    }

    public static function render_meta_box(\WP_Post $post): void
    {
        wp_nonce_field('discussionbridge_post_' . $post->ID, 'discussionbridge_post_nonce');
        if (get_post_meta($post->ID, Materializer::IMPORTED_META, true) !== '1') {
            echo '<label><input type="checkbox" name="discussionbridge_enabled" value="1" ' . checked(get_post_meta($post->ID, Publisher::ENABLED_META, true), '1', false) . '> Publish this post and its later published edits through DiscussionBridge.</label>';
        }
        echo '<p>Discussion mode</p><select name="discussionbridge_comments_mode"><option value="">Use site default</option>';
        foreach (['none', 'simple', 'full', 'interactive'] as $mode) echo '<option value="' . esc_attr($mode) . '" ' . selected(get_post_meta($post->ID, Presentation::COMMENTS_MODE_META, true), $mode, false) . '>' . esc_html($mode) . '</option>';
        echo '</select>';
        if (get_post_meta($post->ID, Materializer::IMPORTED_META, true) === '1') {
            echo '<p>Math and diagram renderer</p><select name="discussionbridge_rich_content_post">';
            foreach (['' => 'Preserve current choice', 'site' => 'Use site selection', 'enabled' => 'Enabled', 'disabled' => 'Disabled; retain source'] as $value => $label)
                echo '<option value="' . esc_attr($value) . '" ' . selected(get_post_meta($post->ID, Presentation::RICH_CONTENT_META, true), $value, false) . '>' . esc_html($label) . '</option>';
            echo '</select>';
        }
        echo '<p>' . esc_html((string) get_post_meta($post->ID, '_discussionbridge_status', true)) . '</p>';
        self::render_retry_control($post);
    }

    private static function render_retry_control(\WP_Post $post): void
    {
        if (!current_user_can('manage_options') || !current_user_can('edit_post', $post->ID)
            || is_array(get_post_meta($post->ID, CmsPresentation::META, true))
            || $post->post_status !== 'publish' || !in_array($post->post_type, Settings::post_types(), true)
            || get_post_meta($post->ID, Publisher::ENABLED_META, true) !== '1') return;
        $state = (new CmsJournal($post->ID))->read();
        if (!$state instanceof \stdClass || !CmsMutation::invalid_field_rejected($state)
            || $state->scope !== CmsSourcePublisher::scope($post->ID) || $state->accepted !== null
            || isset($state->withdrawal) || isset($state->source_move) || isset($state->lifecycle_attention)) return;
        echo '<p>The source request was refused. Correct its cause before retrying; current source and ownership will be checked again.</p>';
        echo '<button type="button" class="button" data-discussionbridge-retry data-post-id="' . esc_attr((string) $post->ID)
            . '" data-request-identity="' . esc_attr(hash('sha256', $state->pending->identity)) . '" data-nonce="' . esc_attr(wp_create_nonce('discussionbridge_retry_' . $post->ID))
            . '" data-action-url="' . esc_url(admin_url('admin-post.php')) . '">Retry refused source request</button>';
    }

    public static function save_meta_box(int $id, \WP_Post $post): void
    {
        if (wp_is_post_revision($id) || wp_is_post_autosave($id) || !current_user_can('edit_post', $id)
            || !isset($_POST['discussionbridge_post_nonce']) || !is_string($_POST['discussionbridge_post_nonce'])
            || !wp_verify_nonce(wp_unslash($_POST['discussionbridge_post_nonce']), 'discussionbridge_post_' . $id)) return;
        $nonce = wp_unslash($_POST['discussionbridge_post_nonce']);
        if (get_post_meta($id, Materializer::IMPORTED_META, true) !== '1') {
            $was_enabled = get_post_meta($id, Publisher::ENABLED_META, true) === '1';
            if (($_POST['discussionbridge_enabled'] ?? null) === '1') {
                update_post_meta($id, Publisher::ENABLED_META, '1');
                delete_post_meta($id, CmsSourceRecovery::EXCLUSION_META);
            }
            else {
                if ($was_enabled) {
                    $fact = CmsSourceRecovery::exclusion_fact($id);
                    update_post_meta($id, CmsSourceRecovery::EXCLUSION_META, $fact); wp_cache_delete($id, 'post_meta');
                    if (get_post_meta($id, CmsSourceRecovery::EXCLUSION_META, true) !== $fact) return;
                }
                delete_post_meta($id, Publisher::ENABLED_META);
                if ($was_enabled && get_post_meta($id, Publisher::ENABLED_META, true) !== '1') CmsSourceWithdrawal::capture($id, 'policy_excluded', $post, true, $nonce);
            }
        }
        if (isset($_POST['discussionbridge_comments_mode']) && is_string($_POST['discussionbridge_comments_mode'])) {
            $mode = wp_unslash($_POST['discussionbridge_comments_mode']);
            if ($mode === '') delete_post_meta($id, Presentation::COMMENTS_MODE_META);
            elseif (in_array($mode, ['none', 'simple', 'full', 'interactive'], true)) update_post_meta($id, Presentation::COMMENTS_MODE_META, $mode);
        }
        if (isset($_POST['discussionbridge_rich_content_post']) && is_string($_POST['discussionbridge_rich_content_post'])) {
            $value = wp_unslash($_POST['discussionbridge_rich_content_post']);
            if ($value === '') delete_post_meta($id, Presentation::RICH_CONTENT_META);
            elseif (in_array($value, ['site', 'enabled', 'disabled'], true)) update_post_meta($id, Presentation::RICH_CONTENT_META, $value);
        }
    }

    public static function render(): void
    {
        if (!current_user_can('manage_options')) return;
        echo '<div class="wrap"><h1>DiscussionBridge</h1>'; settings_errors();
        echo '<form method="post" action="options.php">'; settings_fields('discussionbridge');
        foreach ([Settings::SERVER_URL_OPTION => ['Discourse HTTPS origin', Settings::server_url()], Settings::CONNECTION_ID_OPTION => ['Content Connection', Settings::connection_id()],
            Settings::LANE_OPTION => ['Publication lane', Settings::lane()], Settings::SERVICE_AUTHOR_OPTION => ['Existing native service author username', Settings::service_author_username()]] as $key => [$label, $value]) {
            echo '<p><label>' . esc_html($label) . '<br><input class="regular-text" id="' . esc_attr($key === Settings::SERVICE_AUTHOR_OPTION ? 'discussionbridge_service_author' : $key) . '" name="' . esc_attr($key) . '" value="' . esc_attr($value) . '"></label></p>';
        }
        echo '<p><label>Connection secret<br><input type="password" autocomplete="new-password" name="' . esc_attr(Settings::CONNECTION_SECRET_OPTION) . '" value=""></label> Leave blank to retain protected configuration.</p>';
        echo '<p>Native post types</p>';
        foreach (get_post_types(['public' => true], 'names') as $type) if ($type !== 'attachment') echo '<label><input type="checkbox" name="' . esc_attr(Settings::POST_TYPES_OPTION) . '[]" value="' . esc_attr($type) . '" ' . checked(in_array($type, Settings::post_types(), true), true, false) . '> ' . esc_html($type) . '</label> ';
        echo '<p>Discussion mode</p><select name="' . esc_attr(Settings::COMMENTS_MODE_OPTION) . '">';
        foreach (['none', 'simple', 'full', 'interactive'] as $mode) echo '<option ' . selected(Settings::comments_mode(), $mode, false) . '>' . esc_html($mode) . '</option>';
        echo '</select><p>Math and diagram renderer</p><select name="' . esc_attr(Settings::RICH_CONTENT_OPTION) . '">';
        foreach (['preserve' => 'Preserve current renderer behavior', 'enabled' => 'Enabled', 'disabled' => 'Disabled; retain source'] as $value => $label) echo '<option value="' . esc_attr($value) . '" ' . selected(Settings::rich_content(), $value, false) . '>' . esc_html($label) . '</option>';
        echo '</select>'; submit_button(); echo '</form>';
        $status = FromSynchronizer::status();
        echo '<h2>Publication status</h2><p>' . esc_html($status['status']) . '</p>';
        if ($status['local_failures'] >= 3 || $status['overdue'] || $status['status'] === 'scheduling_unavailable') {
            echo '<div class="notice notice-warning inline"><p>Local synchronization needs checking. Publication attention is handled separately in the forum.</p>';
            if ($status['reason'] !== '') echo '<p>' . esc_html($status['reason']) . '</p>';
            if ($status['overdue']) echo '<p>The scheduled check is overdue. WordPress needs a working cron invocation.</p>';
            echo '</div>';
        }
        foreach (['last_attempt' => 'Last local check', 'last_success' => 'Last successful check', 'next_due' => 'Next scheduled check'] as $key => $label)
            echo '<p>' . esc_html($label) . ': ' . esc_html(is_int($status[$key]) ? gmdate('Y-m-d H:i:s \\U\\T\\C', $status[$key]) : 'Not recorded') . '</p>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="discussionbridge_retry_synchronization">';
        wp_nonce_field('discussionbridge_retry_synchronization');
        echo '<button class="button" type="submit">Retry synchronization</button><p>This wakes local polling. It does not retry held publications or reset their attempts.</p></form>';
        if ($status['attention_count'] > 0) echo '<p>' . esc_html((string) $status['attention_count']) . ' retained jobs need native reconciliation. Continuing intake does not retry them.</p>';
        if ($status['attention_unknown_count'] > 0) echo '<p>' . esc_html((string) $status['attention_unknown_count']) . ' retained jobs await current forum status. Their stored history alone is not current attention.</p>';
        echo '<p>Native publication retry requires its current reconciliation mechanism. No retry cycle is created by this page.</p>';
        echo '<h2>Available native authors and taxonomy</h2><button class="button" type="button" data-discussionbridge-catalog="authors">Refresh authors</button> <button class="button" type="button" data-discussionbridge-catalog="taxonomy">Refresh taxonomy</button><p id="discussionbridge-catalog-status" role="status">Catalog refresh updates available choices without changing policy or publication selections.</p></div>';
    }

    public static function retry(): void
    {
        $raw_id = $_POST['post_id'] ?? null;
        $id = is_string($raw_id) && preg_match('/\A[1-9][0-9]{0,18}\z/D', $raw_id) === 1 && (string) (int) $raw_id === $raw_id ? (int) $raw_id : 0;
        $identity = $_POST['request_identity'] ?? null;
        $nonce = $_POST['_wpnonce'] ?? null;
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || $id <= 0 || !is_string($identity)
            || preg_match('/\A[a-f0-9]{64}\z/D', $identity) !== 1 || !is_string($nonce)
            || !current_user_can('manage_options') || !current_user_can('edit_post', $id)
            || is_array(get_post_meta($id, CmsPresentation::META, true))) wp_die('Current native reconciliation is required.');
        check_admin_referer('discussionbridge_retry_' . $id);
        $actor = get_current_user_id(); $nonce = wp_unslash($nonce);
        $result = FromSynchronizer::serialized(function (callable $physical) use ($id, $identity, $actor, $nonce): mixed {
            $guard = fn(): bool => $physical() && get_current_user_id() === $actor && current_user_can('manage_options')
                && current_user_can('edit_post', $id) && wp_verify_nonce($nonce, 'discussionbridge_retry_' . $id)
                && !is_array(get_post_meta($id, CmsPresentation::META, true));
            $state = (new CmsJournal($id))->read();
            if (!$state instanceof \stdClass || !is_string($state->pending->identity ?? null)
                || !hash_equals(hash('sha256', $state->pending->identity), $identity)) {
                return new \WP_Error('discussionbridge_cms_retry_refusal_changed', 'Current native reconciliation is required.');
            }
            // The canonical identity contains the private request. Only its
            // digest crosses the form; the exact retained tree stays protected.
            $authorized = CmsSourcePublisher::authorize_retry($id, $state->pending->identity, $actor, $guard);
            if ($authorized !== true) return $authorized;
            if (!$guard()) return new \WP_Error('discussionbridge_cms_retry_authority_changed', 'Current native reconciliation is required.');
            Publisher::queue($id, true);
            return true;
        });
        if ($result !== true) wp_die('Current native reconciliation is required.');
        wp_safe_redirect(admin_url('options-general.php?page=discussionbridge')); exit;
    }

    public static function retry_synchronization(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !current_user_can('manage_options')) wp_die('Native administrator permission is required.');
        check_admin_referer('discussionbridge_retry_synchronization');
        $result = FromSynchronizer::retry_synchronization();
        if (is_wp_error($result)) wp_die('Local synchronization could not be queued. Check the current connection and scheduler status.');
        wp_safe_redirect(admin_url('options-general.php?page=discussionbridge')); exit;
    }
}
