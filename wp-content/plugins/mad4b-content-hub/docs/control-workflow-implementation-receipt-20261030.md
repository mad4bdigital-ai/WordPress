# Control MCP Nested-Workflow — Staging Execution Receipt

Date: 2026-10-30. Scope: `tours.dev.mad4b.com` Staging only. No Production deployment, published post or remote company mutation.

## Completed

1. **Company usage rights v2 parity:** Staging now supports `display`, `publish` and `distribution` under company policy even when `license_state=not_verified`. PHP lint, runtime decisions and public HTTP 200 passed; Staging file Git blob matched GitHub `484c541a13952edab2c48e7d8172b869609fcb99`. This is not a legal permission determination.
2. **Central ContentJob recovery:** Added `MAD4B_Hub_Workflow_Continuity`, registered Control MCP Abilities `mad4b/control-workflow-status`, `mad4b/control-workflow-resume`, `mad4b/control-workflow-baseline` and REST equivalents. Reuse a persisted Hub run under a per-run lock, check Site/Brand/Job version, append an immutable `scraped_page` evidence pointer, and CAS transition the **same** ContentJob. No rescrape, draft or publication.
3. **First real coverage loop:** The existing recovered ContentJob `14d65a58-73f2-4f1c-8512-c9fb652317a0` is `RUNNING / INFORMATION_GAIN`, with 3 linked Artifacts: `d8a17e66-a4a5-46e5-bc80-844f8a19144b` (source pointer), `d319cf82-5076-483f-80fc-c6eedc1d19e2` (evidence coverage) and `cd31322b-8a3b-4972-8a63-b2547e52db72` (Information Gain). Unknown SERP, competitor and destination facts are marked **not yet observed**, never invented. Next action: `mad4b/research-keyword-record`.
4. **Idempotency:** Retried identical Control MCP request after Hub persisted; reused the same Job and Artifacts, no extra source fetch or event. Standalone Continuity contract PASS with one append, one transition, safe replay, terminal-job refusal.
5. **Dynamic Skills route discovery:** If Market Growth Policy has no assistant role mappings, route researcher/writer/critic/reviewer/recovery by exact enabled Skill Registry names. Tested 5 selections. This is **candidate discovery**, not confirmation that an AI provider has executed a skill.
6. **Canonical Release Gate:** Introduced `mad4b/hub-canonical-release-assess` and `mad4b/hub-canonical-release`. Checks exact source version, same-job Draft, Final QA and three component QA Artifacts, source scope, and company usage policy before an atomic canonical version promotion. It never creates a WordPress post. Negative live test rejected nonexistent QA and preserved the existing `research_candidate` and version. Detailed standalone positive/negative QA contract passed on Staging; only a compact rights/release smoke test was accepted for GitHub CI.
7. **JetEngine Local Consumers:** Activated 3 native JetEngine REST API Listing endpoints for Wo Vacation: 390 (`research_observation`), 391 (`article`) and 392 (`tour`). The existing real research candidate remains held. Synthetic approved Article and Tour fixtures were consumed through native JetEngine/Company Pricing and rolled back, leaving the canonical DB at 1 record, 1 version, 1 run. Article fields now include body, source/consumer company and rights metadata.
8. **Pricing:** Consumer Feed can reference local canonical source post IDs to reuse `MAD4B_Hub_Price_Sources_V1` and `MAD4B_Hub_Company_Pricing_V1`, exposing indicative starting prices and per-product rate entries. Existing published tour post 64850 returned one starting price and no rates; synthetic Feed test passed. Checkout and original prices/rates were not changed.

## Verified final Staging snapshot

- `wp_mad4b_hub_content_records`: 1.
- `wp_mad4b_hub_content_versions`: 1.
- `wp_mad4b_hub_ingestion_runs`: 1.
- Original ContentJob: `RUNNING / INFORMATION_GAIN` with 3 Artifacts.
- JetEngine Consumer Governance: `active_local`, local 3, remote 0.
- Public site: HTTP 200.
- No canonical approved content and no actual remote distribution; existing Research Candidate is not displayed or published.
- Staging-only QA backups and tests located under `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/workflow-continuity-20261030`.

## Outstanding, not certified

- AI provider-backed keyword/SERP/competitor research, real iterative stopping criteria and writer/critic execution; the Skills are discovered but not yet executed.
- End-to-end real Draft → Fact/Editorial/SEO QA → canonical release → JetEngine target readback: contract and negative/fixture tests exist, but no real approved content to exercise the whole chain.
- Canonical entity resolution/per-field provenance across tours, hotels, transport, programmes, guide languages, translations, media and rate ownership.
- Remote JetEngine consumer deployment and exact Site UUID/schema discovery for signed peers; existing P256 reference-sharing connection must not be confused with a deployed runtime. Remote consumers remain 0.
- Rate overlay with actual rate-bearing products, multi-market calendar, rates CCT and real booking quote readback.
- Publish/update/delete/undo and measured SEO/analytics/conversion → Research feedback.
- GitHub PR #650 remains Draft and has pending/failing CI; do not merge or deploy to Production without tested source parity and remediation.

## Release architecture

The orchestrator still owns mutation authority; `delegated_user` is default, NHI is optional. Raw source evidence cannot be promoted without typed content QA. External content/media license status is not an automatic display/publication denial under the company's chosen policy; preserve provenance and do not imply a legal license. All automatic writes are bounded by tenant/site permission and version readback.
