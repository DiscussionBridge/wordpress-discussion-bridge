<?php
/**
 * Plugin Name: DiscussionBridge for WordPress
 * Description: Connects authoritatively published WordPress content to a DiscussionBridge Discourse forum.
 * Version: 0.1.0-alpha.15
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: DiscussionBridge
 * License: MIT
 * Text Domain: discussionbridge
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('DISCUSSIONBRIDGE_WORDPRESS_VERSION', '0.1.0-alpha.15');
define('DISCUSSIONBRIDGE_WORDPRESS_FILE', __FILE__);

require_once __DIR__ . '/src/Settings.php';
require_once __DIR__ . '/src/Client.php';
require_once __DIR__ . '/src/WpDiscourseGuard.php';
require_once __DIR__ . '/src/PublishedContent.php';
require_once __DIR__ . '/src/SourceAuthors.php';
require_once __DIR__ . '/src/TableOfContents.php';
require_once __DIR__ . '/src/Publisher.php';
require_once __DIR__ . '/src/Materializer.php';
require_once __DIR__ . '/src/Presentation.php';
require_once __DIR__ . '/src/Admin.php';
require_once __DIR__ . '/src/Plugin.php';

register_activation_hook(__FILE__, [DiscussionBridge\WordPress\Plugin::class, 'activate']);
DiscussionBridge\WordPress\Plugin::boot();
