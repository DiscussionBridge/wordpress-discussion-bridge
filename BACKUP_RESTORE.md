# Protected native WordPress backup and restore procedure

This is the written procedure for work-list1097–1100 on the new V4 baseline. **Execution status: NOT RUN.** No installed site, database, backup destination or restore target has been selected in this task. A product ZIP, Git checkout and isolated PHP rollback fixture are different evidence from a native site backup or installed restore drill.

## Select the actual target and recovery set

Resolve every placeholder before execution. Keep this target record and backup set in protected operator storage, outside the webroot and outside the product/release repository. Do not put secret values in this document, command arguments, terminal transcripts, logs or a source manifest.

| Placeholder/input | Required actual value |
|---|---|
| `<WP_CLI>` | Installed WP-CLI executable/launcher and version; native PHP/MySQL client availability |
| `<WP_ROOT>` | Resolved native WordPress root for this site, not the development repository |
| `<PLUGIN_ROOT>` | Actual installed `wordpress-discussion-bridge` directory and verified normal artifact |
| `<CONFIG_PARENT>` / `<CONFIG_FILENAME>` | Actual wp-config path; account for a config above the webroot and included config files |
| `<SET_ROOT>` | New empty protected backup-set directory, outside all copied roots; ACLs/access and storage encryption selected |
| external content/config roots | Actual custom wp-content/uploads, linked targets, MU plugins/drop-ins, service config and key/secret providers |
| secret provider | Existing constant, protected file or encrypted option; record only the provider/path, never its value |
| database scope | Exact host/database/prefix, site/network ownership, table list, engines/charset/collation and permitted backup scope |
| `<RESTORE_ROOT>` | New empty isolated restore directory; same original site identity/origin retained while traffic is blocked |
| restore database | Explicit existing empty isolated database and secure connection provisioning; never an implicitly selected live/shared database |
| cache/process control | Actual web/PHP/CLI/Cron/queue services and site-specific persistent-cache/OPcache control |
| recovery point | UTC point/interval, artifact hashes and associated receiver/native state evidence; identify any remote state that advanced afterward |

The command examples below use PowerShell, WP-CLI and Windows Robocopy. They are **templates, not commands executed by this task**. For a POSIX/container installation, select its ACL/ownership/link-aware snapshot tool and equivalent paths with the site operator; the object list, consistency and reconciliation requirements are unchanged. Do not silently apply Windows copy semantics to another filesystem.

The Windows actor must already have the rights needed to preserve the selected ACLs/owner/audit data. Resolve absolute source/destination paths and confirm the new backup/restore directories stay within the expressly selected target roots. Reject unresolved placeholders, a drive root, a backup destination inside a copied tree, an existing nonempty restore root, or an unaccounted link target. Select encryption and access controls before writing any SQL/config copy. Preserve external key-store/EFS recovery capability where the actual installation uses it; an unreadable encrypted copy is not a usable backup.

## Preserve this component's actual state

Use a matched **whole native files + whole selected WordPress database** recovery set. The component currently adds no independent native SQL tables; it uses WordPress tables and exact private SDK files. Do not back up only visible posts or a hand-selected subset of DiscussionBridge options.

| Location | Required state |
|---|---|
| native root/config/content | WordPress files, exact plugin files/assets, active theme/block templates, MU plugins/drop-ins, uploads and actual external roots, routing/service configuration |
| wp-config/includes/key provider | Existing authentication and secure-auth salt/key inputs used by `wp_salt`; unchanged on restore |
| server configuration | Current `DISCUSSIONBRIDGE_SERVER_URL`, `DISCUSSIONBRIDGE_CONNECTION_ID`, `DISCUSSIONBRIDGE_CONNECTION_SECRET` or `DISCUSSIONBRIDGE_CONNECTION_SECRET_FILE`, and `DISCUSSIONBRIDGE_SERVICE_AUTHOR` overrides where configured; preserve through the protected provider |
| actual `$wpdb->options` | All site/config options including site UUID, origin/connection/lane/post types/service author/modes, encrypted connection secret, native `cron`, `discussionbridge_from_sync_state`, attention and transient state |
| `discussionbridge_native_<hash>` option rows | Every sealed `gcm1:` journal, including per-source To ledger, confirmed deleted-source withdrawal, exact pending02/05/28/29/30 trees, rejected outcomes, accepted/full prepared/native source history, URL ancestry/operation/before+after receipts/expected native article+template/public proof/reservations, From cursor/complete-source staging/fence/work/lease state, catalog replay and To recovery index |
| actual `$wpdb->posts` / `$wpdb->postmeta` | IDs, types, slugs, status, content, actual dates, source opt-in/modes, resource/topic/URL bindings, complete hashes/counts, `_discussionbridge_cms_state`, distinct signed `_discussionbridge_to_source_receipt`, managed revisions and `_discussionbridge_owner_exclusion` signed native facts |
| users/usermeta and taxonomy tables | Native author IDs/capabilities and terms/term_taxonomy/term_relationship IDs/assignments used by policy/catalog/ownership |
| normal plugin artifact | Exact selected ZIP, its SHA-256, `SOURCE_MANIFEST.json`, release descriptor and `src/private/cms-v4/PROVENANCE.json`; current development version is0.4.0-alpha.1/wireV4/release null |

`ProtectedJournal` and the encrypted settings secret derive their key from the existing auth + secure-auth `wp_salt` inputs. Journal authenticated data also binds the exact option name. The To index context includes native home URL/site UUID; source journals bind home URL/server/connection/native ID. Restoring encrypted options with different salts, a regenerated site UUID, renamed option keys, changed site origin or substituted connection is not a valid recovery. Preserve the native configuration/key-provider and database pair; do not rotate keys, regenerate identities or edit ciphertext to make a restore appear healthy.

The exact SDK pins are codec `717513f474719538be0d40ad8cc4aea274051c17cf522274898ee29abf64a481` and schema `7dee282601e4195459f38b2f2451a5f0f03af39a5f60affc65c49b34664e3f7a`. These identify current r5 development bytes, not an approved release or a migration/restore qualification. A matched historical backup retains its own recorded pins and artifact; do not retag its journals or substitute this current pair.

## 1097 — protected files backup

1. Record the resolved target, protected set ID, source artifact/file hashes, exact native table scope, original home/site URL, current process/cache arrangement and external roots. Locate the actual config without printing its contents:

   ```powershell
   & "<WP_CLI>" --path="<WP_ROOT>" config path
   ```

2. Quiesce actual native writers using the selected host's service controls: web/PHP requests, editor/REST/CLI jobs, native Cron and external scheduled invocations, bridge workers and catalog/admin writes. Drain or stop already running processes and record uncertain writes; an HTTP maintenance page or deactivation alone does not establish this. Keep the same configuration/identity and current pending evidence. Do not toggle policy, delete Cron/journals or rotate a secret to simulate quiescence. Keep the files/database backup in this same controlled interval.
3. Copy the native tree into the new protected set with metadata preservation. Account separately for all excluded junction targets and external roots. This example preserves symbolic links rather than traversing them and excludes junction traversal; an unbacked linked upload/config tree makes the set incomplete.

   ```powershell
   robocopy "<WP_ROOT>" "<SET_ROOT>\site-files" /E /COPYALL /DCOPY:DAT /SL /XJ /R:1 /W:1 /LOG:"<SET_ROOT>\files-backup.log"
   if ($LASTEXITCODE -ge 8) { throw 'Native files backup failed; retain the partial set as incomplete.' }
   robocopy "<CONFIG_PARENT>" "<SET_ROOT>\private\config" "<CONFIG_FILENAME>" /COPYALL /R:1 /W:1 /LOG:"<SET_ROOT>\config-backup.log"
   if ($LASTEXITCODE -ge 8) { throw 'Protected config backup failed.' }
   ```

4. Preserve each actual external secret file/config include/key provider through the selected protected mechanism, with its original permissions/ownership and restore location recorded privately. A secret file must remain outside the native webroot. Do not resolve a file provider by printing the value or adding it to the plugin ZIP. Preserve the original salt/key provider even when the connection credential comes from a separate file or constant.
5. Build a protected manifest of relative paths, byte sizes, SHA-256, ACL/owner/link metadata and external-root coverage. Compare actual copies with source while writers remain quiesced. Robocopy exit codes below8 alone do not prove every required external object, permission or byte matched. Retain the normal product ZIP separately from the site snapshot, and preserve the backup set off the active site's failure boundary.

Robocopy copy/link/return-code semantics are documented by [Microsoft](https://learn.microsoft.com/en-us/windows-server/administration/windows-commands/robocopy). Use the manifest and explicit object coverage as the completion record for this step.

## 1098 — protected database backup

1. Confirm the target database/table scope privately against the actual config, including multisite/network/global user tables where applicable. The following template exports the entire configured database. Use it only where that complete database is in the selected backup scope. For a shared database or mixed/nontransactional engines, the DBA must select the coherent full snapshot/scope before execution; do not silently substitute a prefix-only or posts/options-only dump.
2. Keep all relevant writers and DDL quiesced. Record engines and server/client versions. `--single-transaction` does not make unrelated nontransactional tables or filesystem/plugin side effects transactional. Native From writes themselves require the actual InnoDB tables/physical connection checks; this procedure does not change storage engines.
3. Export directly into protected storage with native config credentials; do not use STDOUT, a public directory or command-line password overrides:

   ```powershell
   & "<WP_CLI>" --path="<WP_ROOT>" db export "<SET_ROOT>\database.sql" --add-drop-table --single-transaction
   if ($LASTEXITCODE -ne 0) { throw 'Native database export failed; the recovery set is incomplete.' }
   Get-FileHash -LiteralPath "<SET_ROOT>\database.sql" -Algorithm SHA256
   ```

4. Record dump size/hash, exit status, selected tables/schema/engines, charset/collation and the controlled snapshot interval alongside the files manifest. Verify expected native table coverage and the private journal/index/config rows without exposing values. Confirm the preserved key provider can decrypt the copied native state during the isolated restore drill; a dump-file hash alone cannot prove decryptability or successful restore.

[WP-CLI db export](https://developer.wordpress.org/cli/commands/db/export/) uses the selected wp-config database credentials and exports all tables unless a table filter is supplied. The [WordPress backup guidance](https://developer.wordpress.org/advanced-administration/security/backup/) treats files and database as separate required parts of a site recovery set.

## 1099 — protected files restore/rollback

1. Keep live native writers stopped and restore traffic/transport isolated. First preserve the current failing/pre-restore files, database, pending receipts and external state in a separate protected rescue set. Never overwrite the only recovery copy.
2. Select one matched backup set and exact normal artifact. Check its stored hashes and metadata before use. Restore into the expressly selected new empty directory rather than overlaying mixed generations of plugin/theme files. For the Windows copy layout above:

   ```powershell
   robocopy "<SET_ROOT>\site-files" "<RESTORE_ROOT>" /E /COPYALL /DCOPY:DAT /SL /XJ /R:1 /W:1 /LOG:"<SET_ROOT>\files-restore.log"
   if ($LASTEXITCODE -ge 8) { throw 'Native files restore failed; keep the target isolated.' }
   ```

3. Restore the actual external config/includes/content roots and protected secret/key providers to their recorded locations with original ACL/ownership/link semantics. Preserve the original salts, site UUID, origin, native service-user identities and connection settings. Do not generate a new site/config as a shortcut. A standalone ZIP reinstall does not restore native posts, sealed journals or native database history.
4. Compare the restored file manifest and native permissions with the chosen backup; verify the installed plugin/source manifest and SDK pins. Select a site-specific fresh/invalidated persistent cache and PHP OPcache state so pre-restore cached posts/options/terms cannot masquerade as restored rows. Do not flush an unrelated shared cache indiscriminately.
5. Point the isolated target's existing native config at the explicitly selected empty restore database using its protected credential mechanism while retaining all cryptographic/site/protocol identity inputs. Keep its external requests blocked. A restored test copy with the original origin/credentials must never silently act as a second writer against the live receiver.

## 1100 — protected database restore/rollback

1. Verify the selected SQL hash and files/config pair again. Ensure the actual restore database already exists, is empty, and is the selected isolated target. Do not import into an inferred live/shared database. Current WP-CLI import runs the SQL in the file; it does not create the database. Preserve the complete dump, not a selective reconstruction of current posts or the visible record mapping.
2. Import through the selected native config, without putting credentials into command arguments:

   ```powershell
   & "<WP_CLI>" --path="<RESTORE_ROOT>" db import "<SET_ROOT>\database.sql"
   if ($LASTEXITCODE -ne 0) { throw 'Native database restore failed; keep the target isolated.' }
   ```

3. Compare the actual restored native table list/schema/engines/row coverage and IDs with the backup control record, including post/meta/options/user/taxonomy relations, Cron, complete sealed To/From/catalog journals and index. Check actual native IDs, resource/topic/URL bindings, revisions, source hashes/counts/dates, signed owner facts and accepted/pending history. Do not renumber posts/terms/users or rewrite receipt/fence/cursor/lease fields to force agreement. Database-generated connection IDs and named locks are not restorable authority; a future worker must acquire its new physical writer witness.
4. In an isolated native bootstrap, use the existing `ProtectedJournal::read_existing`, `CmsJournal::read` and `Settings::connection_secret` APIs to establish successful decryption/config availability without printing plaintext. The following diagnostic body is for an operator-supplied protected file loaded into the selected native WP runtime. It performs no Bridge HTTP, publication, scheduling or lifecycle recovery. Its WordPress bootstrap, MU plugins and core hooks still require the host isolation above; `--skip-plugins` does not skip MU plugins.

   ```php
   <?php
   $pluginRoot = '<PLUGIN_ROOT>';
   require_once $pluginRoot . '/src/ProtectedJournal.php';
   require_once $pluginRoot . '/src/Settings.php';
   require_once $pluginRoot . '/src/CmsArtifacts.php';
   \DiscussionBridge\WordPress\CmsArtifacts::codec();
   global $wpdb;
   $table = '`' . str_replace('`', '``', $wpdb->options) . '`';
   $names = $wpdb->get_col($wpdb->prepare('SELECT option_name FROM ' . $table . ' WHERE option_name LIKE %s', $wpdb->esc_like('discussionbridge_native_') . '%'));
   if (!is_array($names) || $wpdb->last_error !== '') throw new RuntimeException('Native state enumeration failed.');
   $checked = 0;
   foreach ($names as $name) {
       $state = \DiscussionBridge\WordPress\ProtectedJournal::read_existing($name);
       if (is_wp_error($state) || $state === null) throw new RuntimeException('Protected state decryption failed.');
       $checked++;
   }
   if (\DiscussionBridge\WordPress\Settings::connection_secret() === '') throw new RuntimeException('Protected connection configuration unavailable.');
   echo 'Protected state readable; journal count=' . $checked . PHP_EOL;
   ```

   The diagnostic is not a substitute for matching that count and the actual index/source/lease/history identities against the selected backup. Preserve existing WordPress salt inputs before bootstrap; missing/recreated key material is a restore failure, not permission to overwrite journals. Native bootstrap may have unrelated core/plugin effects, so keep the rescue copy and target isolation. This diagnostic has not been executed against an installed target.
5. Reconcile the restored local point with actual receiver state **before enabling bridge writers**. A remote record/mapping/work may have advanced after the snapshot. Use current scoped metadata/status and accepted local evidence: never treat Record04 metadata as acceptance of pending02B, never replay a closed-source B merely to find out whether it applied, never silently adopt an unknown binding or recreate an uncertain native create. Existing acceptedA/closure/04(A) separate05(A) semantics remain; mismatches retain reconciliation. Do not rewind remote history/fences or lower generations to match an older local snapshot. If the selected point cannot be reconciled with the current receiver using existing mechanisms, keep bridge writers isolated and preserve both points for the specific recovery decision.
6. Run the selected installed restore drill and record actual results: native editor/REST fencing, uncertain replay/no second create, confirmed-deletion requeue, block/shortcode/modes/comments/assets/native query pagination and anonymous canonical verification. Re-enable only the selected native services/Cron after the native and receiver state is reconciled. Retain original rescue/backup/artifact sets and the redacted execution record. New V4 upgrades/rollback need their actual state/schema compatibility; earlier development protocol conversion is not part of this procedure.

[WP-CLI db import](https://developer.wordpress.org/cli/commands/db/import/) documents the existing-database/SQL execution behavior. [WP-CLI config path](https://developer.wordpress.org/cli/commands/config/path/) identifies the actual config path without displaying its secrets.

## Current evidence and missing execution inputs

| Work-list item | Written procedure | Native execution evidence |
|---|---|---|
|1097 protected files backup|Present above|NOT RUN; selected native roots/tool/ACL/key-provider/backup store missing|
|1098 protected database backup|Present above|NOT RUN; selected database/table scope/engines/credentials provider/quiescence missing|
|1099 protected files rollback|Present above|NOT RUN; matched protected set/isolated restore root/service/cache controls missing|
|1100 protected database rollback|Present above|NOT RUN; selected empty restore database/native runtime/receiver reconciliation point missing|

No secret values, native snapshots, SQL dump, restored database or live target were created by authoring this procedure. Isolated source fixtures and package-member verification remain separately labeled evidence.
