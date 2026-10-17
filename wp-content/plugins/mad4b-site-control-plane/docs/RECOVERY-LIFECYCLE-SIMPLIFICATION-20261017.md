# MAD4B — Generalized recovery lifecycle and low-friction operator review
2026-10-17 | Source-only | stacked on PR #395, PR #396 Draft

## Scope / principle

The existing WordPress Control Plane owns discovery, diagnostic planning, plugin adapters and dedicated governed executors. This increment does **not** add an independent plugin or mutate a site. It converges user-facing decisions into one next read/plan step while preserving exact plan CAS, signed owner intent, OAuth Step-Up, one-time grants/approvals, independent verification, fail-closed rollback and post-update gates. A status response is not a release certificate.

## Reviewed lifecycle

| Step | Existing mechanism | New simplification / validation | Remaining closure evidence |
|---|---|---|---|
| 1. Detect incident | Adaptive Operation Router, browser acceptance | Cross-provider symptom families and provider-neutral hypotheses; no automatic vendor attribution | Live console/AJAX/browser evidence |
| 2. Discover native capabilities | WordPress Abilities, MCP discovery and provider adapters | Root Cause and Operational Remediation explicitly included in read catalog; Native-first routes | Live registered tools and provider schema probe |
| 3. Propose correction | Root Cause and remediation coverage | Generic diagnostic vocabulary for multilingual/forms/SEO vs JS-only language | Version-qualified provider read/write API contracts |
| 4. Site/project/version continuity | Post-Update Continuation and provider capability resolver | Reuse exact existing dynamic tool inventory, avoid plugin version pinning | Real provider update + changed-feature smoke tests |
| 5. Plan and owner review | Admin local owner preview and remote OAuth owner review | Signed persisted receipt displayed after PRG; plan unavailable distinguished from changed plan | Exact same-site local/remote plan field parity |
| 6. Approval | Owner Intent and normal enrolled owner OAuth Step-Up | No redundant owner form after valid intent; advanced OAuth details collapsed | Real fresh-owner Step-Up and exact plan identity |
| 7. Governed execution | Staging Write Authority Convergence | Never infer Write READY from matching grant count alone | Live authoritative candidate binding + Write runtime |
| 8. Uncertain execution outcome | One-time owner claim + append-only audit | Readback-first next step; do not blindly replay receipt or mutation | Audit lineage and new-request remote write status |
| 9. Verify | Native Staging and independent Browser Acceptance | One canonical read-only next step before new approval | ETG + All Royal browser regression and site-specific parity |
| 10. Roll back | Provider-specific reversible contracts and fail-closed preflight | No blanket restore or unverified vendor setting write | Before-state, CAS, audited revert and new browser readback |
| 11. Release | G2–G9, exact-head CI and package identity | Refresh source fingerprint when an existing cataloged source file changes | New head full CI, exact build, both sites staging acceptance |
| 12. Production | Independently governed authority | No shortcut from Staging write or owner attestation | Separate approval and Production readiness |

## Current user-facing contract

`MAD4B_SCP_Operational_Remediation::primary_next_step()` reduces a trusted **already-read** canonical Staging status to one bounded handoff:

- An invalid or incomplete plan forces diagnostic integrity first.
- A blocked gate with an existing readback shows only that readback, to prevent repeating uncertain writes.
- A blocked gate whose writer exists presents exact read-only preparation, never the writer itself.
- A missing provider route prompts discovery of a native ability or existing adapter, not creation of a standalone plugin.
- No blocked gate but unverified Staging readiness stays on Staging verification.
- Verified Staging without browser acceptance prompts separate browser evidence.
- Zero visible gate defects does not claim an approved release.

The admin page renders the one recommended next step **above** its detailed gates. Existing detailed workflows remain accessible. No frontend mutation, timers, automated retry or fake success notice was added.

## Specific issues addressed from previous rounds

1. `wp is not defined`: LiteSpeed may be one hypothesis; browser differential and isolated provider proof still necessary.
2. LiteSpeed settings: official bounded getter uses `litespeed_conf` where runtime supports it; vendor write hooks do not satisfy a new reversible mutation contract automatically.
3. Elementor, JetEngine, JetSmartFilters, WPML, Rank Math, Fluent Forms: dynamic provider catalog, domain-specific diagnosis and fail-closed new write grants.
4. `102/102` or `103/102`: inventory and grants are not enough; verify exact tool source, binding, current gates and authorization.
5. WordPress Owner Review static reload: signed persisted current/claimed/expired/plan-change state and 303 PRG, with no second submission in the successful state.
6. Historical Admin vs OAuth digest mismatch: one shared hash implementation, two plan readers. Compare the actual signed exact fields rather than attempting to force hashes equal.
7. Outcome-unknown MCP apply: never blind-retry a one-time claim. Read fresh status, candidate binding and audit first.
8. Provider upgrade compatibility: zero-authority-delta read continuity is different from extending mutation grants; recertification and owner review remain mandatory.
9. Catalog discoverability: Root Cause and Operational Remediation now appear explicitly in the **read server** inventory; action executors stay governed independently.
10. Source integrity: existing bound source changes require corresponding exact record refresh (G2); no CI failure is to be hidden or treated as a runtime pass.

## Acceptance matrix: known vs not verified

- **Source verified locally:** primary-next-step negative paths; cross-provider diagnostics; owner intent and OAuth mismatch fixtures; read catalog/source contracts; PHP 7.4/8.3 lint and hermetic runtime; G6 and G9 delivery/fingerprint checks.
- **Not independently verified:** browser E2E acceptance; real provider-native tool settings mutation and rollback; site-level readback after applying a new package; OAuth Step-Up for a live owner; app access to newly mounted read tools; Production.
- **Release gate:** PR #396 exact new head CI must complete and pass; owner approves exact head; build identity and Staging acceptance separately. PR #395 remains a stacked Draft prerequisite.
- **Scope control:** no direct WordPress site modification, owner approval, grant rebind, Production, Developer or Breakglass.

## Regression checks

- `tests/operator-primary-next-step-runtime.php` (PHP 7.4 and 8.3)
- `tests/native-first-root-cause-runtime.php`, `tests/cross-provider-remediation-runtime.php`
- `tests/staging-write-owner-intent-runtime.php`, `tests/staging-write-owner-transport-runtime.php`
- `tests/operational-remediation-control-runtime.php`
- `tests/g6-delivery-integrity.py`, `tests/g9-delivery-contract.py`
- CI verification: `specs/007-content-intelligence-workflow-platform/extensions/competitive-experience/rebind-delivery.py`, must show `drift_count=0`.
