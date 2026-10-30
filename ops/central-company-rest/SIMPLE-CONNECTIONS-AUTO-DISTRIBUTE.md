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
bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh
```

Rollback: keep the source unchanged; leave automation disabled; restore backed-up central 250/260 MU adapters if needed. The 827 private posts remain accessible for inspection; to revert records, use the pre-import scoped backup (35 old records and Hub options) retained under `/opt/mad4b-company-release-prep/20261030/tourism-cross-ssh-release/backup-state/`. Do not delete anything automatically. Further dynamic tenant/provider discovery and per-company private distribution require their own receiver registration and run policy.

## Central Staging code ownership and owner-only release attestation — 2026-10-30

**Operational source of truth**: the Tourism read-only exporter lives under the central `tours.dev.mad4b.com` Staging runtime on the MAD4B VPS, at `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/runtime/source-providers/tourism/source-export.php` (root-owned, 0600; not web-served). The VPS host runner streams these source bytes over the pinned SSH connection via `wp --path=<Tourism root> --skip-themes eval-file -`. Tourism only provides its WordPress data and API runtime; there is **no requirement to install or update an exporter PHP script on the source**. The historical source-side exporter file is no longer used by the updated runner.

**Safe verification**: `bash /opt/mad4b/operations/content-hub/cross-ssh/run-tourism-cross-ssh.sh --verify-only` runs export and contract/count checks, without any Content Hub import. Explicit `--import` (or the legacy no-argument mode) runs the existing private Staging receiver, subject to its own policy and data safeguards. No automatic scheduler is enabled.

**Approval policy**: one recorded owner decision for the **exact proposed code version/HEAD** is sufficient for release or PR merge. GitHub serves only as version history and review audit. There is no independent release certificate, extra signing ceremony or third-party certification gate imposed by this integration. Runtime requirements still include correct authorized company, scoped credentials, SSH host-key verification, data validation, no implicit publishing and passing tests. The owner's general preference for single-owner attestation does not itself approve a specific HEAD or trigger deployment to Production.

**Observed verification**: the centrally stored script was executed remotely by WP-CLI over pinned SSH and read 827 published language-specific records (217 tours, 597 properties, 13 transportations), with no content mutations. The updated installed host runner passed `--verify-only` on the same 827-record source. SHA256 checks are transfer-integrity checks, not release certificates.
