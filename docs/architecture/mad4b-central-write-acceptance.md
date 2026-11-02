# MAD4B Central Write Acceptance — verified enrichment mutations

Contract `mad4b.central-write-acceptance.v1`, shared by all enrolled companies and environments. No company/site hardcoding. Staging-first rollout, no Production updates or write grants.

## Single acceptance boundary

For **every automatic enrichment write**, review the existing authorized WordPress Ability/REST endpoint and use `MAD4B_SCP_Central_Write_Acceptance` for common proof checks before its first side effect. The feature's original authorization, evidence collection, native write, and domain readback remain mandatory. The central layer **never** creates an OAuth grant, approval, remote WordPress session, arbitrary WP mutation endpoint or generic remotely callable code executor.

Its `verify(claim, native_source, site_scope)` requires exact bindings: canonical Site Profile UUID and WP environment, reviewed source subject/ability/ContentJob, source SHA-256, source revision, and reference ID (Place ID, supplier ref, translation locale/term, media identity etc.). Reference and source must be **re-read from WordPress source of truth**, not echoed from a request. Evidence requires an independently server-stored SHA-256, timestamp, verified independent source, factual/quality/rights checks, and exact deterministic idempotency key. Both caller claim and separately obtained server record must match for the timestamp and QA/rights proofs. Default max age 72h, max bounded policy 30 days, no future timestamps beyond 60s.

For original permission checking, preserve each registered Ability permission/REST operation permission. Anonymous clients, unauthenticated WP CLI and plain read/discovery cannot create write authority. `route_admission` delegates to central REST write proof and rejects synthetic non-REST invocations.

## Durable application contract (internal trusted PHP adapters only)

An implementation of `MAD4B_Central_Enrichment_Write_Adapter` supplies native `source()`, `authorize()`, `apply()`, and `readback()`. The central `execute()` checks actor/site policy, independently re-reads source and authorization under a MySQL named lock, **atomically claims a stable WordPress option before the side effect**, then calls the reviewed adapter. The immutable claim key is scoped to site, environment, ability, job, subject, source SHA, revision, reference and evidence SHA.

`VERIFIED` stores a receipt hash and minimal result after independent readback, allowing a verified readback-only replay. An uncertain callback exception/failed effect/readback leaves `UNKNOWN` and denies blind retry or alternate-transport replay. `IN_PROGRESS` also remains held until reconciliation. No automatic background queue or paid API invocation. Any provider-specific rollback/side-effect reconciliation belongs to the original domain adapter.

The adapter interface **is not exposed as a generic public write Ability**. Only trusted local first-party integration code may invoke it. A hypothetical malicious WordPress plugin can already execute PHP: this interface does not expand browser or remote user privileges.

## Initial integrations and remaining coverage

| Native feature | Current integration | Additional policy |
|---|---|---|
| Origin Closure / Place ID | `360-mad4b-dynamic-origin-closure.php` calls central `verify()` in eligibility, which `apply()` re-checks before writing | Exact external Place ID, native source tour hash/plan revision, independently verified supplier source, fixed pickup term, actual postmeta readback, alert closure, ContentJob revision |
| WPML Auto Qualification | `360-mad4b-wpml-autoqualification.php` runs central `verify()` immediately before term visibility canary | Native WPML source/translation hashes, localized published tours, current source-bound QA receipt incl. rights and language, HTTP 200, same canonical, mandatory staging noindex, rollback and job readback |
| Other existing enrichment providers (Firecrawl, SEO, media, supplier, taxonomy, analytics, etc.) | **Contract available, not yet proven individually integrated** | Each must bind its original ability and native source/evidence adapter; do not claim a global runtime veto for unmodified writers |

A future project can extend the coverage per-Ability, without changing the generic proof language. Avoid claiming complete enforcement until all native writers are enumerated, updated and acceptance-tested. Do not silently block unrelated WordPress writes lacking the new metadata.

## Staging acceptance

- Common proof negative/positive cases: **26/26 PASS**, includes wrong Place ID, stale/future proof, source hash or revision drift, wrong site/job/ability/subject, changed/faked server-side QA claims, wrong idempotency key, and unauthenticated synthetic REST.
- Durable adapter fixture: **8/8 PASS**: original permission denies, exactly one positive in-memory fixture mutation, verified readback/receipt, idempotent replay does not repeat, readback drift denies, uncertain result remains `UNKNOWN` and blocks retries, all temporary fixture options removed. No tour postmeta or external API changed.
- The *real* `mad4b/location-closure-apply` Ability is registered but executing it under unauthenticated WP-CLI user 0 returned `ability_invalid_permissions` **without mutation**.
- A real open Origin issue is still blocked at eligibility and `writeback_allowed=false` (no fabricated supplier proof).
- WPML plan reports 177 open gaps, zero ready. DFSB 0.4.0-alpha.14-local.6 boot remains unfaulted; SEO publication and managed routes remain off.
- An actual authorized, non-fixture production-like enrichment write across ChatGPT MCP or remote REST still needs a valid source-bound proof plus native readback. No Production sites modified.
