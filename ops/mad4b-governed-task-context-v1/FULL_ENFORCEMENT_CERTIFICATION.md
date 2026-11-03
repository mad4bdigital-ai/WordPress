# Full Enforcement: source-backed certification gates (not yet accepted)

Scope: `tours.dev.mad4b.com` Staging, database `etg_commerce_staging`. Do not infer authority from GitHub merges or generated hashes. Existing runtime remains `certified_only`; no Production mutation.

## Original-source evidence as of 2026-11-01

The original WP-CLI read-only shadow invocation kept the persistent `certified_only` mode. It classified 324 durable historic tasks, only 2 historical records had a valid plan in a hypothetical `enforce` context. **This is a historical plan compatibility test, not a proposal to modify historic data.** The operationally relevant 20 nonterminal tasks were blocked from creating new semantic plans; their existing legacy-hash allowlist remains valid for original claims.

Active classifications: 16 require original-provider source identity reconciliation (`review_canonical_identity` 13, `stage_work` 3); 3 `stage_work` need server-verified origin; 1 `ai_guard_stage` needs live original ContentJob stage readback. Do not rewrite any old payload/origin to make a test green, and do not replay WAITING_EXTERNAL/NEEDS_RECONCILIATION work without independent effect verification.

## Next certified read-only lane candidate: runtime_resolve

The original `MAD4B_Hub_Dynamic_Runtime_Resolver::resolve` has been read on Staging: its actual readback has a source-derived resolution digest, `provider_calls_performed=false`, `mutation_performed=false`, and capability discovery explicitly does not grant execute authority. The new exact-source certificate re-reads the original ContentJob revision/stage and the resolver plan on each claim; it incorporates the resolver digest into the capability certificate. A changed resolver plan, stage, job revision, forbidden direct provider effect, or mutation causes rejection. Existing `workflow_inspect` / `content_job_read` certificate calculations are unchanged.

**This is a source-level candidate only until new exact-HEAD CI, owner attestation, governed Staging package update and original two-process queue canary succeed.** Do not treat the code review as a Staging deployment or full mode permission. No additional provider or publishing lane gains authority from this operation.

## How lanes become independently certified

1. **Source / identity / canonical / inventory / rates**: the original Canonical Registry supplies exact source entity/term/company/gap and authority, the rate/inventory authority supplies current revision and outcome readback, the original provider supplies command/effect receipt, rollback/compensation is scoped, and no stale order/rate can be re-run as a new iteration. The original human review queue remains authoritative for unresolved identity collisions.
2. **AI / generation / media**: existing AI Queue Pipeline and AI Guarded Preflight supply exact ContentJob revision, source/policy/capability/model/prompt digests, budget/grant, provider execution token, signed/verified artifact/result and repeat/replay controls. No inference of publication approval or downstream effect from a generation receipt.
3. **Publishing / WPML / JetEngine / company distribution**: original WordPress operator and editorial approval must bind target site, language, exact content revision, pre-state, apply receipt, post-write independent readback, and exact rollback. Staging source acceptance is not Production authority.
4. **Provider / integrations / unlinked workflows**: original Provider Receipt Bridge supplies exact requested provider record, mapped company/tenant scope, authorized executor, request idempotency and independent provider readback. A client-supplied `workflow_origin`/SHA256 is never an eligible proof.
5. **Existing 20 legacy active tasks**: preserve immutable task, history, stage and lease semantics; reconcile with source provider on a case-by-case basis instead of converting to new leaders automatically.

## Release gating

- Keep `MAD4B_HUB_SEMANTIC_FLIGHT_MODE=certified_only` until **all** operation families have original contract adapters, isolated and two-process Staging E2E, replay/expiry/compensation tests, exact-head CI and owner approvals.
- `tools/mad4b_full_enforcement_shadow_gate.py` evaluates an exact-source read-only evidence document; its output always marks `enable_full_enforce_authorized=false`. It **cannot** be substituted for provider-specific certified grants.
- A genuine `enforce` rollout needs a separate original operator plan with frozen ingress, fresh recovery point, deny/allow matrix, canary and rollback. Never force it by setting an environment variable alone.

Artifacts (not committed, because they are mutable runtime observations) reside at:
`/opt/mad4b-company-transport/artifacts/hub-master-3c68e4f4/FULL-ENFORCEMENT-SHADOW-EVIDENCE-20261101.json`
and `FULL-ENFORCEMENT-VERDICT-20261101.json` in the same directory.
