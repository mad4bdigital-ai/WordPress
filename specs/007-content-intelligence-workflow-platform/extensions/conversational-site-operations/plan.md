# CSO01 — Incremental implementation plan (all phases NOT STARTED)

**Scope:** documentation extension; existing Feature 007 release/freeze unaffected. Each phase must have reviewable output, independent proof and compensating rollout.

## Phase 0 — Discovery/read-only kernel

inventory actual site primitives, define adapter capability provenance, fixtures for unknown plugin and storage. Gate: CSO-G1. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 1 — Typed UI and lifecycle

schema compiler, forms, conditional fields, suggest, previews, localization and approval intent. Gate: CSO-G2. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 2 — Secret handoff isolation

secure origin-bound first-party input, provider-owned credential storage, rotate/revoke, no conversation plaintext. Gate: CSO-G3. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 3 — Governed precise write

plan/commit/verify, exact CAS, operation receipt, grants, authorization and denied target mutation. Gate: CSO-G4. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 4 — Bulk and workflow saga

DAG compiler, triggers, durable checkpoint, canary, backpressure and compensation. Gate: CSO-G5. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 5 — Content/media and reusable templates

certified CPT, meta, taxonomy, relationships, SEO, assets, WPML, templates and provider variants. Gate: CSO-G6. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 6 — Multisite, monitoring and diagnostics

tenant/site isolation, drift watchers, alert dedup, provider Doctor and evidence routing. Gate: CSO-G7. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 7 — Staging/Production governance

signed source/diff package, independent Staging/Browser/Host tests, separate Production approval. Gate: CSO-G8. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.

## Phase 8 — Adversarial A–Z acceptance

real disposable WP/PHP7.4/8.3/MySQL/MariaDB/Browsers Arabic/English, keyboard and fault injection. Gate: CSO-G9. Exit requires exact-head test + signed/owner evidence. Source-only simulation never counts as live acceptance.


### Rollout

- Feature flags default OFF: `cso.read_inventory`, `cso.forms`, `cso.secrets`, `cso.single_write`, `cso.bulk`, `cso.workflow`, `cso.multisite`, `cso.production_proposal`. No flag turns on execution authority.
- Internal canary → disposable WordPress Staging → selected external provider → site-wide Staging. Production has a distinct release signoff and kill-switch.
- Run design validator separately from native tests. CI may be unavailable; native PHP and disposable provider tests still required before any certification.
- Explicit stop/rollback gate for schema drift, permission drift, upstream plugin upgrade, source mismatch, interrupted commit, leaking secret or unverifiable receipt.
