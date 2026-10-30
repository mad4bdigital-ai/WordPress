# MAD4B Content Hub — Source Import (default) + Auto Distribute

## New default

**Source mode = Import into Content Hub** (import_to_hub). Source published content is fetched over HTTPS using WordPress Application Passwords and stored as private records in the **central** Content Hub. No full WordPress database copy, no source-site mutations and no customer data.

The optional alternative **Use existing Content Hub references** (reference_only) distributes prior trusted, already-imported Hub Nile references without fetching a fresh source collection. It preserves the previous behavior.

**Enabled** remains off by default. Choosing a default mode is not automatic permission to connect to any site or publish content publicly.

## Admin workflow

1. On a source company's WordPress site, install read-only source adapters remote/240-mad4b-company-simple-identity.php and remote/260-mad4b-company-simple-content-feed.php after a separate site-specific Staging test; configure a dedicated native Application Password user ID in the non-autoloaded option mad4b_simple_service_user_id_v1.
2. On the central site, install central/240-mad4b-central-simple-connections.php, central/250-mad4b-hub-auto-distribute.php and central/260-mad4b-hub-source-import.php (the last registers private CPT mad4b_hub_content). Keep the existing 160 central company registry.
3. Open Content Hub > Connections. Connect the source site with its dedicated Application Password, then Test Connection. The central site encrypts the credentials at rest and never shows them in status.
4. In Content Hub > Auto Distribute, default mode is Import into Content Hub. Select published post types (Tours, Properties or Transportations), languages and optional receiving companies. The Tourism company is the initial source for this pilot.
5. Enable and use Run Distribution Now, or the 15-minute WP-Cron schedule. With no recipients selected this is an import-only job; with recipients selected it imports first, then distributes selected private reference previews.

## Import behavior

- Source endpoint: POST /wp-json/mad4b-company/v1/simple-content-feed with JSON body {"post_type":"tours-and-activities","page":1} (exact registered source hostname, no redirects, TLS validated, native Application Password, avoids caching of authenticated GET).
- Supported source CPTs: tours-and-activities, properties, transportations. Only published posts; verified EN/AR/FR/DE/IT/ES WPML language tags on the Tourism source.
- Imported fields: source company, post type, original post ID, source language, title, summary, sanitized body text, source modified time. No private metadata, users, orders, submissions, media bytes, WooCommerce prices or secrets.
- Each import upserts an existing private Content Hub post keyed by company + post type + language + source ID. The latest complete source collection defines the active index; missing source items are removed from distribution but retained privately for review.
- A failed or partial source fetch does not activate a new import index. No automatic publication or WooCommerce checkout.
- Stored post type: mad4b_hub_content (private / excluded from public REST and search).
- Auto-distribution uses the newly imported Hub reference set if the default mode is selected. The old reference_only mode remains available.
- Current consumer inbox supports private preview references. Native public JetEngine Query/Listing/SmartFilters integration is a separate implementation step, not activated by selecting import mode.
- Post types and source sites are allowlisted. This initial flow uses the Tourism source; additional companies and custom provider discovery require later extensions.
- WP-Cron is traffic-triggered. Exact time-based scheduling requires an independently supervised scheduler.
- Existing P-256 source reader remains intact for the old Nile history/reference flows. No active authentication is deleted in this incremental rollout.

## Tests and rollout

Tests: test-simple-connections.php, test-auto-distribute.php, test-hub-source-import.php under ops/central-company-rest/tests. Run with PHP 7.4/8.3 CI and in isolated Staging PHP CLI.

Deploy the **central** 240/250/260 files to Central Staging with Auto Distribute disabled, verify REST route registration and the default import mode. The user explicitly authorized SSH preparation on the Tourism source host. The two read-only source MU adapters 240/260 have been installed there, with a dedicated subscriber user (ID 12). Production source content was not modified. The native service Application Password was tested once and later revoked after automated central credential handoff was blocked; no connection secret remains in the handoff files. The central connection is still unconfigured and automatic source synchronization cannot start.

Rollback: disable Auto Distribute, remove only the added central MU files (or restore prior 250 version), and clear its cron. Keep original source site, existing Hub data, and former signed connectors unchanged.

## SSH acceptance checkpoint — 2026-10-30

- Verified source hosting account: registered SSH target hosting-1 (Cloud Startup), WordPress root under almallahgroup-mg.com/public_html/tourism.almallahgroup-mg.com.
- Source mu-plugins/240 identity and /260 source feed installed; WordPress 7.1.4/PHP 8.3, both REST routes registered, unauthenticated 403, site HTTP 200.
- Dedicated reader subscriber account bound by option mad4b_simple_service_user_id_v1 (user ID 12). Authenticated application-password identity verified as HTTP 200 once; that temporary password was revoked and its plaintext handoff copies deleted after central enrollment was blocked by tool safeguards.
- Feed internal callback: 200 for tours 217, properties 597, transportations 13; published content in six WPML languages (EN, AR, FR, DE, IT, ES).
- Central Staging now supports the same six languages; import-only runs are not restricted by the 150-item *consumer delivery* batch limit. Auto Distribute disabled. 35 older Tourism Nile references remain stored as private Hub items.
- Isolated regression: 23 connection tests, 43 combined delivery tests, 45 source-import tests passed. No live 827-item source-to-Hub sync occurred.
- Next operation is native credential pairing through an authorized secure credential entry flow in Content Hub Connections. Only after a successful source page and import test should scheduled import be enabled; consumers still require their own private inbox connection.

## Identity cache-safety correction

The Source read identity endpoint uses **POST only** (not GET), because LiteSpeed was observed returning a cached authenticated GET 200 without an active credential. The remote registered route uses POST; central exact-host verification uses wp_safe_remote_request with method POST over TLS; an explicit URL purge removed the previously cached GET response. Verified post-fix on Tourism: anonymous GET 404 and anonymous POST 403. Never trust public cached GET response as proof of Application Password authorization.

## WP-CLI Cross SSH — native host-only source transport (2026-10-30)

**Source:** Tourism Cloud Startup Hostinger SSH target `hosting-1` on registered port 65002. SSH key + pinned `known_hosts_verified` live exclusively on the primary MAD4B VPS in `/opt/hostinger-shell-mcp/external-targets/hosting-1`. No SSH key or Application Password is copied into WordPress.

**Host runner:** `ops/central-company-rest/ssh/run-tourism-cross-ssh.sh`. Deploy as root, for example to `/opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh`. The runner accepts no free-form hosts, usernames, WordPress paths or shell command arguments. It enforces the exact source and central WordPress site URLs, pinned SSH host key, mutual-exclusion flock, validated all-three-type JSON manifest and SHA256 byte-for-byte transfer.

**Read-only source script:** `ssh/tourism-source-export.php` runs using source WP-CLI `eval-file` on the enrolled source host; reads only `publish` Tours, Properties and Transportations (all six WPML languages). It never changes source posts, settings, users, customers, bookings or rates. A fixed source deployment path is used, outside public_html.

**Central receiver:** `ssh/central-receive.php` runs only through central Staging `wp eval-file`, accepts only the staged local manifest, requires `WP_CLI` and the explicit one-off host runner constant, revalidates source company, exact canonical host, type counts and 0..2000 record bounds, plus every noncommercial item before updating the private Content Hub post type. No HTTP import endpoint or SSH key access in WordPress.

**Central Settings:** Content Hub → Auto Distribute → Source connection → **WP-CLI Cross SSH (VPS runner)**. Keep `source_mode=import_to_hub`, select all three source CPTs and the desired languages; `source_transport=wp_cli_cross_ssh`. A separate `wp_rest_application_password` choice remains supported. In Cross SSH mode, native WP-Cron import and the browser Run Distribution button are disabled intentionally. Host execution is explicit; a host scheduler is **not installed or enabled** by changing the dropdown. In this initial release, Cross SSH supports Tourism only, and does not automatically distribute to other companies.

**Measured end-to-end Staging acceptance:** the source exported **827** published items (**217 tours, 597 properties, 13 transfers**), manifest SHA256 `e8998af9ad6ae4e896b95bc3931b253fc36cc52c8de65c49c4c0eb976344de01`. Content Hub central private storage verified **827** matching active records in EN/AR/FR/DE/IT/ES, zero publication/checkout/tenant violations. Repeat import gave `created=0, updated=0, unchanged=827` and central `last_run.status=imported_only`. Source and Staging websites returned HTTP 200. **No consumer distribution or public publishing.**

To rerun manually on the authorized primary VPS as root:

```bash
bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh --status
# Governed sync only when explicitly invoked by authorized VPS root operator:
bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh --run --request-key <unique-idempotency-key>
```

Rollback: keep the source unchanged; leave automation disabled; restore backed-up central 250/260 MU adapters if needed. The 827 private posts remain accessible for inspection; to revert records, use the pre-import scoped backup (35 old records and Hub options) retained under `/opt/mad4b-company-release-prep/20261030/tourism-cross-ssh-release/backup-state/`. Do not delete anything automatically. Further dynamic tenant/provider discovery and per-company private distribution require their own receiver registration and run policy.

## Central Staging code ownership and owner-only release attestation — 2026-10-30

**Operational source of truth**: the Tourism read-only exporter lives under the central `tours.dev.mad4b.com` Staging runtime on the MAD4B VPS, at `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/source-providers/tourism/source-export.php` (root-owned, 0600; not web-served). The VPS host runner streams these source bytes over the pinned SSH connection via `wp --path=<Tourism root> --skip-themes eval-file -`. Tourism only provides its WordPress data and API runtime; there is **no requirement to install or update an exporter PHP script on the source**. The historical source-side exporter file is no longer used by the updated runner.

**Safe verification**: `bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh --verify-only` runs export and contract/count checks, without any Content Hub import. Direct `--import` and no-argument invocation are now intentionally refused; only `--run-queued` may mutate private Hub records after a registered Job is queued. No automatic scheduler is enabled.

**Approval policy**: one recorded owner decision for the **exact proposed code version/HEAD** is sufficient for release or PR merge. GitHub serves only as version history and review audit. There is no independent release certificate, extra signing ceremony or third-party certification gate imposed by this integration. Runtime requirements still include correct authorized company, scoped credentials, SSH host-key verification, data validation, no implicit publishing and passing tests. The owner's general preference for single-owner attestation does not itself approve a specific HEAD or trigger deployment to Production.

**Observed verification**: the centrally stored script was executed remotely by WP-CLI over pinned SSH and read 827 published language-specific records (217 tours, 597 properties, 13 transportations), with no content mutations. The updated installed host runner passed `--verify-only` on the same 827-record source. SHA256 checks are transfer-integrity checks, not release certificates.

## Central Source Sync Jobs via WordPress Control Plane — 2026-10-30

Central Staging operational code and data live on `https://tours.dev.mad4b.com/` within the enrolled MAD4B VPS. The source is only WordPress Tourism's read-only data. GitHub is version history and regression checks, not an alternate execution/data authority.

- Native read Abilities on Central Staging: `mad4b/hub-source-providers` (existing), `mad4b/hub-source-sync-plan` and `mad4b/hub-source-sync-status`. They expose company/dataset identity, bounded committed import receipts and run status to Control MCP without exposing secrets or enabling publishing.
- `central/276-mad4b-hub-source-sync-jobs.php`: manager-only, idempotent, single-active-job queue for exactly `tourism-almallahgroup / tourism_wp / tourism_main / wp_cli_cross_ssh`, registered with Company Source Registry, `source_mode=import_to_hub`, no consumer, no automatic distribution. Requests are not shell commands, arbitrary provider URLs or unconditional imports.
- WordPress admin REST routes for Standard WordPress auth: `GET /wp-json/mad4b-central/v1/hub-source-sync/plan`, `GET /wp-json/mad4b-central/v1/hub-source-sync/jobs`, `POST /wp-json/mad4b-central/v1/hub-source-sync/request`. Standard REST cookie nonce or Application Password and `manage_options` are required. **Do not expose public access.**
- The native **Write** Ability `mad4b/hub-source-sync-request` is now mounted through the **existing SCP core Write Catalog** with category `mad4b-write`, execution lane `write`, and provider `core` (not a read-only reclassification). The prior `original_lane_not_mounted` blocker is resolved. The SCP native authorization boundary still rejects requests without verified NHI identity (`mad4b_nhi_identity_required`), before any job is created. SCP injects standard one-time owner approval ticket/context receipt inputs; the queue accepts their strict optional shape but does not store them or use them as an authorization grant. Actual remote MCP-owner-context execution has **not** been verified; do not confuse catalog admission with a fully authorized remote write.
- Explicit host worker: `bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh --run-queued`. The root-only VPS runner claims exactly one pending job via local WP-CLI, reads source with **central exporter bytes** sent over pinned SSH to remote `wp eval-file -`, validates the all-types 827-item snapshot, imports into private Hub records and verifies the committed execution ledger. On failure it records the job as failed. When no job is queued it refuses to import (observed exit 91). `--verify-only` still does read-only validation. There is **no Cron or automatic dispatch**.
- First internal admin REST request created job `518ef7b3-c01c-4b1f-8d09-1f8860ec97a6`. The VPS worker claimed it, returned `SOURCE_EXPORT_VERIFIED=827`, imported `created=0`, `updated=0`, `unchanged=827`, and completed the job. The job SHA256 equals the last authoritative Content Hub import receipt and provider ledger SHA256. After completion: 827 private records, provider ledger 5 committed entries, `auto_distribute.enabled=false`, zero consumers, site HTTP 200. Anonymous GET plan/status responded HTTP 401.
- Tests on Staging: 26 isolated source sync lifecycle/owner-envelope cases, 68 source import, 18 provider registry, 12 field fusion, 23 generic/specialist Dedup and 6 native SCP Write mount assertions (**153 passing assertions total**). PHP 7.4/8.3 workflow includes the Write mount regression. Tests do **not** certify cross-company auto-merges.

**Outstanding**: confirm an authenticated **remote MCP client** with the correct NHI/OAuth actor and one-time scoped approval successfully executes the now-mounted Write Ability. Separately validate real authenticated remote REST operation (current successful POST was in-process with an administrator WordPress context). Expand provider identity keys and source schema to more than Tourism. Hotel identity remains unresolved for 30/30 review pairs. Per-field canonical entity materialization, cross-company distribution and rollback drills are not enabled.

**Approvals**: exact-version owner approval only. No additional release certificates, signatures or approval ceremonies are introduced. WordPress capability checks, source identity, SSH pinning, write atomicity and review holds remain mandatory runtime safeguards.

## Default governed fallback: External Control MCP → VPS Local WP-CLI — 2026-10-30

**Default policy is `external_first_vps_wpcli_fallback`, Staging-only, installed at central `tours.dev.mad4b.com`.** This is a transport fallback for an already-authorized operator initiating a specific Company source-sync task; it does **not** turn an anonymous or remotely denied operation into an approved local write. A visible external, verified OAuth client remains the preferred Control MCP route. When ChatGPT OAuth client binding is genuinely unbound, the default recovery route is the root-owned VPS WP-CLI dispatcher.

- WordPress central MU file: `277-mad4b-hub-source-local-fallback.php`. Read-only native Ability: `mad4b/hub-source-fallback-status`. Default is enabled **only** on the exact enrolled Staging site; opt-out is available via WordPress option `mad4b_hub_source_local_fallback_enabled_v1=false`. `--status` does not write.
- Host dispatcher: `/opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh` (root:root, 0700). Modes: `--status` (default), `--dry-run`, and **explicit** `--run [--request-key KEY]`. A stable request key prevents a retry from creating or importing a second Job. No Cron, no polling, no silent job creation, no stored OAuth impersonation.
- Enforced boundaries: root operator of pinned VPS; exact central URL/environment `tours.dev.mad4b.com/staging`; one **enrolled WordPress administrator** in WP-CLI; registered `tourism-almallahgroup/tourism_wp/tourism_main` with `wp_cli_cross_ssh`; source mode `import_to_hub`; zero commercial consumers; Auto Distribute and Auto-Merge off; no active Job; no arbitrary host, company, CPT, credentials, or remote URL. Fallback triggers only on `external_client_unbound`, **never** on remote MCP authorization denial/401/403.
- Prequeue audit: bounded WordPress option `mad4b_hub_source_fallback_decisions_v1` records UTC, actor WP user ID, source scope, reason, request key and safe outcome. A failed audit write prevents queue creation. Job and provider execution ledger separately record the import and SHA256 receipts. Root-owned scripts reject duplicate concurrent invocations with `flock`; unmatched retries do not enable publishing.
- Hardening: `run-tourism-cross-ssh.sh` no longer accepts unchecked `--import` **or zero-argument import**. Its only modes are `--verify-only` (source read, no Hub write) and `--run-queued` (claim exact persisted Job, import and commit verified receipt). SSH known-host pinning and private export files remain unchanged.
- Operational commands (on authorized VPS only):
  ```bash
  bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh --status
  bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh --dry-run
  bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-source-fallback.sh --run --request-key vps-fallback-operation-001
  ```
- **Live acceptance:** a single intended request `vps-default-fallback-e2e-20261030` created Job `26effd9f-caf9-4cad-a509-98343f2850eb`, completed via the pinned source SSH reader. 827 published source records → 827 private Hub records; `created=0`, `updated=0`, `unchanged=827`. Provider ledger went from 6 to 7; active jobs zero. Full 827-record pre/post snapshot comparison (post titles/bodies/excerpts/status and all metadata) found **zero differences**. A same-key replay reported `FALLBACK_JOB_ALREADY_COMPLETED_NO_REIMPORT` and did not append a ledger entry. A concurrent invocation was rejected by the host file lock. No Production mutation.
- Tests: `test-hub-source-local-fallback.php` (21 assertions), source sync lifecycle (26), import (68), provider registry (18), Field Fusion (12), Dedup (23), native Write mount (6). The Hub CI workflow lints the host scripts and runs PHP 7.4 / 8.3 tests. Backups and snapshot evidence are stored root-only on the VPS under `/opt/mad4b-company-release-prep/20261030/tourism-cross-ssh-release/default-local-fallback/`.

**Security distinction:** this default fallback covers **local administrative source-sync transport only**. It is NOT an authentication fallback for remote ChatGPT requests. An external ChatGPT OAuth/NHI identity and SCP Write grant still need a real verified connection; no owner certificate or additional release attestation is imposed beyond owner version approval.
