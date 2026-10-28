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

## Origin provenance and single-root-cause audit (2026-10-28)

The source-selection review found 13 published English tours referencing a source
taxonomy term in the continent level. In the ETG Lab this was Africa (term 2306).
A continent is not a passenger pickup location. Source titles such as From Cairo
are not verified operational pickup addresses: no location, place ID, coordinates
or WPML translation was guessed or written.

The generic source classifier verifies source terms in the company-configured
taxonomy, resolves source-language term via WPML, flags invalid references, and
reports a too-broad origin with term ID, taxonomy, destination_type and native
term edit link. It suppresses the duplicate empty-pickup alert until the invalid
source is corrected. On subsequent validation, an empty pickup still becomes
an actionable alert. All rules remain site-configured.

Admin issue list now sorts open-first, critical/high-first. The lab scan covered
1092 published records and returned 781 current issues (45 high, 736 medium).
The ledger retained 794 historical IDs: 781 open and 13 resolved duplicate
origin_missing alerts. The 13 broad-origin issues remain OPEN: resolved here
never means a pickup location has been created.

Readback confirmed all 90 expired Tour Rates have stop_sale=1. No pricing,
booking, rate dates, public Staging or Production was changed. Board ID remains
0 and central MAD4B write policy remains closed, so no task or outbound alert
was sent. No scheduled scans were enabled.

The isolated contract tests source discovery, deduplication, resolution/reopen,
permissions, gallery reuse, root-cause suppression, origin source evidence,
missing term reference rejection and missing commercial date classification.
Rollback files on VPS: /opt/staging/chatbotx-deploy/backups/content-readiness-origin-20261028/
One-time Lab data corrections are NOT distributed as plugin updates.


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

## DFSB source-backed Content Readiness adapter — 28 October 2026

The active Dynamic Filter SEO Bridge registers a read-only
`mad4b/content_readiness/external_issues` provider that emits four
site-wide SEO readiness diagnoses, each with a deterministic fingerprint:

- `dfsb_brand_identity_drift` — High, company identity drift in Rank Math.
- `dfsb_archive_meta_missing` — Medium, archive meta description unavailable.
- `dfsb_breadcrumb_owner_conflict` — Medium, possible duplicated schema owner.
- `dfsb_release_not_certified` — High, DFSB release certification incomplete.

The Control Plane validates exact issue identity against current WordPress
blog ID and source issue code; requires a scoped site issue, fixed provider
marker, a known severity, and a bounded label; rejects forged status,
task ID, duplicate fingerprints or malformed provider responses. A provider
failure makes the complete scan ineligible for reconciliation. When the
provider is detached, existing source issues remain open rather than being
misclassified as resolved. When a functioning provider explicitly emits
an empty result, the corresponding saved issue may resolve. A later
regression reopens the same original issue ID.

Exact ETG Lab certification on the isolated DB `etg_woocommerce_lab`:

- Discovery: 1,092 content records, 785 active issues including four DFSB issues.
- Ledger after save: 798 historical records, 785 open (47 High, 738 Medium)
  and 13 previously resolved empty-origin duplicate alerts.
- Two consecutive reconciliations returned identical summary and IDs.
- Fluent Boards task count remained **3 → 3**; no external notification
  or message was dispatched, and the central mutation gate remains closed.
- REST GET: guest 401, authenticated manager 200 with filtered DFSB record;
  REST POST: 404. REST readback did not modify the ledger.

These are Content/SEO **readiness alerts** only, not certified facts about
search engine ranking, indexing or actual customer-facing schema duplication.
Provider rules are kept in the DFSB integration and are not reimplemented
inside the central readiness engine.

Full rollback: original plugin source and pre-change ledger snapshot at
`/opt/staging/mad4b-content-readiness/backups/external-source-20261028/`.
Do not copy this Lab issue ledger into other companies or Production.

## Issue ledger pagination and reopen semantics — 28 October 2026

- The WordPress Content Readiness admin page defaults to Open issues. Editors
  may filter by Open / Resolved / All and by Critical / High / Medium / Low.
  The result table displays 100 issues per page, with bounded Previous / Next
  links and the displayed-versus-matched issue count.
- High-priority issues remain first within each selection, and a manager can
  navigate the entire issue history instead of the first 100 rows only.
- When a previously resolved source issue reappears, reconciliation increments
  its reopen counter, preserves its fingerprint and first-seen timestamp, and
  clears its obsolete resolved_at timestamp. A missing issue is closed only
  after a complete eligible scan.
- This change does not create Board tasks, send notifications, change external
  provider rules, or modify prices or source content.
- Local contract suite: reopen timestamp acceptance plus existing DFSB
  source-validation, authorization, REST filtering and ledger tests.
- Site-specific issue counts are operational snapshots, not bundled defaults.
