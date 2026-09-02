<?php
/**
 * DiscussionBridge WordPress demo social footer.
 *
 * This is demo-site presentation, not part of the distributable adapter.
 */

add_action('wp_enqueue_scripts', static function (): void {
    wp_register_style('discussionbridge-demo-socials', false, [], null);
    wp_enqueue_style('discussionbridge-demo-socials');
    wp_add_inline_style('discussionbridge-demo-socials', <<<'CSS'
.discussionbridge-demo-socials{width:min(100% - 2rem,72rem);margin:2rem auto 0;padding:1.25rem 0 2rem;border-top:1px solid #d8dedb}.discussionbridge-demo-socials__links{display:flex;flex-wrap:wrap;align-items:center;justify-content:center;gap:.55rem}.discussionbridge-demo-socials a{display:grid;width:40px;height:40px;place-items:center;border:1px solid currentColor;border-radius:50%;color:currentColor;opacity:.78;transition:opacity .16s ease,transform .16s ease,box-shadow .16s ease}.discussionbridge-demo-socials a:hover{opacity:1;transform:translateY(-2px);box-shadow:0 7px 18px rgb(0 0 0 / 14%)}.discussionbridge-demo-socials svg{width:19px;height:19px;fill:currentColor}
CSS);
});

add_action('wp_footer', static function (): void {
    $sprite = WPMU_PLUGIN_URL . '/discussionbridge-social-icons.svg';
    $links = [
        ['forum', 'DiscussionBridge forum', 'https://forum.discussionbridge.dev/'],
        ['github', 'DiscussionBridge on GitHub', 'https://github.com/DiscussionBridge'],
        ['bluesky', 'DiscussionBridge on Bluesky', 'https://bsky.app/profile/discussionbridge.bsky.social'],
        ['discord', 'DiscussionBridge on Discord', 'https://discord.gg/Y7SRQAxKq'],
        ['mastodon', 'DiscussionBridge on Mastodon', 'https://mastodon.social/@DiscussionBridge'],
        ['reddit', 'DiscussionBridge on Reddit', 'https://www.reddit.com/r/DiscussionBridge/'],
        ['x', 'DiscussionBridge on X', 'https://x.com/DiscussBridge'],
        ['youtube', 'DiscussionBridge on YouTube', 'https://www.youtube.com/@DiscussionBridge'],
    ];
    echo '<aside class="discussionbridge-demo-socials"><nav class="discussionbridge-demo-socials__links" aria-label="DiscussionBridge community and social links">';
    foreach ($links as [$icon, $label, $href]) {
        printf(
            '<a href="%1$s" aria-label="%2$s" title="%2$s"><svg viewBox="0 0 24 24" aria-hidden="true"><use href="%3$s#%4$s"></use></svg></a>',
            esc_url($href),
            esc_attr($label),
            esc_url($sprite),
            esc_attr($icon)
        );
    }
    echo '</nav></aside>';
}, 20);
