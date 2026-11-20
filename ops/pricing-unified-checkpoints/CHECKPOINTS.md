# MAD4B Unified Pricing & JetEngine UX — Release Checkpoints
Snapshot date: 20 November 2026. Primary source: Tours.dev staging; target: Wayfatours production. This branch is stacked on PR #965.

## Financial and data guardrails
- Never activate Checkout or publish historic Wayfa Tour Rates without certified children, accommodation, transport, guide, extras, tax, FX and company margin evidence.
- Tour Types / Package Levels are preview-only markups applied to old selling start_price, NOT supplier cost proof.
- JetEngine CPT/Taxonomy/Options Page definitions remain source of keys, types, native form and save actions. Do not duplicate inputs or clone policies.
- Staging first, original file backup, rollback, tests on both sites and Git SHA parity for every deployed component.
- Never claim active customer price merely from an enabled native draft or preview.

## Integrated checkpoints
| Checkpoint | Scope and acceptance | State |
| --- | --- | --- |
| CP0 | Capture both sites' settings, feature SHA, Git and #965 CI | PASS: identical source preview SHA 384f523d; 5/5 CI #965 |
| CP1 | WPML Package IDs accepted across languages only when original WordPress Tour relation exists; invalid Package refused; native quotes unchanged | PASS: 228 Staging and 477 Wayfa identity checks, 87 translations, 193 foreign IDs rejected, 0 failures. 192/197 price previews unchanged |
| CP2 | Reconcile core JetEngine functional-modes PHP/JS/CSS source vs Wayfa, native Save and dependency contracts; browser regression | SERVER PASS, BROWSER OPEN: 53 pricing/31 activation fields; optional Recipe Registry compatibility; browser Save/repeater/mobile not yet certified |
| CP3 | Single cost evidence contract for property/unit, transport, guide, extras; no retail value is cost | BLOCKED: certified component inputs not yet complete |
| CP4 | Unified price evaluator with one-time rule application, effective FX receipt, passenger tax/fee, no duplicate inclusion | NOT STARTED: cost qualification required |
| CP5 | Semantic JetEngine Modes, conditional 21-field editor, searchable entity/assignment fields with native input preservation | NOT STARTED |
| CP6 | Unified native policy write/approval gateway with RBAC, revision, validation and original writer ownership | NOT STARTED |
| CP7 | E2E matrix: Wayfa 84 Tours, 8 package templates, EN/ES, rate/occupancy/children/FX, mobile 320/390/768/1024/1440 and checkout gate | NOT STARTED |
| CP8 | Local Git/GitHub/live fingerprint parity, all CI green, approval and merge/release evidence | NOT STARTED |

## CP1 evidence
- Imported the source preview file from both matching live WordPress MU roots; it was previously absent from the Git feature branch.
- package_for_tour resolves caller WPML term to the actual original Taxonomy relationship and checks canonical term identity, no target guessed from name.
- Test: ops/pricing-unified-checkpoints/tests/verify-wpml-package-preview.php.
- The five pre-existing preview failures remain: 1 disabled Platinum and 4 missing price-base selections on posts 57562/57563; no Tour Rates, prices or orders were mutated.

## CP2 runtime findings
- Original Wayfatours module.php does not include the optional Recipe Registry and its styling assets, unlike Tours.dev. The first attempted three-file upgrade **failed** during a WP-CLI asset test and was rolled back to exact original hashes, verified with HTTP 200.
- A source-neutral guard now calls the Recipe Registry only when the class exists, and enqueues optional Recipe field CSS only when available.
- Deployed the source core class, Functional Modes JS/CSS, and optional CSS only after staging acceptance. Production test passed the native field schema, 9 compatible UI Modes and registered dependencies, HTTP 200. Source CSS/JS hashes match both sites.
- Did not replace module.php or install the entire Recipe Registry on production. The browser-authenticated Save and mobile repeater acceptance remains open.

## CP2 source reconciling notes
Functional Modes source PHP and JS in Git match Tours.dev but differ from Wayfatours Production: save control and error states, dynamic original JetEngine fields, optional JS dependencies and CSS repeater fixes. Never deploy these older production files over staged current files; compare and verify before production rollout.

## Full closure criterion
Commercial Rate generation requires per-component signed cost receipts, deterministic calculation order, no duplicated markups, currency and passenger correctness, provider readiness, audited approvals, UI acceptance and release CI. Record each blocked prerequisite explicitly and do not present a checkpoint as passed without testing it.
