<?php

declare(strict_types=1);

use DiscussionBridge\WordPress\Admin;
use DiscussionBridge\WordPress\Settings;

// Model the native options-page order: options-head.php renders the retained
// Settings API notice list before WordPress invokes the registered callback.
// This fixture does not claim a full WordPress administrator bootstrap.
function add_options_page(string $title, string $menu_title, string $capability, string $slug, callable $callback): string
{
    $GLOBALS['dbt']['settings_page'] = [$title, $menu_title, $capability, $slug, $callback];
    return 'settings_page_' . $slug;
}

function settings_errors(): void
{
    $GLOBALS['dbt']['settings_notice_renders'] = ($GLOBALS['dbt']['settings_notice_renders'] ?? 0) + 1;
    foreach ($GLOBALS['dbt']['settings_errors'] as [$setting, $code, $message]) {
        echo '<div data-settings-notice="' . esc_attr($code) . '"><p>' . esc_html($message) . '</p></div>';
    }
}

function settings_fields(string $group): void
{
    echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">';
}

function submit_button(): void { echo '<button type="submit">Save Changes</button>'; }

function dbt_render_settings_page(): string
{
    Admin::register_menu();
    $page = $GLOBALS['dbt']['settings_page'];
    expect($page[2] === 'manage_options' && $page[3] === 'discussionbridge');
    ob_start();
    try {
        settings_errors(); // Native options-head.php, before the plugin callback.
        $page[4]();
        return ob_get_contents();
    } finally { ob_end_clean(); }
}

test('native settings header renders one saved notice and the adapter retains its normal settings form', function (): void {
    configured();
    add_settings_error('general', 'settings_updated', 'Settings saved.');
    $notices = $GLOBALS['dbt']['settings_errors'];
    $html = dbt_render_settings_page();
    expect(substr_count($html, 'data-settings-notice="settings_updated"') === 1, 'Saved notice rendered more than once.');
    expect($GLOBALS['dbt']['settings_notice_renders'] === 1, 'Adapter rendered the native notice list again.');
    expect($GLOBALS['dbt']['settings_errors'] === $notices, 'Adapter cleared or changed native notices.');
    expect(str_contains($html, '<form method="post" action="options.php">'));
    expect(str_contains($html, 'name="option_page" value="discussionbridge"') && str_contains($html, 'Save Changes'));
});

test('native validation errors remain visible once without clearing or concealing another setting error', function (): void {
    configured();
    $previous = Settings::server_url();
    expect(Settings::sanitize_server_url('http://invalid.example/path') === '');
    add_settings_error('another_setting', 'another_error', 'Other validation <must remain visible>.');
    $notices = $GLOBALS['dbt']['settings_errors'];
    $html = dbt_render_settings_page();
    expect(substr_count($html, 'data-settings-notice="invalid_server_url"') === 1);
    expect(substr_count($html, 'data-settings-notice="another_error"') === 1);
    expect(str_contains($html, 'Other validation &lt;must remain visible&gt;.'));
    expect($GLOBALS['dbt']['settings_notice_renders'] === 1 && $GLOBALS['dbt']['settings_errors'] === $notices);
    expect(Settings::server_url() === $previous);
});

test('ordinary settings view does not invent a saved notice or alter stored settings', function (): void {
    configured();
    $options = $GLOBALS['dbt']['options'];
    $html = dbt_render_settings_page();
    expect(!str_contains($html, 'data-settings-notice=') && !str_contains($html, 'Settings saved.'));
    expect($GLOBALS['dbt']['settings_notice_renders'] === 1 && $GLOBALS['dbt']['options'] === $options);
});

test('settings callback still requires the native administrator capability', function (): void {
    configured();
    $GLOBALS['dbt']['capabilities']['manage_options'] = false;
    ob_start();
    try { Admin::render(); $html = ob_get_contents(); }
    finally { ob_end_clean(); }
    expect($html === '' && !isset($GLOBALS['dbt']['settings_notice_renders']));
});
