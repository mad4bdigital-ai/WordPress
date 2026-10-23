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
