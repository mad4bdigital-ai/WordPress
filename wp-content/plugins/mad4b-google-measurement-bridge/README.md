# MAD4B Google Measurement Bridge — GA4 + GTM

## Status — source implementation, NOT live activated
This is a **draft** integration stacked on WordPress PR #479; no deployment, consent exchange, Analytics change, GTM change or publication has occurred. Existing PR #479 Local Client behavior is not activated by this bridge.

## Implemented
- First-party Google Workspace **Brand Authority** grants catalogue extended with **opt-in, default-OFF** Google Analytics and Tag Manager read/edit scopes. GTM publish scope is a distinct explicit choice, excluded from the full-suite default.
- Existing Google OAuth callback remains the consent route. Measurement access is allowed only when the precise scopes were selected and actually granted, the stored encrypted grant hash matches the selection, the exact enrolled WordPress origin/UUID/profile matches, the Google grant is not revoked, and the current operator is the WordPress owner who completed OAuth consent. Older grants require explicit **owner reconsent**; Drive alone is insufficient.
- In-process token injection filter: `mad4b_google_measurement_broker_grant`, returning the token only to the allowlisted API client, never to WordPress Abilities or JSON responses.
- Read-only Google REST API abilities: GA4 accounts, properties, custom dimensions, key events, 28-day event and traffic-source reports; GTM accounts, containers, workspaces, workspace status, live version, tags, triggers, variables. Resource-specific requests require the exact Site Profile binding.
- WordPress Tools → Google Measurement: nonce-protected binding of a GA4 `properties/<id>` and GTM `accounts/<id>/containers/<id>` to site UUID and profile digest. This records a selection, **not verification of remote ownership**.
- Read-only GTM4WP audit: public container ID (if available), potential competing tracking plugins and an explicit ownership policy. The audit does not claim duplicates without Tag Assistant evidence.
- Exact-plan, **Staging-only** GA4 custom-dimension and GTM workspace creation code: plan SHA binds actor, Google encrypted grant identity, selected resources and exact site profile; only approved writes could run.
- CI workflow `Measurement Bridge Lint` checks bridge PHP syntax and the altered Brand Authority Google OAuth class.

## WordPress Abilities
- `mad4b/google-measurement-inventory` — site connection, binding and GTM4WP hints
- `mad4b/google-measurement-plan` — required scopes and handoff steps
- `mad4b/google-measurement-api-read` — exact, allowlisted GA4/GTM REST reads
- `mad4b/google-measurement-write-plan` — source/site/token-bound dry-run
- `mad4b/google-measurement-write-apply` — Staging-only, **fail-closed**

### Deliberate write block
A reviewed MAD4B one-time-ticket authorizer still needs to implement the callback `mad4b_google_measurement_exact_write_authorized`. Until it does, all Google mutation operations are rejected. Creating a WP plugin file does not grant external Google API authorization. No GTM publish operation is enabled: GTM publication can affect a live site even from a Staging WordPress connection and needs a separately verified target and authorization.

## Owner activation prerequisites
1. Finish code/CI review and deploy only to **ARE Staging** after approval.
2. Open the existing MAD4B Google Workspace/Drive connection, enable Analytics and Tag Manager **read** grants explicitly and complete Google owner reconsent. Separate edit scope selection requires a new approval. Do not disclose access/refresh tokens.
3. Verify GA4/GTM accounts returned by the authenticated APIs, then select exactly one property/container under WordPress Tools → Google Measurement.
4. Use read inventory to compare GTM4WP bootstrap/events and active existing tags, then generate a workspace plan and explicit approval, with readback before any future publication.
5. Test GTM Preview, GA4 DebugView, Consent Mode and browser/CAPI deduplication separately.

## Safeguards
- Default OFF scopes; no automatic Drive-to-Analytics grant expansion
- Stored Google tokens remain encrypted using the existing site-bound mechanism
- No arbitrary Google API request URLs or caller tokens accepted
- Read requests use strict resource path validation and bounded responses
- Production remote writes forbidden; Staging writes also blocked until exact approved ticket integration
- No WooCommerce/Lead event-source duplication or Google container snippet injected by the bridge
- No personal form values or conversation text in the data layer, API or audit

## Remaining before claiming end-to-end completion
- Live Google owner consent and scope selection; site connection currently requires token refresh
- Independent Google API readbacks, actual property/container ownership verification
- Exact one-time-ticket approval adapter for Staging draft mutations
- GTM entity edit/version diff and independently authorized publish/rollback lanes
- GA4 report/custom definition and GTM4WP live acceptance tests
- Review and acceptance of the stacked PR before merge/deployment

## Audience Listing (GA4)
- `mad4b/google-measurement-api-read` with `action=ga4_audiences` and the bound `resource_id=properties/<id>` calls `GET https://analyticsadmin.googleapis.com/v1alpha/properties/<id>/audiences`.
- Supports bounded pages through `page_token`. Lists audience resources and their supported definitions, **not individual audience members**. Some pre-existing/default audiences do not expose filter definitions.
- Read scope: `analytics.readonly`; no audience create/edit/archive operations are enabled.

## Custom Reporting
- `mad4b/google-measurement-report-catalog`: available fields, saved definitions, four default presets.
- `mad4b/google-measurement-report-run`: allowlisted custom dimensions (max 5), metrics (max 5), period (1–365 days), up to 500 rows per request, optional exact event filter. The selected GA4 property is taken exclusively from exact site binding, never arbitrary user input.
- Presets: `tour_funnel`, `acquisition`, `tour_products`, `returning_visitors`.
- Saved report definitions: WordPress Tools → Google Measurement, owner-only nonce-protected form; definitions isolated by site UUID and selected GA4 property. Use `saved_report` to execute a stored definition.
- These are Data API `runReport` queries. They **do not automatically create reports in the GA4 Reports UI**, GA4 explorations, audience memberships, or Google Ads audiences.
- Unsupported dimensions/metrics, insufficient OAuth scopes, property changes, and invalid requests fail closed. The provider may reject metrics not compatible with the chosen dimensions; such errors are surfaced as non-sensitive status codes.

## Audience/Report QA
- Reconsent to GA4 `analytics.readonly` before testing.
- Confirm exact property binding and compare returned audience identifiers to GA4 Admin → Audiences.
- Run preset `tour_funnel`; reconcile event names and counts against GA4 UI with matching date ranges/time zone.
- Verify Custom Reporting report definitions survive a normal WordPress request, remain isolated when property binding switches, and never return personal user-level exports.
- GA4 query quota, API limitations and reporting thresholds remain in effect.

## Advanced Measurement Operations (this changeset)
- `mad4b/google-measurement-advanced-read`: GA4 metadata, realtime events, dimension/metric compatibility, batch and pivot reports, aggregate tracking quality check, audience listing, GTM workspace/status and live-version inspection.
- `mad4b/google-measurement-operation-plan`: **plans only** for audience create/update/archive, GTM tag/trigger/variable operations, workspace versions, publish/rollback, Custom Dimension sync, scheduling, anomaly rules and conversion reconciliation. It does not execute these operations; source code and Google one-time consent do not authorize writes.
- `mad4b/google-measurement-rankmath-audit`: read-only, redacted Rank Math plugin state and public Search Console property hints (where options expose them), without Rank Math OAuth token inspection or reuse.
- GA4 tracking-quality read uses aggregated 7-day event counts. Missing/zero events are flags, not proof of browser or CAPI deduplication. A complete event payload audit still needs Tag Assistant and consent-aware client evidence.
- Rank Math has its **own Google connection** and may expose Search Console configuration; those permissions do not automatically grant Brand Authority access. Google Search Console API requires a separate reviewed `webmasters.readonly` grant.
- The advanced reporting and audience operations remain source-only pending owner OAuth and exact property binding, Staging deployment, and readback.

## Scope limits
This release implements read-only APIs and fail-closed plans. It does **not** yet implement executable audience mutation, GTM tag CRUD, GTM version publish/rollback, WP-Cron report delivery, anomaly-alert delivery, Meta/Google Ads/CRM reconciliation, or Search Console API calls. Those remain explicit follow-up gates and must not be reported as completed.

## Measurement vNext — incremental delivery on PR #481
### Implemented and PHP-linted
- GA4 Metadata, Realtime, compatible field checks, Batch/Pivot, aggregated event quality audit
- Audience definition listing (not member export), owner-bound account selection
- GTM live-version and workspace status reads; human-readable workspace change inventory
- Anomaly comparisons over two consecutive 7-day periods and a canonical funnel-event presence audit
- Fail-closed **planning only** for GA4 audience create/update/archive, GTM tag/trigger/variable edits, version publication/rollback, Custom Dimension sync, recurring report scheduling, anomaly alerts and conversion reconciliation
- Independent Search Console read OAuth grant (default OFF), authorized Search Console property inventory, redacted Rank Math status/hints
- Live ARE Staging plugin inventory confirms Rank Math SEO, Rank Math SEO PRO and GTM4WP are active. Their Google external connection state is **not** verified by plugin activation.

### Required closure before actual live management
- Consent and authorized GA4/GTM/Search Console account readbacks; select exact resources under the verified site
- Finish one-time MAD4B write-ticket claim/finalization and implement reviewed Google mutation executors, API revisions/diff and readback
- GTM publish/rollback safety boundary independent of Staging vs Production
- Schedule delivery and anomaly alerts via dedicated service identity/authority (not an implicit cron token escalation)
- Independently verify Rank Math Search Console selection, permission and API access; Rank Math's own tokens are never reused
- Consent, GTM4WP browser events, GA4 reporting compatibility, Meta/Ads/CAPI conversion dedup and CRM acceptance tests

**Do not interpret plans as completed writes.** No live deployment, external Google modification or Search Console reconsent was performed.

## Google Ads (OAuth-only, no Developer Token) — source implementation
- Brand Authority Google Workspace grant catalog: `google_ads` is **default OFF**, with explicit Google user consent for `https://www.googleapis.com/auth/adwords`. Effective API permissions are controlled by the connected user's Google Ads account rights and Google Cloud OAuth project's Ads API access tier.
- API version: Google Ads REST **v25**; `GET /customers:listAccessibleCustomers` and allowlisted `POST /customers/{id}/googleAds:searchStream`. No `developer-token` header is sent, per Google developer-token sunset on September 9, 2026.
- Google Ads Customer ID and optional MCC login customer ID are bound to the exact MAD4B WordPress Site Profile via Tools → Google Measurement. Setting the ID is not verification of Google permission.
- Read-only ability `mad4b/google-ads-read` supports account discovery and bounded reports: customer profile, campaigns, ad groups, keywords, search terms, budgets, conversion actions, audiences, and last-30-day campaign performance. Queries are fixed allowlisted GAQL; no arbitrary GAQL or user-supplied request URL.
- `mad4b/google-ads-operation-plan` supports planning campaign, budget, ad group, keyword, negative keyword, ad asset, audience, conversion action and recommendation changes. **It does not write to Google** and has no execution callback.
- Google Ads API access must be verified via the OAuth project's Google Cloud Google Ads API Overview and actual account readback. HTTP failures are returned without exposing provider credential or raw error payload.

### Open before full Google Ads CRUD
- Owner Google OAuth reconsent and Cloud-project API production access readback.
- Account/MCC roles verification; validated GAQL field compatibility against actual v25 API.
- Add reviewed mutate executors using Google validate-only/dry-run, full resource snapshots, exact owner one-time tickets, budget approval, readbacks and immutable audit receipts.
- Verification of Google Ads conversions vs GA4/Meta and GTM4WP duplication/consent.
- Production campaign mutations and spend changes remain disallowed until separately approved.

**No account connection, campaign change, Ads budget mutation or GTM publication was performed in this source-only branch.**

## Keyword Research / Keyword Planner
New WordPress Ability: `mad4b/google-ads-keyword-research`.

Two supported, read-only operations:
- `action=ideas`: seed up to 20 phrases, an HTTPS page URL, or both. GA keyword ideas with historical search metrics and pagination.
- `action=historical`: up to 1000 explicitly provided keyword phrases, analyzed for monthly search volume trends, Google Ads competition and top-of-page bid range.

Inputs: `keywords`, `page_url` (ideas only), `language_id` (Google criterion ID), `geo_target_ids` (max 10, Google criterion IDs), `network` (Search or Search+Partners), `page_size` (max 200) and `page_token`.
Endpoint: Google Ads REST v26 KeywordPlanIdeaService, using the connected operator's `adwords` grant and exact Google Ads Customer binding. No developer token header and no campaign mutation.

Results cache (6 hours) is partitioned by Site UUID, operator and Google Ads Customer + MCC + targeting. The OAuth grant is checked before a cache hit is returned. These are estimates from Google Ads, **not** organic SEO keyword difficulty, guaranteed CPC, or current live traffic.
For SEO priorities, combine query data from Google Search Console, page-level organic performance and actual search intent; never equate Google Ads competition with organic ranking competition.

Future research extension gates: ad group themes and forecasts (request schemas require independent review), search intent classification, keyword clustering, GSC cannibalization, competitor URL seed analysis, multi-country opportunity scoring and governed publishing of campaigns/content plans. None of these is claimed as active live Google integration.

## Keyword Intelligence + Organic Opportunities + Forecast (vNext)
Three new read-only WordPress Abilities:
1. `mad4b/keyword-intelligence-plan` — accept up to 500 structured keyword rows from Ads/GSC, deduplicate and cluster by shared normalized Arabic/English tokens, provide heuristic intent, provisional opportunity scores (based on available volumes and GSC observations, **not SEO difficulty**), and draft editorial briefs. No campaign or WordPress content is published.
2. `mad4b/keyword-gsc-opportunities` — bounded Search Console Search Analytics `query` (query, page, date, country, device), 1–90 days, top 1000 rows, based on an explicit URL-prefix or domain property within the enrolled Site Profile host. Requires new owner consent to `webmasters.readonly`; never borrows Rank Math credentials. Rows can omit low-frequency queries due to GSC privacy/top-row limitations.
3. `mad4b/google-keyword-forecast` — inline Google Ads API v26 KeywordPlanIdeaService forecast simulation for up to 50 keywords, match type, geographic/language targeting and simulated CPC/budget values. No Google campaign, ad group or budget is created. The API response is an estimate sensitive to account relevance and bidding strategy, not a performance guarantee.

Recommended workflow:
Keyword Ideas → Historical Metrics → GSC Organic Queries → Intent/Clusters → draft SEO brief or ad-group plan → Forecast → manual approval before publishing. Content briefs are deliberately **not equivalent to researched SERP briefs**: use external SERP evidence and brand editorial QA first.

### Remaining before live acceptance
- Verify Ads Cloud-project API production permission, Google OAuth and GSC scopes, exact Ads customer / GSC property.
- Exercise real v26 KeywordPlanIdeaService forecast request and response schemas; validate languages, locations, account currencies, API quotas and rate limits.
- Validate WordPress Abilities discovery and return envelopes in ARE Staging.
- Improve semantic clustering with SERP evidence, intent models and multilingual synonym dictionaries; simple token overlap currently may over-segment.
- Create governed stored research jobs, configurable scoring models, ad-group CSV/export, WP content-job handoff, and approved automation, which are **not implemented** in this increment.

## Persistent Keyword Research Drafts & Handoff Plans (2026-10-23)
- `mad4b/keyword-projects-read`: list/get drafts scoped to Site Profile UUID + digest + WordPress operator; no public listing.
- `mad4b/keyword-projects-save`: explicit local draft save only with a valid WordPress owner nonce; 25 projects/operator and 500 keywords/project maximum. Admin form in Tools → Google Measurement obtains a nonce and saves without publishing content.
- `mad4b/keyword-handoff-plan`: immutable SHA-256 proposal binding project, keyword snapshot, site UUID and destination (`content_job` or `ads_ad_group`). No external API changes or WP CPT creation. Lists required brand context, SERP/cannibalization checks, approval and Google account prerequisites.
- CI PHP syntax remains only a syntactic gate. Execution/permission testing must run against reviewed ARE Staging runtime after deployment and owner OAuth.
- Not yet implemented: managed SERP crawler, competitor enrichment, semantic clustering, Content Job creation, Ads ad-group creation, recurring job orchestration, exact one-time Google mutation tickets, AI generated editorial briefs verified against sources. Do not interpret handoff plans as executed operations.

## SERP evidence and multilingual normalization increment
- `mad4b/keyword-serp-evidence-analyze`: source-attributed SERP evidence intake requiring a provider label, locale, observation date, HTTPS ranked URLs, titles and optionally supplied intent labels. Validates and caps 20 results, groups competitor domains, produces an evidence SHA-256 and intent distribution.
- **It is not a crawler** and does not assert upstream provider authenticity or execute any external HTTP requests. Domain/intent counts are only as trustworthy as the supplied observations.
- The keyword clustering heuristics now normalize a bounded synonym dictionary for some English travel terms and Arabic plurals. This is not transformer/embedding-based semantic clustering.
- Existing `mad4b/keyword-handoff-plan` still produces review-only Content Job and Ads handoffs. There is no authority to create a content job, campaign or ad group automatically.

### Closure controls
Review real SERP API provider contract and licensing, protect against SSRF if server-side fetches are later added, verify query locale/device/date, deduplicate canonical URLs, compare the site's existing WP content, and require editorial review + MAD4B exact one-time ticket before any write. Production mutation and remote Google writes remain disabled.

## Canonical SERP provider reuse — deduplication correction (2026-10-23)
The existing Feature 007 Search Intelligence runtime is the **only owner of live SERP provider enrollment, credentials, spending authority, transport, capture, snapshot normalization, retries, reconciliation, and evidence provenance**. Its registered abilities include `mad4b/search-discover`, `mad4b/search-capture-plan`, `mad4b/search-capture-apply` and `mad4b/search-reconcile`.

Source of truth:
- `mad4b-site-control-plane/includes/class-mad4b-scp-adaptive-search-intelligence.php`
- `mad4b-site-control-plane/includes/search/adapters/class-mad4b-scp-search-serp-adapters.php`
- `specs/007-content-intelligence-workflow-platform/contracts/adaptive-search-intelligence-runtime.md`

Keyword Research uses the same underlying Search Intelligence evidence or reviewed imported observations; its `mad4b/keyword-serp-evidence-analyze` operation remains read-only analysis of supplied evidence, **not** another provider transport or certification layer.

**Deleted** the redundant direct `DataForSEO` HTTP client and its owner-paid-request nonce UI. No separate WordPress config keys `MAD4B_SERP_PROVIDER_LOGIN`/`MAD4B_SERP_PROVIDER_PASSWORD`, independent quota path, or ad-hoc DataForSEO authentication are introduced by this plugin.

To use live SERP: discover a certified Search Profile/provider in the existing control-plane and follow its budget/capture plan/apply/reconcile contracts. If the provider is missing or ineligible, fail closed; do not try a separate Keyword Research HTTP request. No new live SERP capture is claimed in this PR.

