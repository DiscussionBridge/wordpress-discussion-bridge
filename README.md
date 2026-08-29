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
- stores resource/topic/outcome/retry state in protected post metadata;
- exposes authorized operator status and retry controls;
- provides the native dynamic `DiscussionBridge / From Discourse` block and a
  compatibility `[discussionbridge_record resource_id="…"]` shortcode, both
  rendered server-side with a bounded cache.
- appends the mapped To Discourse discussion to the original published post
  through Discourse Core's full-app dynamic-height presentation; Core retains
  ownership of sessions, replies, composer, moderation, and interaction.

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
