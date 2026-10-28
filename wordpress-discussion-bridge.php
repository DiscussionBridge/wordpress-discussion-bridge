<?php
/**
 * Plugin Name: DiscussionBridge for WordPress
 * Description: Connects published native WordPress content to a DiscussionBridge Discourse forum.
 * Version: 0.4.0-alpha.1
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: DiscussionBridge
 * License: MIT
 * Text Domain: discussionbridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) exit;
define('DISCUSSIONBRIDGE_WORDPRESS_VERSION', '0.4.0-alpha.1');
define('DISCUSSIONBRIDGE_WORDPRESS_FILE', __FILE__);
spl_autoload_register(static function (string $class): void {
    $prefix = 'DiscussionBridge\\WordPress\\';
    if (!str_starts_with($class, $prefix)) return;
    $name = substr($class, strlen($prefix));
    if (str_contains($name, '\\')) return;
    $file = __DIR__ . '/src/' . $name . '.php';
    if (is_file($file)) require_once $file;
});
register_activation_hook(__FILE__, [DiscussionBridge\WordPress\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, static fn() => wp_clear_scheduled_hook(DiscussionBridge\WordPress\FromSynchronizer::HOOK));
DiscussionBridge\WordPress\Plugin::boot();
