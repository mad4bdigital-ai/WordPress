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

- Source endpoint: GET /wp-json/mad4b-company/v1/simple-content-feed?post_type=tours-and-activities&page=1 (exact registered source hostname, no redirects, TLS validated, native Application Password).
- Supported source CPTs: tours-and-activities, properties, transportations. Only published posts; only verified EN/AR language tags in this pilot.
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

Deploy the **central** 240/250/260 files to Central Staging with Auto Distribute disabled, verify REST route registration and the default import mode. Consumer source-side installations require separate authorized Staging validation before touching any company Production site. With no source endpoint/credentials installed yet, the import mode is configured but cannot retrieve live content.

Rollback: disable Auto Distribute, remove only the added central MU files (or restore prior 250 version), and clear its cron. Keep original source site, existing Hub data, and former signed connectors unchanged.
