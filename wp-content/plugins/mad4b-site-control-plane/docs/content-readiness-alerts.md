# MAD4B Content Readiness & Alerts v1

A site-neutral module within MAD4B Site Control Plane, reusing existing WordPress, WPML and provider contracts.

The scanner reads configured published CPTs, source meta fields (origin, location, media, owner, date and stop sale), missing WPML languages, WP-Cron backlog, and optionally Fluent Forms unread follow-ups. Rules are stored in WordPress option mad4b_content_readiness_rules_v1 and are independently configurable per company. There are no fixed business domains, Tour IDs, brand names, or commercial fields in the shared module.

Admin UI: MAD4B Control Plane > Content Readiness. Editors can inspect issues. An administrator can save rules and explicitly scan. Issues use stable site-ID + type + object-ID + gap-code digests to prevent duplicates, auto-resolve after a complete scan and reopen on regression. A dashboard notice and admin bar counter expose critical issues. No emails or webhooks are fired on page loads.

Fluent Boards: the module can create a governed task linked to an open issue and store its task ID; the central MAD4B mutation gate, administrator permission, and an explicitly selected board and stage must authorize execution. The site has no approved content board yet and its global write gate is disabled, so no external tasks or mail notifications were created during tests. Fluent Support and Bit Flows outbound routes remain separate follow-on certification; never broadcast unapproved mass alerts.

Source example for ETG Lab (SITE POLICY ONLY): post types tours-and-activities, properties, transportations, tour-rates; origin meta tour_origin_address; taxonomy location_jet; property gallery key property_gallery; Tour Rate owner related_tour_id, date tour_rate_end_date and stop_sale. Configure Fluent Forms ID 6 only on the proper origin. An empty media meta field signals review, not conclusively missing visitor-facing imagery.

No automatic booking, pricing, content modification, extension of expired rates, or generic REST write endpoint. Source scan has a 3000-post cap per CPT and a 3000-issue storage cap. Partial scans do not reconcile. The operator must explicitly trigger a scan; scheduled scans depend on verified scheduler readiness and are NOT enabled while WP-Cron is unhealthy.

Acceptance: isolated ledger tests exercise discovery purity, deduplication, resolution, reopening and permission rejection. Main Lab bootstrap is reversible from the VPS backup created before deployment. Production remains unchanged.


## Source-provenance extensions (2026-10-28)

Additional per-tenant fields: origin_source_fields maps post types to term-ID meta;
origin_source_taxonomies resolves its registered taxonomy; invalid_origin_levels
declares forbidden term levels. The detector resolves selected terms through the
current site's WPML reference language before examining authoritative
destination_type metadata. A broad continent source is not a pickup address. An invalid broad origin and the empty derived address are treated as one root-cause issue containing term ID and hierarchy evidence. Broken origin term references have a separate explicit integrity issue.

Media reuse detection is opt-in with media_reuse_threshold (0 disables).
An identical gallery source reused by multiple distinct content records raises
a review issue, but the detector never deletes images, fabricates photo URLs or
automatically changes hotel ownership. A missing or malformed configured rate
end-date also raises a separate high-priority issue.

### ETG WooCommerce Lab remediation receipt (not a portable data seed)

- Confirmed local DB: etg_woocommerce_lab; Production and external Staging unchanged.
- 85 published Tour Rates expired before 2026-10-28 had stop_sale activated.
  A readback proved all 90 expired Rates now have stop_sale=1. Commercial prices,
  currencies, availability dates and publication status were untouched.
- 32 Tour origin records were refreshed solely from valid selected destination
  term IDs resolved to canonical WPML reference terms, preserving Google Place
  ID and coordinates. Seven literal 'Africa' origin placeholders tied to
  continent-level selected terms were cleared; selected source terms retained.
  The existing save hook now refuses to regenerate continent-level pickups.
- No source-backed remediation was available for 30 tours missing WPML language
  registrations, 584 properties missing configured galleries, or 51 unlinked
  destination assignments. Those remain in the reviewer-facing issue ledger,
  not silently translated or assigned a shared/stock hotel image.
- 19 populated property galleries use an identical asset set, so the shared
  gallery review issue is intentionally open rather than certifying hotel media.
- Configured rules use the site's actual destinations taxonomy, not the absent
  location_jet alias. Form ID 6 is only part of this local site's rule config.
- After root-cause issue deduplication, 781 issues remain open: 45 high and 736 medium. Thirteen former duplicate empty-origin alerts were resolved without deleting the source term issue.
  Two independent scans confirmed ledger key stability, no external tasks,
  and 3 pre-existing Fluent Boards tasks unchanged.
- Backup and exact rollback evidence reside on the authorized VPS under
  /opt/staging/content-gap-repair-20261028/backups/.
- Avoid distributing the one-time Lab SQL/content repair scripts as part of
  a tenant-wide plugin update. General code reads the target company's own
  fields, WPML and taxonomy definitions.

## Governed administrative REST readback (2026-10-28)

- GET /wp-json/mad4b-content-readiness/v1/summary returns current Open / High /
  Critical / Resolved counts, last reconciliation timestamp, and snapshot size.
- GET /wp-json/mad4b-content-readiness/v1/issues returns sanitized source
  issue evidence and status. Optional filters: status (open, resolved, all),
  code, post_type, severity, page and per_page (1-100).
- Both require an authenticated user with WordPress edit_others_posts
  capability, verified by a route permission callback. The API has no
  Create, Update, Delete, bulk scan or task creation endpoint.
- No public host is hardcoded; the current WordPress site serves its routes.
  Read endpoints do not execute the content scan or mutate Issue / Board data.
- Exact Lab REST checks: summary HTTP 200 with 781 open; filtered 13
  origin_source_too_broad entries; unauthenticated GET HTTP 401; POST
  HTTP 404; issue registry and Fluent Boards task counts unchanged.
- The isolated test is portable between staging sources and the plugin's
  GitHub tests/content-readiness layout.

These routes are intended for a future governed Control Plane diagnostics
consumer. They do not imply approval for automated external notifications or
unrestricted ChatbotX operations.
