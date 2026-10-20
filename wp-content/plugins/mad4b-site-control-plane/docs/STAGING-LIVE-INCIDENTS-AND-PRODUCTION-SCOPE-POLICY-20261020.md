# ETG / ARE live evidence, fail-closed remediation and Production scope-policy boundary

**20 October 2026 | PR #443 | CI-only candidate; no live deployment, no permissions, no Production enrollment.**

## Evidence and fixes

| Evidence | ETG Staging | ARE Staging | Source outcome | Remaining live acceptance |
|---|---|---|---|---|
| Installed MAD4B | `0.4.0-rc.102` source `1fed486708a475edc72aea67482d4ec970dcf41f` | Same source | PR #443 change is **not installed** | Certify exact PR head, deploy Staging independently and re-read provenance |
| Paired same-origin probe | Homepage HTTP 500; REST root HTTP 200 | Same | The internal signed synthetic request can itself crash at frontend diagnostic line 47 before `wp_salt()` exists. Replace it with a purpose-scoped HMAC derived from strong configured `AUTH_KEY/AUTH_SALT`; reject weak/missing secrets without sending an unsigned probe | Post-deploy signed/ordinary, header-preserved/header-stripped paired requests; external browser and public HTTP independently |
| Native fatal recorder | 7 `E_ERROR` frontend incidents, MAD4B first-party frontend probe line 47 | 3; same first-party probe line on last two | Sufficient to identify the synthetic-probe failure path, **not** proof that all visitor-facing 500 responses have that cause | Correlate post-deploy incident fingerprints and browser symptoms without publishing raw logs |
| Diagnostic MCP `-32603` / opaque error | Some read wrappers intermittently fail; session-safe full diagnostics succeeded in later serial attempts | Some wrappers intermittently fail; serial full diagnostics returned `subject_not_ready` correctly | An opaque tool exception has no trustworthy source file/line yet. Never assume a timeout or client transport failure means a target mutation failed | Pin runtime generation and schema, serialize per site, collect sanitized failure receipts and direct-vs-wrapper parity before source repair |
| Staging governed Write | Current candidate matched and Write Runtime Certification `ready=true`; 101/101 exact grants | Candidate source still old, one grant missing; write not effective | No permission remediation in this PR. No implicit renewal from an old SHA or owner approval | ARE exact new owner plan + OAuth step-up + independent readback. ETG isolated post-draft/publish/revert acceptance remains separate |
| Provider closure | 31 gated abilities; 29 actionable, two not applicable | 31 gated; 26 actionable, five not applicable | Versioned provider attestation, rollback and canary certification remain independent | Functional source + AJAX + browser + WPML parity; do not widen all-provider grants |
| ETG IndexFlow/DFSB | Safe Boot OK; tours profile disabled, publication inactive | Not applicable | SEO publication intentionally off until provider/result-count/Elementor/WPML acceptance | Validate filters, AJAX result parity, canonical/hreflang and sitemap by external browser before publication |
| Skills | Seed v13 Runtime Certification `ready=true` | Same | No Skills reconciliation required merely for this incident | Test exact new candidate and independent readback |

## Production automatic reconciliation — proposed *separate* authority contract

This document is an **unimplemented governance design**, not a grant, activation, or claim of Production completeness. The existing PR #443 Production implementation is a bounded exact-operation batch; it does **not** authorize general future unknown SHA changes.

- **Observations and readiness recalculation**: schedule read-only, without mutation, under Production's read lane. Recompute diagnostic baselines and report findings.
- **Derived/cache state**: consider a dedicated narrow idempotent allowlist with explicit namespace, TTL, rate limit, impact budget, CAS and signed auditable readback. Reject invalidations affecting authorization, published content, all-sites/global caches or external services unless separately approved.
- **Recurring operational repair**: optional owner-signed, environment/site-bound **scope lease**, valid only for exact operation families, resource selectors, maximum per-run changes, maximum cumulative budget, finite expiry, no policy/issuer drift, and verified rollback/readback. A source SHA changing *by itself* would not demand a new owner prompt only when immutable authority-bearing scope AND independently certified behavior continue matching; changed code or artifact bytes must still pass trusted release certification, constraints, revocation and kill switches. This is **not** a blanket future-SHA bypass.
- **Publish, plugin installs, schema, permissions, identity, secrets and Production promotion**: retain a distinct exact-plan, explicitly owner-authorized transaction with signed tests and staged rollout. No standing-scope lease authorizes these.
- All leases must fail closed if site/origin/environment changes, authorization policy changes, test certificate expires, actor/OAuth binding changes, readback conflicts, audit storage is not ready, or the rollback fence is absent. Missing evidence produces **REVIEW_REQUIRED**, never a synthetic PASS.
- A development PR merging cannot turn on Production reconciliation, silently enlarge grants, release an installed plugin or mint owner authorization.

## PR and post-deploy acceptance

1. Run the exact new `frontend-origin-early-marker-runtime.php` in separate PHP subprocesses for `valid`, `weak`, `missing` modes on PHP 7.4/8.1/8.3/8.4/8.5. Assert no `wp_salt`, signed header/query windows, expiry, forgery/malformed rejection and no unsigned fallback. The governance runner `frontend-origin-early-marker-contract.py` is standalone.
2. Keep PR #443 draft until its actual-head CI is green. Current known existing failures include predictive dependency `staging-write-authority-contract.py`, release-set integration byte fingerprint drift, Feature 007 G6 source-fingerprint drift and the missing exact-head owner Release Verdict; do not relax gates.
3. Staging deploy requires certified artifact, canary, package/version identity and rollback plan. Re-run same-origin paired probe and independent public browser. A synthetic loopback success is not a browser acceptance certificate.
4. Diagnose remaining `-32603` with exact transport/schema-generation evidence; do not turn it into automatic mutation retry. Inspect a target by idempotency/audit readback after any transport uncertainty.
5. Reconcile ARE write only through its current exact plan and separate owner approval. No Developer/Breakglass/Production authority.
6. Independently test ETG native tours CPT and sequential Draft→Publish→readback→Draft only with explicit target authorization, while DFSB SEO publication remains disabled until content/filter SEO checks pass.

**Never assert that Production is automated, that the public site is fixed, or that a Release Candidate is certified solely from this PR or local tests.**
