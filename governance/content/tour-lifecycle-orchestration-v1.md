# MAD4B Tour Lifecycle Orchestration v1 — governed design contract

Status: PROPOSED / NON-AUTHORIZING. Scope: Tours staging first; multi-tenant & multi-provider capable. Does **not** install a runtime or enable publishing.

## End-to-end stages (immutable order, with explicit resumability)

| # | Stage | Input → durable output | Gate / mandatory checks |
|---|---|---|---|
| 01 | Source Discovery | destination/keywords/market → source manifest (URLs, canonical, fetched_at, locale, content digest, robots/ToS status) | source provenance; crawling permission; secrets isolation; rate limits |
| 02 | Market Competitor & Competitor Dominating | source manifest → competitor landscape, positioning/offer gap, share-of-search proxies and evidence-backed opportunity ranking | distinguish observed vs estimated metrics; never claim dominance without defined comparison cohort/date/metric |
| 03 | Content Intelligence | approved source extracts + brand profile → unique angle, search intent clusters, multilingual brief, original editorial draft | factual claim/evidence links, copyright & supplier rights, AI disclosure where required, no unauthorized copy |
| 04 | Data Mapping | canonical content model → field-by-field provider bindings + validation manifest | capabilities discovery from provider, type/schema/version, null handling, no cross-site term IDs |
| 05 | JetEngine / WooCommerce Fields | bindings → CPT post (draft), post meta, taxonomy terms, JetEngine CCT only when registered, Woo product association | native APIs/abilities, capability scoped; idempotent upsert; snapshot, lock, readback |
| 06 | Prices & Itineraries | supplier contract/rates + itinerary sources → versioned itinerary and offer/rate candidates | no synthetic live prices; dates, currency, taxes, pax, nationality, inventory, cancellation; approvals before selling |
| 07 | WPML | canonical EN record + translation plan → translation sets and per-locale readback | English source; translation group/trid; term ID mapped per locale; fallback explicit, no duplicated catalog identity |
| 08 | Media Scraping & Importing | rights-cleared asset manifests → downloaded, scanned, deduplicated Media Library entries | allowlist domains, SSRF prevention, robots/license/credit, consent, MIME/bytes, malware scan; reject unlicensed imports |
| 09 | Elementor QA | Elementor kit/template/listing references + rendered URLs → desktop/mobile QA findings and screenshots | no direct widget schema guesses; query/listing/filters, responsive, LCP/CLS, accessibility, design tokens & WPML locale |
| 10 | Approval | evidence bundle + reviewer assignment → signed decision per content/price/rights/translation/media | separation of duties; exact hashes, SLA, expiration, deny-first; editorial approval ≠ commercial approval |
| 11 | Publication | approved immutable release manifest → status transition and public-edge readback | exact current revision, separate publish ability, permissions, publication acceptance, rollback/unpublish |
| 12 | Post-Publish Monitoring | production URLs & metrics → status/quality/SEO/price drift alerts and corrective draft | scheduled checks, attribution, consent-safe analytics, broken links, price and translation drift, audit & incident routing |

## Cross-stage contracts

- Every stage has: `stage_id`, `run_id`, `tenant_id`, `site_uuid`, `environment`, `provider_id`, `scope`, `input_sha256`, `dependency_sha256`, `output_sha256`, `attempt_id`, `started_at`, `ended_at`, `state`, `evidence_refs`, `error_code`, `review_state`, `audit_ref`.
- Allowed states: `not_started -> planned -> ready -> running -> completed`; exceptional: `blocked | awaiting_approval | failed | recovery_required | cancelled | stale`. Never silently treat `blocked` as success.
- Planner is read-only. Discovery may fetch public material under governed terms but must not write CMS state. Mutating stages require exact target, context, user/agent authority, environment and provider capability gates at execution time.
- Stage outputs are immutable versioned artifacts; downstream execution pins upstream hashes. Changed source/market data invalidates only dependent stages and forces replan, never silently rewrites live pages.
- `operation_key` is stable across retries for one logical unit. Each attempt gets separate `operation_id`; lock and existing entity binding prevent duplication. On uncertain writes, stop and reconcile before retry.
- No one-stage all-or-nothing claim across WordPress, Woo, WPML, media store and external providers. Each native provider writes with its own journal/compensation/readback; record partial success and `recovery_required`.
- Manual gates are mandatory for supplier commercial truth, media rights, final publication and high-impact changes; AI may propose but not self-approve.
- Separate credentials and policies for scraping, content import, publishing, analytical monitoring; never reuse developer/breakglass authority.
- Use English as canonical source for content fields then map locale-specific WPML IDs and translation groups. Geographic concepts use provider-neutral canonical keys (Place ID or equivalent), never cross-site raw term IDs.
- SEO competitor data is research input, not license to copy photographs, descriptions, itineraries or pricing. Competitor share is not verifiable unless metric, timeframe and cohort are included.

## Target-specific provider mappings (capability-discovered, not hardcoded globally)

- `tours-and-activities` or provider-registered tour CPT; WordPress posts + postmeta, dynamic JetEngine taxonomies/term meta, CCT/option pages only if API exposes exact authority.
- WooCommerce custom tour product type: multiple itinerary program variants, rate periods and currencies, booking/availability states. Rates never published unless verified by supplier and inventory.
- JetEngine Query Builder, Listings and JetSmartFilters are native consumers, not a parallel rendering layer. Test relevant list/grid/accordion and search filters on desktop/mobile.
- WPML: canonical EN reference, per-language term/post mapping, translation link integrity and meta-field translation configuration.
- Elementor: Kit global colors/typography, inherited templates, nested mobile presentation, archive widgets and search/filter interactions.
- Additional providers integrate via discovery registry: `capabilities -> field schema -> permission -> plan -> apply -> readback -> compensate`; unknown capabilities fail closed.

## Required rollout and acceptance gates

**Phase A — observability-only:** stage catalog, provider discovery snapshots, evidence lineage and chain preflight read-only. No production changes.

**Phase B — Staging mutation:** tour draft + provider field mappings + itineraries/rates candidates + translations/assets in quarantined non-public form. Test duplicate, stale hash, wrong locale, wrong site, revoked permissions, SSRF, unlicensed media, unavailable supplier price, partial failure and rollback uncertainty.

**Phase C — editorial QA:** Elementor device and locale render check, accessibility/SEO, manual supplier/media/legal approval and signed release manifest. No auto-approval.

**Phase D — gated publication:** exact ability with independent approval and edge readback; publish to selected site/locale only, monitor and create corrective drafts rather than unreviewed live edits.

### Acceptance criteria
1. `plan` enumerates *all twelve* stage states and blockers with evidence/owner, never infers readiness from CPT alone.
2. `apply` executes only ready authorized stage(s), pins snapshots, records journals, and returns per-stage readback.
3. Multi-site/multi-language IDs are mapped using explicit canonical keys and target-site vocabularies.
4. Media import skips any asset without rights provenance; competitors inform strategy, never implicit content reuse.
5. Prices, inventory and booking remain non-public absent supplier contract and current verification.
6. QA tests at least one desktop/mobile and each requested locale; approval and publication remain separate.
7. Monitoring surfaces timestamped deltas and does not silently republish.
8. Production remains unchanged until separate signed approval and rollout.

## Current implementation boundary (2026-10-30)

Confirmed: `mad4b/content-orchestration-plan`, `mad4b/content-apply-bundle`, WordPress CPT draft, lock/journal/binding/compensation/readback; staging canary post 67887. Not yet evidenced as end-to-end implementation: competitor ranking, structured rates/itinerary verification, WPML creation, rights-cleared media import, automated Elementor QA, publication approval and monitoring. This proposal intentionally does not claim those stages are implemented.

## Full Plan Preflight contract (required before returning ready)

The orchestrator must return a structured per-stage `readiness` vector with principal identity (kind, enrollment and capability), provider ID/version/capability certification, exact tenant/site/environment/locale, Restore Epoch binding and ready/blockers, MCP peer inventory and foreign transport classification, credential presence **only** (never secret values), provider egress/robots/ToS/media rights, request cost/rate budgets, input and dependency hashes, current journal/lock/idempotency state, compensation support and missing approvals/evidence. An explicit `reason_code`, `remediation_owner`, `provenance_sha256` and `checked_at` accompany every blocker. `PLAN_READY` must be false if an execution-fatal prerequisite is known missing; no premature approval claims. Apply **always** rechecks at runtime, even if plan was ready. This is a design requirement; not yet the live planner's current behavior.

## Discovery providers

**Firecrawl** (`firecrawl`): bounded, separately authorized search/scrape/map/crawl/extract; structured source evidence carries source URL, retrieval time, HTTP status, locale, content digest, rights decision, crawl batch ID and attribution. Credential is runtime secret ref, not WordPress post meta or logged API key. Firecrawl search results may supply leads; competitors' data is research, **not** publishing permission. Media ingestion is a separate rights-reviewed import gate.

**SERP results provider** (`serp_search`): search-region/locale/date/keyword, rank and result type normalization, domain grouping, confidence/measurement method, cohort/timeframe and source provenance. Supports strategy research and observation of competitor positioning, not fabricated market share. The user called this "Serph API"; exact API brand and endpoint must be confirmed by discovery before configuring a live connector (possible SERPHouse, SerpApi or another provider). Do not silently treat these services as identical. No credential creation or API call occurs from this manifest.

Both provider contracts require discover → validate authentication → inspect allowed capabilities/regions/costs → bounded plan → execute public-data retrieval → normalize → cite provenance → store read-only research artifact. No arbitrary URL proxy, SSRF, private subnet access, unbounded crawl, rate-limit evasion, or direct WordPress mutation. Timeout, retries, spending limits, and tenant isolation are explicit.

## Provider normalization and fallback

Prefer existing site-installed certified provider when capability, rights and rate budget align. Search results may come from the configured SERP provider while Firecrawl fetches source pages. If one fails, planned fallback is only to an explicitly certified equivalent with cost/rights approval; never auto-switch secrets, compliance regime or billing account. Deduplicate by canonical URL and content digest while preserving independent search observations.

### Provider rollout tests

- Invalid/missing API key: plan blocked, key never exposed in logs.
- Unknown provider ID, different tenant, or unverified endpoint: fail closed.
- HTTP 429/402/5xx or quota exhaustion: stage-specific status and bounded retry, not infinite polling.
- robots/terms disallow or insufficient image license: no crawl/import.
- private/loopback/link-local URLs or redirected egress: deny.
- SERP rank changes across region/device/date: preserve separate observations, no bogus dominance claim.
- Dependency and identity changes between planning and application: stale error; replan with new hashes.
- Provider outage cannot unlock CMS mutation, approval, pricing or public publication.
