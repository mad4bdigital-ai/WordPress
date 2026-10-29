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


## Permanent per-attachment review index (v1.2)
- Each scheduled quick batch saves a bounded-by-site indexed finding to the non-autoload option `mad4b_media_reference_audit_findings_v1`, preserving `attachment_id`, `parent_state`, classification, reference evidence, scan depth, and timestamp. No media/file or core attachment mutation is performed.
- Native ability accepts `action=review` with `after_id`, `limit` (maximum 60) and `classification` (`all`, `referenced`, `unresolved_review_only`, `test_only`).
- Findings are rebuilt when a fresh full-cycle scan restarts; a partial in-progress cycle is not a full-site verdict.
- `unresolved_review_only` is always protected against deletion. Deep review requires `action=scan`, `deep=true` and is strictly capped at 3 candidates per request.
- On ETG Commerce Staging, verified `tests/acceptance.php` through `wp eval-file -` with PASS: ability registered, anonymous access denied, schedule present, indexed review readable, no media/attachment count changes.
- Review index writes are audit metadata; the read-only ability itself never changes the database. Only scheduled jobs persist analysis checkpoint and findings.
- This is installed only on ETG Commerce Staging (`tours.dev.mad4b.com`). The separately connected `staging.egypttourgates.com` Control Plane has not received this change, so external MCP discovery there does not prove this ability.

## Source governance
- Source branch and review: PR #616. The Staging MU file is an exact copy of this branch's source file, not a canonical Production installer yet.
- Do not merge or spread to companies without normal source CI, owner attestation, rollout and authority review.
- Offsite backup is postponed by owner; same-VPS snapshot risk remains.

## Full-inventory triage and reversible administrative quarantine (2026-10-29)

The routine WordPress Ability is intentionally bounded; it cannot establish that images are unused. Run the standalone **read-only** full-inventory tools against a reviewed local Staging DB and protected local media origin:

- `full_reference_inventory.py`: streams all 6,449 no-parent / dangling-parent image attachments and local reference surfaces (post metadata, posts, options, user metadata, term metadata); produces a private JSONL and summary. No WordPress mutations.
- `review_secondary_sources.py`: checks unresolved images against WPML strings/translations, Content Hub objects and generated theme/Elementor/CSS files. Still cannot certify remote/external usage.
- `stage_admin_quarantine.py`: snapshots unresolved candidates in a root-only private **review-hold registry** with SHA-256 on existing source files; it NEVER moves or deletes media or publishes catalog permissions.
- `review_hold_control.py`: provides private registry status and explicit reasoned release of an administrative hold only; records never authorize deletion, physical quarantine or publication.

Pilot results on ETG Commerce Staging: 6,449 candidates. Broad local references found for 6,398. Secondary audit found additional references for 2 of 51 initially unresolved. Final review queue: **49 records** (30 source files present, 19 source files missing). These files include branding/payment/UI assets, so physical removal is not certified. No assets moved or deleted. Separately, 490 attachment records from the total candidate population point to files absent in both current Staging media and the verified local backup; treat those as a broken-reference repair task, not a deletion list.

Private registry on VPS: `/srv/mad4b-media-control/quarantine-review/ETG-REVIEW-HOLD-20261029.json` (mode 0600). Rollback control tested on a private copy of this registry, without touching source media. Its active hold paths are checked at generation time by the internal Media Gateway source in PR #614; held files cannot be added to the new preview allowlist. This **does not** disable existing WordPress/media URLs and is not a global public-media ACL. Existing live media bytes stay intact.

The authoritative scope remains Staging-only. No Production/DNS changes, no file deletion, and no offsite-backup certification (deferred by owner). Exact CI/owner authority still required before source merge or deployment elsewhere.


## Verified Staging repair of missing image sources (2026-10-29)

The initial Staging attachment census found **490** image attachment records whose `_wp_attached_file` target did not exist on the source VPS. That does **not** prove they were unused. This repair addresses only verifiable file-byte recovery, not content deletion or automatic public-media approval.

The in-repository `restore_verified_alternates.py` compares missing attachment paths to image files already present **in the same ETG Staging backup**, and requires exact basename, width, height, MIME and recorded original filesize. Candidate bytes are verified by SHA-256. The original source and both target paths are checked before copying. Target creation uses O_EXCL (no overwrites). Append-only receipts allow exact-hash rollback of newly created files. The non-scaled `original_image` file for one image was recovered separately from the same-source backup using its verified paired scaled source and WordPress metadata evidence.

Staging result:
- **17** attachment records restored via **13** unique image paths; one required additional original unscaled source file.
- Both WordPress Staging uploads and the private, independent same-VPS Media Origin received verified bytes. No company-hosting files were copied.
- WP-CLI `media regenerate --skip-delete` was run only on those restored attachment IDs. Duplicate records for the same underlying image were reconciled by the staging-only `reconcile_duplicate_image_metadata.php`, preserving the existing original file/size and other metadata. **4** duplicate image size metadata entries updated with private preimage + SHA-gated reconciliation/rollback plan.
- `sync_selected_derivatives.py` copied **85** unique newly regenerated thumbnails, using no-overwrite and SHA-verification, into the VPS Media Origin. Its rollback was tested on a disposable copy (not on the real origin).
- All **17** restored attachments have existing primary images, and **106/106** size references recorded in their current WordPress metadata resolve to files. Original and thumbnail URLs sampled over Staging HTTPS returned HTTP 200.
- Remaining missing attachment records: **473**. Many files have no verified, same-company bytes; do not infer their content from a same-named but dimension/byte-mismatched file, nor mix other companies' media.
- All **49** preexisting administrative review holds remain intact. No image was deleted or overwritten; Production and DNS unchanged.
- The persistent native read Ability now includes `source_file_state=present|missing` for every scanned orphan candidate; it does not grant deletion authority.

**Evidence:** `evidence/VERIFIED-RESTORE-17-OF-490-20261029.json`; complete private manifests and SHA-256 receipts are on the VPS in `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/media-reference-audit-20261029/full-scan-20261029/`. These are Staging-only repair operations; offsite backup remains deferred at the owner's direction. PR review and exact owner authority remain required before any Production rollout.
