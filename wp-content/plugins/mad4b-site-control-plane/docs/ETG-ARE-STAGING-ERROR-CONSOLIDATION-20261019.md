# ETG + ARE Staging error consolidation — PR #439

Date: 2026-10-19. Scope: **source-only** changes on `fix/session-safe-diagnostic-memory-bound-20261019`; no WordPress site mutation, Production promotion, Developer/Breakglass grant or automatic retry.

## Observed symptoms and root-cause confidence

| Incident | ETG | ARE | Source assessment | Remediation and acceptance |
|---|---|---|---|---|
| `mad4b/session-safe-diagnostics` opaque internal failure | Intermittent | Intermittent | **Confirmed source memory-bound defect**, external transport root not independently proven | Project each provider check *before* sibling accumulation; hash bounded summaries, preserve fail-closed generation and 16KB response cap. Stress at PHP 64MB and verify live identity/triage/full on both sites after release. |
| `mad4b_staging_owner_proof_ambiguous` from exact approved owner plan | Reproduced | Not executed | **Confirmed contract mismatch**: broker schema allows both `owner_intent_sha256` and `owner_review_digest`, while source rejected both even when they bind the same plan | Verify exact-plan review digest and use already signed receipt without re-attest; claim exactly once after journal reservation; reject stale review/receipt, wrong owner/OAuth/site, replay and Production. Tests run in MCP compatibility and operational error suite. |
| `target_error_reconciliation_required` after owner apply | Reproduced | Not executed | Correct fail-closed dispatch envelope for an uncertain target; prior particular cause was preflight proof mismatch | Preserve reconciliation and independent journal/readback; do not blind retry, falsify a successful grant, or mark an error as a completed apply. |
| Write Authority: 100/101 grants, two stale, candidate `1edf2a...` vs installed `48720568...` | Blocked | Blocked | **Confirmed persisted authority drift**, not a PHP source regression | Use one fresh owner-approved, exact-site, exact-release Staging-only convergence *after* new certified package; independently verify current grant inventory, candidate binding and write runtime certification. Do not auto-enable authority as part of installation. |
| Direct ChatGPT step-up tool reports `Tool not found` but governed enrollment catalog discovers equivalent operation | Observed | Not exercised | External MCP projection / registration mismatch; insufficient evidence of its upstream cause | Use only capability-discovered first-party enrollment lane, not an invented direct endpoint. Require actual tools/list and schema refresh integration acceptance. |
| ETG and ARE frontend homepage loopback HTTP 500 vs REST root 200 | 500/200 | 500/200 | **Root cause unknown**; historical PHP fatal on ARE without trusted file/line; ETG incident recorder was empty | Keep issue #432 P0. Obtain sanitized request-correlated host PHP/webserver logs and independent browser acceptance. Never infer root cause from PHP 8.5 deprecated notices, nor call an unrelated plugin fix a solution. |
| Tour publication `tours-and-activities` | Not attempted after failed authority converge | Not requested | WP core create/publish capabilities available, MAD4B exact remote authority blocked | After explicit, current owner approval and certified deploy: create distinctly labeled private/test draft, read back id/type, exercise approved publish, verify permalink in external browser, revert to draft; verify no duplicate after uncertain outcomes. No real bookable offer. |
| Provider version/certification drift (JetEngine, JetSmartFilters, Elementor, Rank Math, LiteSpeed) | Some mutating provider abilities gated | Some mutating provider abilities gated | Correct fail-closed certification state, not interchangeable with core post mutation | Build exact artifact attestation and behavioral rollback canaries before exposing provider write abilities. Do not widen grants or lower certification thresholds. |
| CI: dynamic readiness misclassifies all plugin sources into content | PR #439 | PR #439 | **Confirmed source classifier bug**: second classifier token preserved `wp-content/` | Strip global plugin prefix from both path and candidate; add negative regression proving content, provider and platform distinguish correctly. |
| CI: Feature007 `last_reviewed_master_parent_sha` stale after PR #438 merged | PR #439 | PR #439 | Exact baseline metadata lagged the merged parent | Reviewed ancestry `b9ffd800...` → `9723edd8...` (17 commits; no substantive Feature007 change beyond parent pointer); update baseline parent only. Keep release/Production approval false. |
| Release verdict | Missing exact-head owner attestation | Missing exact-head owner attestation | **Governance checkpoint**, not a functional test | Owner must attest the final head through the authorized GitHub PR mechanism; never fabricate or inherit old SHA approval. |

## Non-negotiable safety constraints

- Normal WordPress permissions and MCP tool discovery do **not** equal current governed write eligibility.
- A signed owner-intent receipt is not effective authority; it is short-lived, single-use, exact-plan and independently audited.
- A preflight before the operation journal must not consume an owner receipt. Any after-dispatch uncertainty requires reconciliation, no immediate replay.
- All new CI records are `reviewed_for_ci_only` with `production_authorized=false`.
- This PR does not deploy itself. No new zip is certified until exact-head CI, owner review and release attestation pass.
- Do not merge the HTTP 500 incident with MCP transport and owner-proof defects solely because they occurred on the same staging sites.

## Independent post-deploy acceptance

1. Confirm full SHA and package fingerprint for the *new* released build on ETG and ARE.
2. Serialize per-site MCP calls; read snapshot, identity/triage/full diagnostics under bounded budgets, 3 repetitions each, check generation and never count skip/partial as pass.
3. Read governed write plan and signed owner review, execute one exact authorized enrollment apply, then verify grant parity, no broad/wildcard grants, current candidate identity and `write-runtime-certification.ready=true`.
4. Approve test content actions per exact target. Verify create / read / publish / external permalink / draft, including idempotent recovery and no production changes.
5. Independently test homepage vs REST root and preserve a request-correlated 500 log, if any; keep P0 open until the actual frontend root cause is fixed.

Evidence references: PR #439, Issue #440, Issue #432, previously merged PR #438.
