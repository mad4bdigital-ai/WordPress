# Read-only Skill Routing — Candidate Review Evidence

**Status: PENDING REVIEW.** This document prepares evidence for repository governance. It **does not** attest owner approval, production readiness, complete CI, or the right to execute remote Skill text.

- Stacked PR: [#525](https://github.com/mad4bdigital-ai/WordPress/pull/525) based on [#522](https://github.com/mad4bdigital-ai/WordPress/pull/522).
- Exact source review candidate: `b2a909f62704590e8f7713210d3d306f4a599e95`. The code blob identities below remain valid only while their bytes are unchanged.
- Immutable dependency: #522 head `cf909fb32cfbdfbfcad754708370e32904299e3a`.
- Scope: read-only task matching and non-authorizing skill composition. External Skills are never installed, executed, granted permissions or treated as trusted code.

## New capabilities and proposed existing controls

| Capability or service | Risk | Suggested existing controls | Review status |
| --- | --- | --- | --- |
| `ability:mad4b/skill-match-task` | P0 | MCP-01, PROVIDERS-01, EXECUTION-01 | PENDING |
| `ability:mad4b/skill-composition-plan` | P0 | MCP-01, PROVIDERS-01, EXECUTION-01 | PENDING |
| `service:wp-content/plugins/mad4b-site-control-plane/includes/class-skill-task-composition.php` | P1 | AUTOMATION-01, PERFORMANCE-01 | PENDING |

The owner/reviewer must decide whether existing controls are sufficient or a new control is required. Listing a control here is not approval.

## Exact source blob candidates

| File (relative to plugin root) | Git blob SHA | Review state |
| --- | --- | --- |
| `includes/adapters/class-mad4b-scp-skills-adapter.php` | `064937e152bbbff3ffa6b8ef4548d4d77b9d2ccd` | PENDING |
| `includes/class-mad4b-scp-skill-abilities.php` | `97560579b62ee702e960b7bd318a0484bcbf049f` | PENDING |
| `includes/class-skill-task-composition.php` | `bf7494168fbc998c274ac510906f05619c6f74c1` | PENDING |
| `mad4b-site-control-plane.php` | `2a1f0a15555ae223fb4f20b36eb221efe3da4a75` | PENDING |

All four changes require exact source review. The bootstrap path additionally participates in immutable archived G6/G9 delivery evidence. Neither old delivery manifests nor review status may be silently re-signed.

## Proposed regression evidence (non-authorizing)

| Test | Exact Git blob SHA | What it tests |
| --- | --- | --- |
| `tests/skill-task-composition-runtime.php` | `fa5000fd514cc3a5d0f6c3640b83bc6fcf6a5e31` | input bounds, denied unknown sources, bilingual matching, pagination, non-authorizing plans, stage order, stable digest, partial discovery and rate-limit errors |
| `tests/skill-task-abilities-runtime.php` | `59e09b0ba1a6414d3849d12119e0b6568db5fc39` | registration, existing read callback, no public REST/MCP, constrained schema, denied read, no execution ability |
| `tests/runtime-dynamic-skills-smoke.php` | `8628a0f5dc8ee5fa2a98b268339e8d1db3c2efba` | native WordPress registration and execution of read-only task matching/planning in disposable Staging |

Additional acceptance still required: current-head PHP 7.4 / 8.3 tests, native WordPress 6.9/latest Abilities, authenticated read-denial and MCP tools/list exposure, external GitHub egress/rate-limit, managed Skill reconciliation and snapshot identity.

## Findings before this review

At the previous exact head `da1d3f1a48febf525affe82eb4fe4d5011de79e1`, the Production Readiness artifact reported seven uncovered changes: three capability/service reviews and four source-blob reviews. The prior #522 head already had its own Feature 007/G9/Release Set fingerprint failures. This document does **not** resolve or waive those gates.

## Governance disposition

- No mutation of `governance/production-readiness/capability-coverage.json` has been made in this PR.
- An authorized owner/reviewer must examine these source blobs and actual test results before any `reviewed_for_ci_only` entry can be created by the prescribed governance workflow.
- `reviewed_for_ci_only` would still not grant a Production deployment, site authority, an MCP write surface, or execution of third-party scripts.
- PR #525 remains Draft until CI, live MCP acceptance, explicit reviewer evidence and #522 dependency are reconciled.
