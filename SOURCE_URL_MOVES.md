# Native To source URL moves

This component implements the existing V4-28/29/30/31 source URL lifecycle in the ordinary publisher, encrypted per-source journal and site writer. Package0.4.0-alpha.1 uses the exact r5 Contract pair. The executed fixtures model WordPress APIs/storage/theme rendering; one case transports that model page through a separate TLS loopback server with CA and hostname verification. An installed WordPress/MySQL/theme/browser target has not been qualified.

## Native owner entry and supported operation

The existing protected PHP entry is `CmsSourceMove::prepare($post_id, $new_url, $nonce)`. The current user must have `manage_options` and `edit_post`; the nonce action is `discussionbridge_source_move_<post_id>`. Invoke it through an already authorized native administrator integration or an explicitly selected WP-CLI user/bootstrap. No new endpoint, screen or privileged actor is installed. `abort($post_id, $nonce)` uses that same native owner boundary; `history($post_id, $cursor)` observes scoped V4-31 metadata.

The operation changes only `post_name` on the SAME published native post. Native `get_sample_permalink` and `wp_unique_post_slug` must realize the exact requested URL through the existing pretty/page/CPT layout. Query/ID-dependent routes, changed site/permalink configuration, hierarchy/date/category changes and collisions are rejected before reservation. The native API is `wp_update_post`; actual native modified dates are observed from storage. The implementation does not assign a date to manufacture a revision. WordPress documents [the sample-permalink placeholders and native filters](https://developer.wordpress.org/reference/functions/get_sample_permalink/) and [the existing update API](https://developer.wordpress.org/reference/functions/wp_update_post/).

Current enabled To connection/secret/config/direction/lane/origin/owned record/revision/native fingerprint checks apply. The separate From publication Policy is not a To source-move authority gate. A changed scope, native source, active pending publication, withdrawn source or conflicting binding retains reconciliation and exact pending state.

## Prospective ownership and exact private encoding

New current captures retain the original article and its captured SOURCE ARTICLE URL, ENTIRE prepared article/count/SHA, semantic title/authors/actual nullable dates and native row. The component does not reconstruct historical generated bodies from their prefixes/hashes or retrofit old history. A source without that complete current evidence cannot move through this mechanism.

`_discussionbridge_to_source_receipt` contains a component-private signed receipt. Its fields bind profile/site/native/resource/topic/external IDs, canonical URL/source revision/full hash/count and complete snapshot digest. Its HMAC uses the existing native auth salt. Full source, pending requests, expected render, native operation and history stay in the existing encrypted journal. Losing the postmeta receipt does not unenroll a positively known owned source from the native fence.

`wordpress-source-article-1` private digest encoding is exact `Codec::semanticIdentity` typed-tree bytes, then SHA256. This is separate from the shared From ownership-receipt grammar. A native move receipt binds the exact before ownership observation, full after row/term IDs, real URL-commit ancestry, new revision, issued move/nonce and complete prepared count/SHA. No nonce, source marker or copied source hash alone proves the public article.

## Actual normal theme integration

The first implemented profile supports an explicitly integrated classic PHP theme. Its actual selected template must reside under the current child/parent theme directory. The plugin observes the actual [template include path](https://developer.wordpress.org/reference/hooks/template_include/) and hashes that file. This does not select or rewrite a theme. Block/FSE or other template systems need their actual equivalent full-article integration before they can use this profile.

The selected template places these attributes on its EXISTING complete native article markup:

| Existing element | Profile attribute |
|---|---|
| Single complete article | `data-discussionbridge-source-article="wordpress-source-article-1"` |
| Actual full title element | `data-discussionbridge-source-title` |
| Each actual native source-author label | `data-discussionbridge-source-author-id="wordpress-user:<site_uuid>:<native_user_id>"` |
| Container of the complete ordinary `the_content()` output | `data-discussionbridge-source-body` |

The template captures that single existing article fragment in its NORMAL public render and passes it through `CmsSourceArticleProfile::article($existing_article_html)` before emitting it. Surrounding layout is not part of this argument. Existing title/authors/layout/content functions still run normally. The plugin does not construct replacement article markup. No synthetic call to arbitrary native callbacks is an expected-render probe.

The native context must be anonymous, singular, main-query, actual matching global post/in-loop, full-content (`$more=1`), non-preview and password-free. The existing normal Presentation append boundary delimits the complete source after native formatting/table-of-contents processing and before its separate discussion append; later native filters run normally. Existing filter priorities stay unchanged. The final actual content is observed. There must be exactly one genuine source boundary, title, ordered native author list and complete body. Missing/duplicate markers, excerpt output, wrong source identity, removed boundaries or stale displayed rows do not qualify. The [WordPress6.6 default filters](https://github.com/WordPress/wordpress-develop/blob/6.6/src/wp-includes/default-filters.php) establish the native formatting order this integration preserves.

The complete SOURCE body between private delimiters is retained as exact native rendered bytes. Title and author values are extracted separately. Its canonical private tree is `{title,authors:[{id,name}],body}`; SHA256 covers the exact typed-tree encoding, including every source-body byte. DOM reads boundary/identity roles; it does not convert or reserialize the rich article. Native Tag Processor annotations preserve original tokens. This keeps displayed code, data quoting and full source intact while the separately appended discussion remains outside the SOURCE body hash. Delimiters are extraction mechanics, not freshness/ownership proof.

Copied relative references use the captured article URL. A URL-only move leaves raw native content/prepared article/full hash/count/title/authors/IDs fixed. Qualified normal views after settlement keep those original targets for an unchanged raw article, without emitting a retired proof challenge. A genuinely edited raw article is captured through the ordinary new native source workflow. No scheme/provider/execution/default/fallback rule is selected by this profile.

## Durable phases and native exclusion

| Phase | Durable meaning |
|---|---|
| `prepare_pending` | Exact28 request/UUID/tree and current owned native plan exist before HTTP |
| `awaiting_actual_native_render` | Receiver reservation/issued nonce accepted; native URL unchanged; no worker render replay |
| `render_expected_captured` | Actual normal old-URL whole native render and template/source receipt captured |
| `native_commit_pending` | Operation intent durable before the native API; restart never blindly repeats it |
| `native_applied` | Same physical transactional connection committed native post plus after-receipt journal |
| `native_not_applied` | Failed native write has proven rollback; old ownership still required before abort |
| `native_commit_uncertain` | Commit/rollback not established; both reservations held |
| `finalize_pending` | Exact29 request/new revision/nonce/actual public proof durable before HTTP |
| `abort_pending` | Exact30 and proven unapplied old source retained before HTTP |
| settled history | Actual typed29/30 result and original request/native source/history retained |

The shared writer, original physical wpdb connection, InnoDB tables/savepoint, row/term locks and conditional native SQL fence are required. Final native data may change only the slug and actual modified fields. Existing editor/REST paths supply the protected persisted-revision token; stale or unversioned direct APIs fail before SQL on owned current sources. Unrelated posts keep their native paths. Native and journal cache entries are invalidated after rollback/retry; reconnect does not prove rollback. Direct SQL and unrelated plugin external side effects are not claimed transactional.

The worker's own native permalink save does not enqueue an ordinary02 update. Genuine later native authoring remains in the ordinary publisher. Real URL-commit ancestry participates in the existing opaque source revision so29 has a fresh unused revision even if native times are unchanged/null. After29, an unchanged ordinary02 keeps that accepted revision; a genuine later current snapshot uses its accepted predecessor. Old02 bodies/receipts remain immutable.

The known accepted discussion stays visible during a scoped queued/held source-only move, using its same signed owned resource/topic binding. Administrative delivery status does not remove that already accepted discussion or change its rendering mode. A withdrawn or scope-drifted source does not use this move-specific presentation binding.

## Independent public verification and recovery

After native commit, the worker fetches the exact configured new canonical URL anonymously with no cookies/credentials/redirects. It requires actual200 HTML/UTF8, one complete versioned native article, exact site/native/resource/external/current edition/issued move+nonce/template identity, and whole actual title/authors/source-body hash equal to the earlier native expectation. The native row/receipt/fence is checked again after the fetch. Source/full prepared SHA and actual native-public tree SHA stay distinct; `checked_at` is the actual successful observation.

Generated excerpts retain the exact owned article prefix and historical selection/full source hash/count. Only the owned Read More suffix and its URL change. Preflight accounts for HTML/JSON caps and the declared native date shape; a longer URL cannot force prefix truncation. Full representations retain their entire body. The receiver performs its own existing scoped/native/CAS checks.

Uncertain28/29/30 transport reuses the identical UUID/tree. Positive native journal discovery can restore a lost eligible schedule. An uncertain native commit never performs a second native operation. Only a proven not-applied old source can abort; applied/uncertain reservations remain held. Definitive `content_preservation_unavailable`29 is sealed with its exact rejected request/outcome and has no automatic retransmission or guessed replacement. History31 is metadata observation, not an accepted mutation receipt. Existing per-phase automatic attempt bounds remain; this adds no native administrator retry cycle or readiness probe.

An unsettled reservation does not add an independent native deletion lockout. Existing supported deletion capture/short writer exclusion remains: an actual completed delete retains its exact closure, source-move journal, accepted history and reservations. Its captured05 closure can follow existing current To authority; it does not authorize29,30,02 replay or native recreation. Missing current native ownership prevents unsafe move finalization or abort. A later human source edit also prevents the current unchanged-before-receipt abort proof; both reservations stay held when actual old ownership cannot be established. A replacement/changed-source recovery branch is not invented here. Direct SQL or other native hook bypass is not claimed captured.

## Inputs and qualification still required

Supply the actual installed WordPress/bootstrap/user, selected post/site/native IDs, current pretty layout and exact supported slug operation; transactional database/table/connection target; selected theme/child PHP template/full title/authors/content boundary; actual native filter/block/shortcode/widget/provider assets; page/CDN/object cache behavior; and public canonical TLS origin. A changed/variable native body or unsupported full-article boundary remains a concrete proof limitation. Linked programs/provider/browser functionality and physical concurrent authoring need their actual installed checks. Cache-control headers do not promise a site cache purge. No worker writes a guessed theme, weakens proof, invents a public field/error or performs an installed operation to fill those inputs.

The written backup/restore procedure must preserve the matched native row/meta/terms, source receipts, encrypted source/index/move journals, source ancestry, expected native render/template identity, exact pending/rejected requests and current SDK/artifact. Installed files/database restore is still NOT RUN.
