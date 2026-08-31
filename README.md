# DiscussionBridge for WordPress

This native WordPress plugin connects one WordPress installation to one
independently scoped Content Connection in the generic DiscussionBridge
Discourse plugin.

Current Alpha slice:

- queues delivery only for explicitly opted-in configured post types on their
  first authoritative transition to published;
- skips drafts, autosaves, revisions, and public page loads;
- uses a generated stable site UUID plus WordPress post ID as external identity;
- performs bounded authenticated create-or-resolve delivery with no redirect or
  direct-Core fallback;
- sends the bounded published WordPress body so the Discourse topic contains
  meaningful source content plus canonical attribution;
- reports the stable WordPress author identity, display name and local author
  archive to the receiving connection. The forum operator chooses fixed or
  mapped Discourse authorship in that connection's Authors tab;
- stores resource/topic/outcome/retry state in protected post metadata;
- exposes authorized operator status and retry controls;
- provides the native dynamic `DiscussionBridge / From Discourse` block and a
  compatibility
  `[discussionbridge_record resource_id="…" comments="fullInteractive"]`
  shortcode, both rendered server-side with a bounded cache. The block exposes
  the same discussion-mode choice in the editor;
- offers `none`, standard plugin-free `full`, and DiscussionBridge
  `fullInteractive` presentation per published post, with a site default;
- appends the exact mapped To Discourse discussion to the original published
  post. `fullInteractive` uses the shared 800px bounded viewport with internal
  scrolling; Discourse retains ownership of sessions, replies, composer,
  moderation, and interaction; and
- renders a From Discourse first post once in WordPress and, when
  `fullInteractive` is selected, presents that same topic's replies below it
  without duplicating the first post in the frame; and
- builds a credential-free **On this page** navigation from two or more `h2` or
  `h3` headings in either WordPress-authored or From Discourse content.

Configure the Discourse HTTPS origin, `dbc_…` connection ID, lane, and
published post types under **Settings → DiscussionBridge**. Prefer these
protected server constants for credentials:

```php
define('DISCUSSIONBRIDGE_SERVER_URL', 'https://forum.example');
define('DISCUSSIONBRIDGE_CONNECTION_ID', 'dbc_000000000000000000000000');
define('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE', '/run/secrets/discussionbridge-wordpress');
```

The secret file must be outside the webroot and readable only by the PHP-FPM
application identity. The secret is never stored in WordPress options and must
never be exposed to browsers, themes, public REST responses, URLs, content, or
logs.

WP Discourse is a separate plugin. For the Alpha demonstration its automatic
publication and comments paths must remain disabled so one WordPress lifecycle
cannot create competing topics.
