# DiscussionBridge for WordPress

```sh
git clone https://github.com/DiscussionBridge/wordpress-discussion-bridge.git
```

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
- reports WordPress's current public post types, categories, and tags to its
  Content Connection, then runs the forum operator's approved category, tag,
  destination, and presentation rules as a resumable background publication;
- backfills eligible existing Discourse topics into native WordPress content,
  retains one stable WordPress identity per forum topic and connection, applies
  later source or mapping revisions without duplicates, and drafts a native
  publication when the forum revokes its eligibility;
- synchronizes authorized Publishing records into genuine native WordPress
  posts, retaining exact Discourse source revision, author, topic, resource and
  destination-URL provenance and updating only when that revision advances.
  Older presentation-only records are never treated as publication authority;
- provides the native dynamic `DiscussionBridge / From Discourse` block and a
  compatibility
  `[discussionbridge_record resource_id="…" comments="interactive"]`
  shortcode, both rendered server-side with a bounded cache. The block exposes
  the same discussion-mode choice in the editor;
- offers `none`, native plugin-free `simple`, standard plugin-free `full`, and DiscussionBridge
  `Interactive` presentation per published post, with a site default;
- renders Simple as sanitized native reply cards, five initially with a
  **Show more comments** disclosure, fetching at most 50 replies in bounded
  server-side batches before continuing on The Bridge;
- appends the exact mapped To Discourse discussion to the original published
  post. `Interactive` uses the shared 800px bounded viewport with internal
  scrolling; Discourse retains ownership of sessions, replies, composer,
  moderation, and interaction; and
- renders a From Discourse first post once in WordPress and, when
  `Interactive` is selected, presents that same topic's replies below it
  without duplicating the first post in the frame; and
- builds a credential-free **On this page** navigation from two or more `h2` or
  `h3` headings in either WordPress-authored or From Discourse content.
- enqueues a local rich-content renderer on imported native publications. It
  renders Discourse Mermaid blocks in strict security mode, renders cooked
  math and supported `[math]`, `$$...$$`, and inline `$...$` forms, and keeps
  `.md-table` wrappers usable on narrow screens without a CDN or receiver
  credential; code examples remain literal.

## Reproducible release package

Release ZIPs are built from committed Git blobs, not working-tree files. This
keeps package bytes independent of Windows or Unix line-ending conversion and
fixes ZIP entry order, timestamps, permissions, and storage method.

```bash
python3 scripts/build-release.py --treeish HEAD
python3 scripts/verify-release-reproducibility.py
```

The first command writes `dist/wordpress-discussion-bridge-<version>.zip` and
prints its SHA-256 identity. The second builds the same commit from checkouts
configured with `core.autocrlf=false` and `core.autocrlf=true`; repository
attributes intentionally normalize tracked text to LF. The check fails unless
both ZIPs are byte-identical. GitHub Actions runs the same proof alongside the
adapter tests; release candidates must use that generated artifact rather than
a ZIP assembled from a working directory.

Configure the Discourse HTTPS origin, `dbc_…` connection ID, optional advanced
category route, and published post types under **Settings → DiscussionBridge**.
Prefer these
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

For whole-forum publication into WordPress, first save the connection settings,
then select **Refresh platform setup and start or restart forum
synchronization**. The first run uploads only bounded, non-secret WordPress
structure: public post types, category and tag identities, publish-capable
authors, presentation modes, capabilities, and content limits. If the connection has no current destination
mapping, WordPress pauses at **awaiting_mapping**. Complete the mapping and
preview in the Discourse connection, then start synchronization again.

The destination mapping also chooses either the configured service author or
one adapter-reported WordPress author. It chooses whether WordPress supplies
the initial slug, the initial Discourse title supplies it, or the stable
Discourse topic ID supplies it. After publication, a mapping change that would
alter the native post type or URL stops with a migration-required attention
code instead of silently changing the Bridge identity.

WordPress processes one signed source-feed page at a time through WP-Cron. Each
topic is rechecked against the exact source revision and destination plan,
written first under a stable local identity, resolved to one connection-owned
Bridge Record, and acknowledged only after native publication succeeds. A
transport interruption reuses the pending draft on retry rather than creating a
second post. The status line exposes processed, created, updated, unchanged,
held, and failed counts plus bounded non-secret attention codes. Starting a new
run is safe and idempotent; it does not require topic-by-topic authorization.

That source-feed scan is the bounded initial backfill or an explicit operator
restart—not the steady-state polling strategy. After the initial backfill,
each five-minute poll atomically claims at most 20 changed or withdrawn topics
from the receiver's durable publication queue. The exact five-minute receiver
lease is included in the successful Bridge Record acknowledgement. A native
WordPress failure is reported against that lease; the receiver performs at
most three automatic attempts before placing the item in central operator
attention. WordPress never silently drops the item or starts another
forum-wide crawl to conceal a failed update.

The durable OBBBA demo must not rely on reader traffic to trigger WP-Cron. Its
operator runbook must install and verify a real scheduler that invokes
`wp-cron.php` (or `wp cron event run --due-now`) on a bounded interval. The
demo is not operationally accepted while the queue depends only on page loads.

The service author must be an existing WordPress user allowed to publish posts.
It owns locally materialized posts; the transported Discourse author, topic and
revision remain separately visible as source provenance. This does not imply
user or login synchronization.

For legacy individually authorized **From Discourse** publications, use
**Synchronize publications** under
**Settings → DiscussionBridge**. The completion notice reports created,
updated, already-current, and failed counts. If a failure occurs, the same
page displays up to five non-secret failure reason codes for that run. Record
those codes before refreshing; do not repeatedly synchronize an unexplained
failure. Existing healthy posts are not deleted by a failed run.

WP Discourse is a separate plugin. For the Alpha demonstration its automatic
publication and comments paths must remain disabled so one WordPress lifecycle
cannot create competing topics.
