# Feature 007 — Competitor-first Market Intelligence & Contracted DMC Exchange

**Design correction (2026-10-09):** Competitor research, benchmarking and independent brand writing are the **primary workflow** and do **not** need a supplier/distributor agreement. DMC/supplier distribution, catalog imports and catalog exports form a **different** contractual lane using live WordPress Post Types, taxonomies and permitted mappings. A user may dynamically configure both profiles and pricing strategies without deploying per-partner code.

## Two separate lanes

### A. Competitor Intelligence — default, noncontracted
1. Register a bounded competitor research profile (HTTP(S) source and display name). No supplier contract expected.
2. Research observable facts, routes, inclusions, itinerary structure, prices, availability clues, positioning and SEO opportunities. Respect source access controls, applicable terms and rate limits. Record each fact's source and observation date; cross-check material booking facts.
3. Use `mad4b/competitor-research-plan`: input is **facts and media candidates**, never a command to clone a complete competitor website. It is read-only and explicitly permits market analysis and draft preparation without a supplier contract.
4. Use `mad4b/market-growth-evaluate` to compare like-for-like currency and minor-unit price inputs. Strategies: `match_market`, `markup_percent`, `discount_percent`, `markup_fixed`, `discount_fixed`, `fixed_price`. Minimum cost and optional floor/ceiling cannot be silently overridden. Values are suggestions, **not a confirmed live supplier/consumer quote**; taxes, booking conditions, FX and inventory need independent readbacks.
5. Use current, approved Brand Core + a scoped Writer Skill to produce **new, original, brand-aligned** wording; Critic/Reviewer roles are independent. Source text must not be copied verbatim as a substitute for writing.
6. Discover competitor media URLs *as metadata only*. Assess authorship, licensed use, public-domain claims and actual license restrictions. Do **not** download/rehost/publish a competitor's copyrighted image just because it is publicly accessible or easily downloadable. Reuse only first-party or verified-license material; use licensed originals/alternatives when verification is absent.
7. Resolve site CPT/taxonomy dynamically and build a draft with `mad4b/content-orchestration-plan` and separately approved `mad4b/content-apply-bundle`. No suggestion may claim partnership, allow supplier checkout, or publish automatically without independent service/rights/price/acceptance gates.
8. Drafting independent informational content is distinct from publishing a real bookable commercial offer. A competitor catalog is **not** live inventory and does not create seller/booking rights.

### B. Contracted DMC exchange — optional
1. Register an actual DMC/supplier profile and agreement reference/validity; configure a connection (`import`, `export`, `bidirectional`), independent from competitors.
2. Register a mapping to the **existing** WordPress CPT (e.g., `tours-and-activities`) rather than hardcoding a site-specific post type. Discover registered taxonomies through WordPress at use time and restrict fields to an allowlist.
3. `mad4b/dmc-exchange-plan` checks recorded agreement status, direction, registered CPT and current WordPress capabilities. Metadata is not independently verified legal proof, so the plan does not send a feed or authorize publication.
4. `mad4b/dmc-import-prepare` converts 1–20 bounded source records into **draft candidates**, each with deterministic operation key. Actual writes must still pass the existing separately approved `mad4b/content-orchestration-plan` then `mad4b/content-apply-bundle`, exact readback, context/license review and duplicate handling.
5. `mad4b/dmc-export-preview` retrieves only authorized, published local CPT rows and only explicitly mapped safe core fields (20 per page max). The resulting JSON is a handoff payload; outbound HTTP transfer is **not implemented** and must be a separately authenticated, scoped, rate-limited, audited connector.
6. Contract termination, expiry, privacy, localized product rules and tax/price conversion invalidate affected exchange plans; production transfers remain independently gated.

## Configurable registry
`mad4b/market-growth-policy-status` returns a bounded, redacted registry view with exact revision and SHA-256. `mad4b/market-growth-policy-update` permits confirmed `manage_options` edits using the exact expected revision and hash, an atomic option writer lock, typed field allowlists and postwrite readback. Collections: `competitors`, `suppliers`, `dmc_connections`, `feed_mappings`, `pricing_rules`, `media_rules`, `assistant_roles`. Plugin extensions can evolve the schema through explicit reviewed versions; arbitrary properties cannot silently grant rights.

`mad4b/market-assistant-route` picks configured research/writer/critic/reviewer/recovery Skill candidates using current external runtime evidence and defaults to human fallback. It **does not inherit exact Agent grant** and cannot authorize a write or its own independent review.

## Persistent recovery
The existing governed Brand draft creation, materialization and reconciliation endpoints now reserve atomic, site/environment/scope-bound retry slots in WordPress options. Three reservations constitute a hard ceiling for that exact scope; a successful attempt or a pending/uncertain provider result blocks a duplicate automatic retry. An uncertain result must first be reconciled. Operator reset requires `manage_options`, explicit confirmation and a current exact journal SHA-256; no AI auto-reset. A write reservation is not a new authorization: existing policies and exact Context mutation tickets remain mandatory. This local attempt budget complements, not replaces, the existing operation journal and idempotent materialization identity; cross-node native concurrency tests remain a release gate.

## Acceptance & negative cases

| Scenario | Expected |
|---|---|
| Competitor without a contract | Public-fact research, benchmark and original draft plan allowed |
| Competitor image without independent reuse license | Candidate URL recorded; reupload/republication not authorized |
| Third-party supplier offer without an agreement | No supplier affiliation/booking authorization |
| Contracted DMC | Only configured direction/CPT and authorized source products can be prepared/exchanged |
| Unknown or expired DMC agreement | Export/import plan blocked |
| Native post type missing | Structured error, not arbitrary CPT creation |
| Duplicate DMC external ID | Error before writes; stable deterministic bundle key |
| Export private/unmapped WP content | Filter out by status, field allowlist and `edit_post` permission |
| Stale pricing registry or currencies mismatch | No authoritative quote; replan from current evidence |
| Retry after a successful or uncertain provider write | Stop and reconcile; no duplicate write |
| Fourth attempt on same exact scope | Persistent `mad4b_retry_circuit_open` |
| Runtime Skills ready but Agent grant absent | Planning possible; write authority not implied |

## Not yet claimed
No remote crawler/scraper, licensed image downloader, remote DMC feed push, external contract authenticator, market quote feed, native PHP/WordPress/Staging integration or production publication has been executed or verified by this PR alone. This implementation establishes guarded configurable registries, actionable read-only competitor/DMC/assistant plans, bounded native CPT export payload, import candidates delegating writes to the already governed content adapter, and persistent guarded attempts in the existing Context write paths.
