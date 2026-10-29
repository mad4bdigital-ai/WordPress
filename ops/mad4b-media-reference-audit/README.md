# MAD4B Media Reference Audit — permanent read-only policy

## Purpose
Unparented image attachments are **not** automatically unused. This policy protects media referenced through featured images, metadata, Elementor, WPML, galleries, destinations, serialized content, site options and external consumers. It does not auto-delete or approve any image for public Media Gateway distribution.

## Installed contract
- WordPress native ability: `mad4b/media-reference-audit` (registered at `wp_abilities_api_init`).
- Category: `mad4b-read`, `show_in_rest=false`, `public=false`, MCP read surface.
- Read authority: `manage_options` or existing governed `MAD4B_SCP_Policy::can_read()`.
- UI: WordPress Tools → **Media Reference Audit**.
- WP-Cron: `mad4b_media_reference_audit_tick_v1` every 5 minutes as a fallback.
- VPS staging: generic systemd timer instance `mad4b-media-reference-audit@commerce-staging.timer`, every 5 minutes, `flock` serialized.
- No automatic removal, trash, disk quarantine, database modification, media catalog grants or sharing.
- Only checkpoint option `mad4b_media_reference_audit_checkpoint_v1` is modified by scheduled runs.
- Policy applies independently to each company site where the MU plugin is deliberately installed. It is not restricted by brand or environment name; it is **currently deployed only on ETG Commerce Staging**.
- No special Production or DNS permit follows from this policy.

## Scan categories
1. `referenced`: at least one *known* reference found. May include false positives; retains image.
2. `unresolved_review_only`: quick or deep scan found no recognizable reference. **NOT proof of unused**. No automatic action.
3. `test_only`: image/parent classified test-only; no automatic action.

For each candidate, the ability returns parent state, reference evidence, and `delete_allowed=false`. The default scan is quick, bounded to 60 records. Deep scan searches arbitrary post metadata, options and post content, explicitly invoked with `{"action":"scan","deep":true,"limit":3}` and capped at 3 records. Deep checks can still miss external links or custom references. Every automated tick uses quick mode to protect the database from broad repetitive LIKE queries.

## Systemd / deployment on approved VPS
Copy the reviewed MU file to the chosen company's WordPress MU plugin directory **only after source/release approval**. From this directory install `run.sh` to `/usr/local/libexec/mad4b-media-reference-audit/run.sh`; place the two systemd template units under `/etc/systemd/system/`. For a specific site instance:
```sh
install -d -m 0700 /etc/mad4b/media-reference-audit
printf 'WP_CONTAINER=%s\nWP_ROOT=/var/www/html\n' '<company-wordpress-container>' > /etc/mad4b/media-reference-audit/<instance>.env
chmod 0600 /etc/mad4b/media-reference-audit/<instance>.env
systemctl daemon-reload
systemctl enable --now 'mad4b-media-reference-audit@<instance>.timer'
systemctl start 'mad4b-media-reference-audit@<instance>.service'
```
Never assume a company Production WordPress installation is authorized by this example. Test service and database isolation first. Avoid direct file deletion.

## Operational acceptance on ETG Commerce Staging (2026-10-29)
- PHP 8.5 lint succeeded.
- Native ability registered; unauthorized WordPress user ID 0 denied.
- First deep-equivalent slow scan: ~10.7s / 5 items, exposing expensive broad searches.
- Optimized default quick scan: 0.271s / 5 items.
- First scheduled checkpoint: 60 candidates, 4 recognized references, 56 unresolved-review-only, no media mutation, attachment and metadata counts unchanged.
- systemd instance enabled, active and tested; first service invocation returned exit 0 and advanced checkpoint from 60 to 120 candidates.
- Backup receipt `/opt/mad4b-media-backups/etg-20261029T161708Z/BACKUP-RECEIPT.json` was verified before installation.
- Existing long-lived media and database remain unchanged; Media Origin pilot remains loopback only.

## Risks and review
WP-Cron alone depends on incoming traffic; systemd supplies a host-side cadence for Staging. Multiple concurrent invocations are serialized by host flock and a short WordPress transient, but there may still be concurrent WP-Cron executions; no destructive action is ever possible. For distributed/multi-host use, replace the transient with a durable DB lock.

Full unattended deletion is **not implemented**: approval requires a separate supervised workflow, confirmed zero references across company-specific adapters and remote assets, immutable snapshot, quarantine retention and tested rollback.
