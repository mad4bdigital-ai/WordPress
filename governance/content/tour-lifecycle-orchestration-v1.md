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
