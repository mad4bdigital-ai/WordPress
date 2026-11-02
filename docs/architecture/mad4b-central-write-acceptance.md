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

## First live source-backed positive write/readback — Kom Ombo French (2026-11-02)

On **ETG Staging only**, an authorized WordPress operator (user ID 26) completed a real source-bound native WPML enrichment for existing ContentJob `fe3d871e-ee50-435c-93fb-63bcdb1bf3b7` linked to existing issue `fbe96c19822da33bccd77520554d66e4d4bfa3ad8518ff62a138747445639a63`. Source public English term `2352` (`kom Ombo`) and French parent `130` were verified directly against WordPress/WPML. The translation's geographic and historical facts were corroborated against the Egyptian Ministry of Tourism and Antiquities and the French archaeological institute IFAO. No Place ID or supplier evidence was invented.

The first authorized CWA adapter invocation created **French destination term 2951**, `ville-de-kom-ombo`, WPML `trid=419051`, with the correct parent, native review/provenance meta, and **`is_public=0`**. The linked ContentJob transitioned `QUEUED → RUNNING → WAITING_REVIEW (EDITORIAL_QA)`, revision `4`. The source English term remained `is_public=1`.

First readback was **false-negative**: WordPress's native term insertion stripped `<p>` tags from the supplied translated HTML; the stored plain text is byte-for-byte equal to `wp_strip_all_tags()` of the intended description, but not to the original HTML. The central attempt was correctly held as `UNKNOWN` and was **not replayed**.

Fixed the reviewed adapter's readback to compare normalized native term text. Added general-purpose `MAD4B_SCP_Central_Write_Acceptance::reconcile()` to make **readback-only** recovery of a pre-existing `UNKNOWN/IN_PROGRESS` attempt possible with independent native source, original permission, immutable site/job/evidence bindings and registered provider readback, without invoking `apply()` again. Verified the original attempt, then promoted only its original native receipt to `VERIFIED`:

- Receipt SHA256 `7bd06fbe21729032bc83386902c08c4fdbe6b9f7c241bbad3b0f1aa0ece1372e`.
- Previous attempt status `UNKNOWN`; `reconciled_at=2026-11-02T18:25:40+00:00`; original mutation was not replayed.
- Exact native readback: one French term with `term_id=2951`, WPML `fr`, published English source unchanged, target private, linked ContentJob `WAITING_REVIEW`.
- Updated shared fixture **11/11 PASS** including failed evidence/permission, original one-write success, uncertain attempt no-replay, independent reconciliation, idempotent verified receipt, no re-execution, and fixture cleanup.
- This proves a **real authorized enrichment write, first-party WPML readback, and durable CWA receipt**. It does **not** mean the French page was published or the ContentJob completed. Independent publication QA and the eligible localized public tour requirements remain pending; `is_public=0` is intentional.

The exact reviewed one-off canary script is included at `tools/dfsb-neutral/central-write-real-kom-ombo-fr-staging.php` for source/audit parity. Do **not** run its original write mode again. Any legitimate repeat verification must use readback or the guarded reconciliation-only path. The script is hard-scoped to one existing Staging term/site, never a multi-tenant automatic writer.
