# DiscussionBridge for WordPress

Install the immutable release ZIP through **Plugins → Add New → Upload Plugin**.
Check its version and SHA-256 against the corresponding GitHub release. Update
through the same native WordPress path after backing up files and the database.
Keep the previous ZIP for rollback; disable the plugin before restoring it.

For source builds, check out the exact GitHub commit and run
`pwsh -File scripts/build-release.ps1`. The ZIP contains one plugin directory,
runtime PHP, blocks, browser assets, their maintained source and locked build
inputs/scripts, author tests, documentation and a SHA-256 source-member manifest.
It excludes demo helpers, local state, dependencies and Git metadata. Its
fixed timestamps and sorted members produce identical bytes from identical
source bytes under the same PowerShell/.NET build runtime. The default output
is explicitly a candidate in `.artifacts/`; an edited checkout does not acquire
the identity of a previously released tag.

To rebuild the selected rich browser asset, install the exact `package-lock.json`
dependencies in a disposable build directory and run `npm run build` there with
the packaged source/scripts. `assets/browser-rich-content-provenance.json` binds
the exact build inputs, local font bytes, dependency notices and output digest.
This build step is for source authors; normal WordPress installation uses the
already packaged asset and requires no Node.js or dependency installation.

This native WordPress plugin connects one WordPress installation to one
independently scoped Content Connection in the generic DiscussionBridge
Discourse plugin.

The local **0.3.0-alpha.1 candidate** requires the coordinated main plugin and
core-publication contract of the same candidate family. It has no release tag or
deployment qualification. The original Alpha.20 boundary remains compatible for
legacy identity resolution; automatic source updates use the explicit publication
extension and its accepted-edition preconditions.

Current candidate behavior:

- queues delivery only for explicitly opted-in configured post types on native
  publication and later published source edits; imported From Discourse posts
  cannot enter that outbound lifecycle;
- skips drafts, autosaves, revisions, and public page loads;
- uses a generated stable site UUID plus WordPress post ID as external identity;
- performs bounded authenticated create-or-resolve delivery with no redirect or
  direct-Core fallback;
- preserves the complete native WordPress body, sending complete portable HTML
  when it fits or a valid HTML excerpt with canonical **Read More** when required
  by the 48KiB topic-body or 64KiB JSON envelope. Source digest/length always
  describe the complete portable body;
- reports the stable WordPress author identity, display name and local author
  archive to the receiving connection. The forum operator chooses fixed or
  mapped Discourse authorship in that connection's Authors tab;
- stores resource/topic/outcome/retry state in protected post metadata;
- exposes authorized operator status and retry controls;
- synchronizes authorized Publishing records into genuine native WordPress
  posts, retaining exact Discourse source revision, author, topic, resource and
  destination-URL provenance. Negotiated metadata and verified chunks retain
  complete source content, including ordinary/wiki grace edits. Source creation,
  source edits and synchronization times remain separate. New native posts use the configured site discussion
  default; source updates preserve an existing post's discussion choice.
  Older presentation-only records are never treated as publication authority;
- withdraws an exactly owned native post when authenticated source metadata
  explicitly reports withdrawal, and restores the same post on source restoration.
  Operator withdrawals, changed canonical URLs and local content edits require
  reconciliation. Missing records or failed requests never authorize deletion;
- provides the native dynamic `DiscussionBridge / From Discourse` block and a
  compatibility
  `[discussionbridge_record resource_id="…" comments="interactive"]`
  shortcode, both rendered server-side with a bounded cache. Fresh authenticated
  metadata is required before a cached source body is used. The block exposes
  the same discussion-mode choice in the editor;
- offers `none`, native plugin-free `simple`, standard plugin-free `full`, and DiscussionBridge
  `Interactive` presentation per published post, with a site default;
- renders Simple as sanitized native reply cards, five initially with a
  **Show more comments** disclosure, fetching at most 50 replies in bounded
  server-side batches before continuing on the configured forum;
- appends the exact mapped To Discourse discussion to the original published
  post. `Interactive` uses the shared 800px bounded viewport with internal
  scrolling; Discourse retains ownership of sessions, replies, composer,
  moderation, and interaction; and
- renders a From Discourse first post once in WordPress and, when
  `Interactive` is selected, presents that same topic's replies below it
  without duplicating the first post in the frame; and
- builds a credential-free **On this page** navigation from two or more `h2` or
  `h3` headings in either WordPress-authored or From Discourse content.

To updates keep the same resource and topic, require the last accepted source
revision, and stop for reconciliation if the forum post was edited independently.
Transport retries stop after three attempts per source edition; an operator may
explicitly retry. A source edit that arrives during acceptance remains queued
after its predecessor's receipt is saved. Native timestamps are retained when
available; absent native dates remain null instead of using synchronization time.

**Rich content is selectable.** New imports leave the JavaScript math/diagram
renderer off until an operator enables it in Settings or the individual post.
Existing imports without the new selection retain their prior renderer. Source
updates preserve local discussion and rendering choices. No theme, layout, core
file or default build is modified.

A native Latest/archive query can explicitly choose exact source-edit ordering
with `discussionbridge_source_modified => true` in its `WP_Query` arguments.
That selection uses source-edit timestamps for imports and native modified times
for ordinary posts. Ordinary WordPress queries keep their existing order.

Configure the Discourse HTTPS origin, `dbc_…` connection ID, lane, and
published post types under **Settings → DiscussionBridge**. Prefer these
protected server constants for credentials:

```php
define('DISCUSSIONBRIDGE_SERVER_URL', 'https://forum.example');
define('DISCUSSIONBRIDGE_CONNECTION_ID', 'dbc_000000000000000000000000');
define('DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE', '/run/secrets/discussionbridge-wordpress');
define('DISCUSSIONBRIDGE_SERVICE_AUTHOR', 'discussionbridge');
```

The secret file must be outside the webroot and readable only by the PHP-FPM
application identity. Its path and value are never stored in WordPress options.
Connection secrets must never be exposed to browsers, themes, public REST
responses, URLs, content, or logs.

For installations without server-file access, an administrator may paste the
one-time connection secret under **Settings → DiscussionBridge**. The plugin
encrypts it with that WordPress installation's authentication salts, stores the
ciphertext in a non-autoloaded option, and never redisplays the secret.
Server-defined constants and secret files always take precedence. Rotating the
WordPress authentication salts invalidates an admin-stored credential; paste a
fresh connection secret afterward.

WordPress schedules To Discourse delivery in the background. A post may remain
**Queued** or **Delivering** briefly while WordPress Cron and the network finish
the request. Refresh the DiscussionBridge settings page after a short wait. Do
not select **Retry** while either status is shown; retry only after the delivery
reports **Attention** or **Failed**.

The service author must be an existing WordPress user allowed to publish posts.
It owns locally materialized posts; the transported Discourse author, topic and
revision remain separately visible as source provenance. This does not imply
user or login synchronization.

WP Discourse is a separate plugin. When both plugins are installed, disable
WP Discourse automatic publication and comments for DiscussionBridge-managed
posts so one WordPress lifecycle cannot create competing topics.
