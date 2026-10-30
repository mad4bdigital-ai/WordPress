# Control MCP → Canonical Content Hub → JetEngine consumers

Staging implementation: 2026-10-30. Central host: `tours.dev.mad4b.com`. No Production changes.

## Source-of-truth and sequencing

The initial run starts from WordPress Ability / Control MCP tool
`mad4b/control-research-to-hub`. It creates one scoped ContentJob, executes
an owned-source Firecrawl observation, links a Research Group/Record, writes a
**research candidate** to three central SQL tables and reads back the immutable
version and provenance.

The Research Feed's legacy direct WordPress Draft path is *not* the new
workflow. No WordPress post, WooCommerce product, JetEngine CCT or
translation is created by ingestion.

Canonical storage tables:
- `wp_mad4b_hub_content_records` — stable per-source/per-brand/per-language identity, revision and current version.
- `wp_mad4b_hub_content_versions` — immutable content normalization, source hash, provider, ContentJob and provenance.
- `wp_mad4b_hub_ingestion_runs` — idempotency key and job/record/recovery state.

These are **inside the central WordPress Staging database**. They are the first
version of the Content Hub Database, not an external DB product.

## Verified E2E acceptance

With idempotency key `owned-homepage-control-hub-e2e-20261030-v1`:
- ContentJob: `14d65a58-73f2-4f1c-8512-c9fb652317a0`
- Record key: `4843b0c9d58e03bd0c72df39562a4bf678a082f4b2dee29d90dd1f021807b2d5`
- Version key: `1821e57417a73a05964ea831f61f64a730bc1465acb105fd85c701be106a890b`
- Run status: `persisted`.
- Readback: 1 record, 1 version, 1 run.
- Retrying the same key produced no duplicate rows.
- No WordPress Draft created; no public distribution/publishing.

The `START TRANSACTION` and `COMMIT` checks were fixed to distinguish
`false` from WordPress DB's valid success response `0`.

## JetEngine multi-company consumption target

### Local company (central host also runs JetEngine)

The central host may itself be a consumer. Use a **local in-process consumer
binding** that reads the central Hub store with the same company/brand
configuration and JetEngine field maps. Prevent circular re-ingestion of its own
rendered output and accidental direct publication of Research Candidates.

### Remote companies (other WordPress sites)

Existing `MAD4B_SCP_Hub_JetEngine_Consumer_Governance`, JetEngine REST Query,
REST API Listings and `MAD4B_JetEngine_Hub_Addon` are the integration surfaces.
Use scoped, authenticated and server-verified REST APIs, with one selected
configuration per destination company.

Each company configuration should declare:
- Exact company/site/tenant identity and endpoint; available content types.
- CPT/CCT destination mapping by semantic field names (not source-local IDs).
- Taxonomy and parent mappings with WPML language-group reconciliation.
- Content version, translation source language and idempotent delivery cursor.
- Brand/media ownership and explicit media reuse rules.
- Starting price / Rate IDs and validity, company repricing, dynamic pricing
  and rate-generator policies where applicable.
- Output mode: **remote reference through existing JetEngine REST Query/Listing**
  or a separately authorized local draft/update when the company needs its
  own editable post.
- Rollback/reconciliation and query-cache invalidation on real changes.

Never infer local term IDs, WordPress post IDs or CCT IDs from a foreign site.
Do not overwrite local brand, media, existing listings or checkout prices.

### Automatic readiness by matching schema

If field/term mapping and required JetEngine dependencies match the declared
company schema, the intended default is automatic consumer activation under
existing WordPress capabilities. Use a separate reconciliation/repair
engine only for schema drift, missing translations, incompatible types or
unavailable provider APIs. No additional owner signature or independent
Writer approval is a *default* application requirement.

## Quality boundary

The existing candidate is `research_candidate` and its QA status is
`not_evaluated`. It is **not** eligible for company distribution.

Next pipeline:
`Coverage / Competitor / Gap analysis → Blueprint → Draft Artifact → Fact,
Editorial and SEO QA → Canonical approved content version → Company
selection → JetEngine transport → target readback → monitoring / feedback /
refresh loop`.

Autonomous review is the default; hard factual/rights blockers still block
distribution. Publication remains separate from storage or query eligibility.

## Current integration blockers

- JetEngine REST API Listings is enabled and the Hub Add-on and Discovery
  Planner are loaded, **but** the Hub Consumer Governance reports
  `planning_only` / admission `deny`. The internal JetEngine MCP client
  reports `provider_native_transport_unavailable` at last check.
- Current addon source mapping is specialized for
  `tourism-almallahgroup` Nile categories, not generic source records.
- Existing remote consumer plan rejects equal source/consumer Site UUID;
  central same-site consumption needs the explicit local binding, not a
  fake second identity.
- Company-specific API distribution and JetEngine Listing/Query consumption
  have not been executed End-to-End in this stage.
- Authoring/AI QA and the canonical promotion/distribution endpoints need
  further integration. Do not represent Research ingestion as finished
  content production.

## Operations and safety

Staging only. Changes tracked in draft PR #650. No mutation of Production.
Secrets are not committed or returned. Real external-site writes are
conditional on existing WordPress credentials and explicit field mappings.
