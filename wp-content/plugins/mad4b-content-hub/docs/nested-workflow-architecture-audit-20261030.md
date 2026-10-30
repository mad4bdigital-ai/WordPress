# MAD4B Control MCP → Research Intelligence → Canonical Content Hub → JetEngine: nested-loop audit

Audit date: 2026-10-30. Environment reviewed: Staging `tours.dev.mad4b.com`. Code inspected on VPS, along with active WordPress database and GitHub PR #650. **Read-only review; no Production mutations.**

## Architectural decision

This is NOT a linear scrape → draft → publish pipeline. Control MCP is the only orchestration entry point; the canonical Content Hub Database is the source of truth for versioned content entities and metadata. WordPress/JetEngine, WooCommerce, WPML and each connected company are consumers. Autonomous is the default workflow mode, with Assisted and Strict opt-in. Authenticated WordPress users are the default principal; independent NHI agents are optional for workers and headless operations. Company usage policy owns external content/media usage decisions, while source provenance and licence state remain recorded (unverified is not an automatic publication ban). Keep publication authorization and source-connection authorization distinct.

## Target nested loops and their completion criteria

### L0: Control, Identity, Discovery & Capability Loop

`Control MCP request → verify WordPress user or NHI → Site/Brand/Company identity → dynamic Provider/Skill/JetEngine discovery → eligible capability route → plan and budget → launch/resume ContentJob`.

Every loop iteration records input scope, selected provider and skill version, execution principal, policy snapshot, correlation ID, and idempotency key. On missing capability: discover/repair provider → re-plan the **same** task without creating duplicate jobs.

### L1: Context & Source Coverage Loop

`Site Profile → Brand Context → existing Hub entities → products/Rates/Blog/destinations/filter terms/guide languages/programmes → ContextPack → missing-context detector → enrich and repeat`.

Attach exact source fingerprints, language, market, company, site, freshness, and evidence; avoid owner-signature/certificate requirements beyond basic permissions. This loop terminates on adequate coverage or a reason-coded, visible partial result.

### L2: Active Research / Information Gain Loop

`Keyword + intent → SERP → competitor set → Firecrawl/provider observations → factual claim/entity ledger → five coverage matrices (topic/question/entity/evidence/UX) → information-gain score → targeted new research`.

Repeat until coverage threshold or diminishing information gain, budget, provider limits, elapsed time, or max iterations. Cache requests by provider/capability version + normalized query + language + time window; persist each observation, provider failure and opportunity. Cross-loop feedback: Content QA returns missing evidence directly to this loop.

### L3: Semantic Entity, Per-Field Metadata & Dedup Loop

`Extract → normalize → candidate entity match → source/field-level comparator → merge/split/new decision → provenance-aware canonical version → conflict queue → targeted research → readback`.

Make stable IDs across companies and languages for tours, destinations, companies, media, rates and itinerary programmes. Each field needs source identity, native field key/type, authority, timestamp/freshness, language, confidence, merge policy, old/new values, override owner and revision. Preserve exceptions for source-company-owned price/inventory, company-level dynamic pricing, and WPML term translations. Never resolve duplicate entities purely by similar titles.

### L4: Editorial & Quality Improvement Loop

`ContextPack + Information Gain → Blueprint → Blueprint QA → Draft → Fact QA + Editorial QA + SEO QA + UX/media QA → critic findings → revise blueprint/draft or request more research → repeat`.

A real provider-backed evaluator must generate and verify evidence, not simply accept caller-supplied empty `hard_blockers`. Autonomous self-review is valid when quality checks are real; do not require independent reviewer by default. Keep a versioned typed Artifact DAG with max iterations/budget and an immutable final QA receipt tied to the exact draft version.

### L5: Rights, Version Promotion & Company Release Loop

`final QA receipt → source/field lineage and rights metadata → company policy (display/publish/distribute) → canonical Content Hub version promotion CAS → release scope/version → consumer eligibility`.

The company can allow external content and images with `license_state=not_verified`; that state is informational, not an automatic veto. It is not a licence claim. A customer-selected stricter policy may apply. QA, explicit publish intent, WordPress capability, correct tenant and target schema remain independent requirements. Store the released snapshot and do not overwrite unapproved source evidence.

### L6: Company Transport / JetEngine / WPML / Pricing Loop

`Approved Hub version → consumer manifest → source/target schema discovery → CPT/CCT/Meta/Taxonomy/WPML field mapping → Signed REST or local binding → native JetEngine Listing/Query or authorized local draft → target readback → reconcile/drift-repair`.

Local first (current Staging), then remote P256-connected companies. Maintain source company vs consuming company separately. Consume starting prices and each product Rate from Pricing Source Registry; apply per-company overrides, markets, nationality/residence, season, dates, programme, inventory and rate generator without mutating source prices. Keep ETags, delivery cursor, remote target IDs and rollback receipts. A signed reference connection does not prove a deployed remote consumer or a successful write.

### L7: Site Experience, SEO Graph & Post-Publish Optimization Loop

`Live result → Indexability + structured data + Core Web Vitals + REST/JetSmartFilters coverage → SEO graph edges among tour, blog, destination, taxonomy, filter combination, itinerary programme and tour-guide-language pages → analytics/search/conversion/price freshness → gap signals → L1/L2/L3/L4/L6`.

Generate indexable filtered pages only for material, distinct, non-empty search intent; link them meaningfully from parent topic and destination pages. Include day-of-week, time-of-day, season, city, cruise programme, guide language, market and nationality when supported. Prevent near-duplicate/noindex conflicts. Reopen only impacted entities, fields or sections rather than re-running the entire workflow.

## Verified Staging reality and gaps

| Surface | Verified | Gap |
|---|---|---|
| Control → Firecrawl → Hub | One idempotent run, one canonical record + version; real provider call | Hardcoded owned homepage and English; not dynamic research graph |
| ContentJob lifecycle | Seven jobs; target job `14d65a58-73f2-4f1c-8512-c9fb652317a0` is RUNNING/SCRAPING, revision 3, three events | Hub run is persisted but zero Artifacts attached to that job and no stage advancement |
| Research service | Keyword, SERP, competitor, scrape, five coverage matrices and Information Gain Abilities exist | Not invoked by Control-to-Hub ingestion; no automatic bounded iterative planner |
| Skills | Registry has 35/34 enabled; general Skill Runtime reports ready | Researcher/writer/critic/reviewer route selects no runnable skill; `external_client_snapshot_verified=false` |
| Content Intelligence | Typed ContextPack, Blueprint, Draft, fact/editorial/SEO QA and Final QA contracts exist | No provider-backed generation/evaluation loop or Hub promotion consumer; zero artifacts for ingestion job |
| Canonical data | Basic record, version, run and hashes/provenance persist | Record key is per source URL/locale, not durable cross-provider entity identity; no per-field dedup or conflict history |
| Rights | Company responsibility `usage-rights.v2` is loaded on Staging; distribution allowed without verified licence when QA passes | Staging `display` and `publish` still return `purpose_unknown`; GitHub has newer un-deployed version |
| JetEngine | One local active Wo Vacation endpoint, ID 390; candidates held | Only 7 flat fields; no canonical article/tour/itinerary/programme/guide language/meta/price/delivery state |
| Remote companies | Three existing P256 signed reference connections discovered | Zero active remote consumers; remote activation explicitly returns connector-required |
| Pricing | Starting price + Rate and company repricing modules exist | Consumer Feed is reference-only with `pricing_overridden=false`, not connected to rate resolver |
| Feedback/SEO | Existing site content completeness and other stand-alone runtimes | No unified post-publish outcome → Hub change or research loop |
| GitHub | PR #650 is Draft and based on `fix/content-native-principal-planning-and-execution-20261029` | CI not fully green; confirm exact VPS/source parity before merge |

Other observed risks: `source_company` in local consumer Feed currently uses the consumer company ID rather than the source company; local feed query scans a maximum 500 records before filtering and paginating; legacy `Research Feed` still offers a draft-first route; the discovery planner still reports `explicit_owner_approval` as pending even though default product policy is autonomous. ContentJob stage changes are possible with unchanged RUNNING state and optimistic revision; the missing transition is an orchestration omission, not an inherent state-machine block.

## Recommended implementation order (Staging only)

1. **Close L0/L2 ↔ job/artifact continuity:** event-driven bounded Orchestrator Worker with task graph, outbox/leases, provider request records, source artifact append and reliable CAS stage transitions; reconcile the already persisted ingestion and handle retries without re-scraping.
2. **Connect real research and writer/critic skills:** dynamic routing, model/provider invocations, coverage/information gain and bounded return edges to research and draft.
3. **Introduce canonical multi-entity/field-level provenance merge:** separate products, destinations, itinerary programmes, locations, language, media, rates and company overrides; audit conflicting values and readback.
4. **Complete QA and company-controlled content release:** exact Artifact DAG, quality evaluation and canonical promotion. Fix Staging `display/publish` policy drift, with no extra independent reviewer requirement.
5. **Complete JetEngine consumers and price mapping:** current local endpoint first, remote schema/site discovery next, signed REST receiver/reader/ack, company price/rate overlay, WPML and target readback.
6. **Close post-publish SEO and performance loops** with analytics/search/filter/internal-link feedback and incremental updates.
7. **Test all edges:** cancellation, duplicate request, timeout, stale source, schema drift, rejected provider, unauthorized principal, cross-company isolation, stale QA, unlicensed-but-company-permitted use, rollback, and exactly-once-ish idempotent delivery.

### Non-goals and separation

Do not use GitHub as the runtime content database. Do not copy a remote site's entire database to distribute products. Do not publish from raw Firecrawl evidence; first create an eligible canonical content version. Source reference permission is not equivalent to legal publication rights or technical writer permission. Do not require external provider certificates or owner signatures as default application gates. Do not mutate Production during this audit.
