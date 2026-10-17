# MAD4B — Dynamic, task-scoped operational readiness

Date: 2026-10-17. Source changes in Draft PR #403; not deployed to either site.

## Cause and remediation
The prior Staging Certification flattened 18 independent gates into one all-or-nothing release decision. An unconfigured Google Drive integration, optional external browser, Brand Core, or import/export could appear as a global blocker even for a read-only frontend test. The Semantic Acceptance reducer also downgraded a valid semantic-only PASS merely because no independent browser receipt existed.

Preserve the original full-release result, but add separate scope_readiness and staging_functional_acceptance to the existing read ability mad4b/staging-certification-status. The read ability accepts task_scope. WordPress-native browser/cron observations are bound to the exact current package source head, exact site-origin fingerprint, saved owner consent revision, and maximum 30 minutes of freshness. All claims originate in server-side readback, not caller-provided PASS flags.

## Scope-derived requirements
- frontend: current Staging browser DOM and real navigation/Canonical proof; no forced Cron if this task is frontend-only.
- server: current same-host HTTPS WP-Cron smoke HTTP 200.
- filters: frontend + explicitly requested GET-only JetSmartFilters interaction with observed AJAX and distinct hashed dataset identity. Does NOT imply independent dataset-parity certification.
- full: both fresh server/frontend; filter required when explicitly tested.
- wpml: a distinct verified WPML translation-parity test; hreflang markers alone do not satisfy it.

The existing Staging gate projection also separates governed_write, managed_skills, frontend_performance, content_authoring, google_drive, import_export, independent_browser_release, and full_release. External browser evidence is optional for a frontend task but mandatory when the independently signed browser-release scope is requested.

## Generalized optional-gate audit

| Gate / dependency | Applicable functionality | Decision |
|---|---|---|
| External browser signing/provider | Independent browser release | Optional for basic native frontend acceptance; retain complete release gate |
| External Skill snapshot | External Skills / full release | Unrelated to frontend acceptance |
| Google OAuth + managed broker | Google Drive operations | Unrelated to frontend or JetSmartFilters; managed mode already selected dynamically |
| Brand Core + Context Authority | Brand-dependent authoring | Not required for frontend smoke |
| WP Import/Export certified archive | Import/export jobs | Not required for unrelated Providers |
| Developer/host isolation | Developer and privileged actions | Not required for read-only native Staging browser |
| Query Monitor attribution + full performance budgets | Performance certification | Unrelated to basic DOM acceptance; 2s / 100 queries / 128MiB targets unchanged |
| Rollback artifact retention | Recovery and release | Retained for mutations/release |
| Write authority / candidate binding | Governed content changes | Retained; functional PASS cannot authorize Write |
| Shadow-provider compatibility and semantic contract | Reversible provider behavioral recertification | Must remain checked per target, fail closed for unknown abilities |
| Build, site, owner revision and observation freshness | All native functional evidence | Required: stale/different version/Production cannot pass |

## Dynamic extension, not plugin-specific code
Plugins may ADD a named task scope with declared *existing* Staging gate dependencies through the bounded WordPress filter mad4b_scp_staging_functional_scope_dependencies. They cannot replace default scopes, omit exact_build + safe_boot, invent passing gates, or relax full-release authority. Unknown gates are rejected. The Semantic Acceptance reducer uses the trusted planned suite; semantic-only PASS is not downgraded by an irrelevant missing browser, while genuinely missing semantic assertions remain INCOMPLETE_EVIDENCE.

## Task ledger
| ID | Work | State |
|---|---|---|
| SIMP-001 | Split functional readiness from full release | SOURCE IMPLEMENTED |
| SIMP-002 | Determine frontend, server, filters, full, WPML requirements by requested task | SOURCE IMPLEMENTED |
| SIMP-003 | Exact source/site/owner revision and 30-minute freshness, no Production | SOURCE IMPLEMENTED |
| SIMP-004 | Optional Google, external browser, Brand and Import gates do not block unrelated scopes | SOURCE IMPLEMENTED |
| SIMP-005 | Shared bounded plugin extension for gate-specific scopes | SOURCE IMPLEMENTED |
| SIMP-006 | Fix Semantic PASS downgraded by unrequested browser requirement | SOURCE IMPLEMENTED |
| SIMP-007 | PHP 7.4/8.3 regression tests | LOCAL PASS; GitHub CI needed |
| SIMP-008 | Full plugin CI, MariaDB concurrency, distribution package | PENDING |
| SIMP-009 | Live ETG / All Royal Egypt Staging functional test + WPML parity | NOT PERFORMED |
| SIMP-010 | Independent signed browser and Production release | SEPARATE, NOT CLAIMED |

Scope note: This review addresses shared readiness and acceptance decisions across the plugin and classifies cross-domain gates. It does not claim every plugin action is certified or that an observed DOM provides full JavaScript/AJAX/WPML parity. No Write/Production grant or live site mutation occurred.
