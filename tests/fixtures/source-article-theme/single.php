<?php
/** Native API MODEL theme only. This file is not an installed WordPress/theme qualification. */
$source_post = get_post(get_queried_object_id());
$source_author = get_user_by('id', $source_post->post_author);
$source_body = \DiscussionBridge\WordPress\CmsSourceArticleProfile::content(\DiscussionBridge\WordPress\Presentation::append_mapped_discussion($source_post->post_content));
$source_html = '<article data-discussionbridge-source-article="wordpress-source-article-1"><h1 data-discussionbridge-source-title>'
    . esc_html($source_post->post_title) . '</h1><span data-discussionbridge-source-author-id="wordpress-user:'
    . \DiscussionBridge\WordPress\Settings::site_id() . ':' . $source_author->ID . '">' . esc_html($source_author->display_name)
    . '</span><div data-discussionbridge-source-body>' . $source_body . '</div></article>';
$GLOBALS['dbt']['source_theme_renders'] = ($GLOBALS['dbt']['source_theme_renders'] ?? 0) + 1;
echo \DiscussionBridge\WordPress\CmsSourceArticleProfile::article($source_html);
