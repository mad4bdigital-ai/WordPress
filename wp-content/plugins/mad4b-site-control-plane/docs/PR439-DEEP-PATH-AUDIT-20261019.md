# PR439 deep path audit — 19 October 2026

Scope: `mad4bdigital-ai/WordPress` PR439, ETG Staging read-only diagnostics, disposable local PHP fixtures. This report is not release, provider, write-authority, SEO or Production certification.

## Reproduced defects and repairs

| Path | Evidence | Repair / verification |
| --- | --- | --- |
| Session-safe provider accumulation | Raw provider trees retained between checks | Existing PR projects callbacks before sibling accumulation; 64 MiB fixture passes |
| Allowlisted scalar summaries | New fixture with an 8 MiB state and nested version failed the size assertion | Bound scalar-only, top-level and nested strings to 512 bytes BEFORE hashing; preserve booleans/null and complete UTF-8; extended memory fixture passes |
| Readiness mapped execution | CI tried to run a WordPress WP-CLI test using PHPUnit | Concurrent exact-head repairs map to actual Python/PHP hermetic execution; WP integration remains separate |
| Feature boundary | `REPOSITORY_ROOT_CHANGE_BRANCH_FORBIDDEN` after changing protected `mad4b-site-control-plane.yml` | Restore that file exactly to master; run the same new tests in scoped MCP workflow across PHP 7.4/8.1/8.3/8.4/8.5; baseline-owned boundary verifier passes |
| Owner contract tests | Catalog/release tests still required obsolete ambiguous-dual-proof rejection | Previous commit 991891d2 preserves required/stale-proof refusal, exact one-use claim, and no reattestation |
| Capability traits fixture | Historical count 33 vs current 40; missing WP_Error facade; assumes local execution and generic idempotency for every provider | Explicit count 40, minimal error facade, exact Elementor document-SHA CAS and owner reconciliation, Drive remote API locality; fixture passes |
| Draft writer fixture | Checks old inline server catalog after extraction to trait | Assert trait import AND draft candidate in `class-mad4b-scp-server-write-catalog.php`; draft/drift/QA refusal fixture passes |
| Self-approval fixture | PHP interpolated `$ability_name` inside source assertion strings | Escape literal variable names; original single-claim, terminal audit, OAuth and Production refusal assertions pass |
| Offline trust enrollment fixture | Expected error names omit actual `mad4b_offline_` prefix | Correct locked/audit-failed error identities; exact-owner mutex and audit rollback fixture passes |
| Import mode fixture | Expected unknown-mode before profile allowlist denial | Verify denied unapproved mode; separately allowlist the invented mode and prove trusted catalog still rejects it; no execution fallback |

## Verification inventory

- Initial independent sweep: 440 PHP fixtures with standalone ABSPATH stubs. 419 passed under bare `php -n`; 10 more passed after loading required ctype/tokenizer extensions. These environment failures are not site defects.
- Five stale fixture contracts above were repaired and executed successfully. Native build fixture passes with tokenizer. Official MCP preflight and endpoint diagnostic fixtures pass when given the materialized bundled adapter path. Deterministic fixture passes with its documented seed and clocks.
- Therefore 438 of the 440 selected fixtures were executed successfully with their prerequisites or repairs. Two selected scripts require dedicated harnesses: `functional-gap-evaluator-parity.php` needs generated JSON fixtures; `g8-mysql-cas-integration.php` requires an explicitly disposable database. They are not marked PASS here.
- The operational error regression wrapper now executes 22 cases, including the five repaired contracts. All 22 pass locally.
- The mapped readiness runner executes both required mapped regressions and reports `PASS_ALL_MAPPED_TESTS`; this is coverage only.
- Separate 64 MiB stress fixture covers raw provider redaction, blocked readiness, callback exception/no replay, giant scalar fields and UTF-8 boundaries.
- The repository contains 879 test files. The 440-fixture sweep is deliberately not described as exhaustive coverage of every live/provider/browser path.

## ETG live read evidence

Observed through the selected WordPress connector:

| Surface | Observation |
| --- | --- |
| Site info | WordPress 7.1.3; PHP 8.5.11; Control Plane rc.102; Staging; Breakglass false |
| Site Profile | Configured; origin/environment/deployment binding match; no profile blockers |
| Operation discovery, diagnostic query | Succeeded; three diagnostic operations plus bounded ability hints |
| Specific Code Snippets diagnostic metadata | Succeeded; read-only schema returned |
| Native-runtime diagnostic metadata / broad discovery / provider matrix | Opaque MCP `-32603 Internal error` observed on separate requests |
| Session-safe identity | Opaque MCP internal failure on installed rc.102, before these new repairs are installed |

Public HTTP GETs, one at a time without authentication or mutations:

| Requested path | HTTP | Interpretation |
| --- | --- | --- |
| `/` | 200 | Earlier homepage 500 not reproduced in this sample |
| `/wp-json/` | 200 | REST index reachable |
| `/robots.txt` | 200 | Public robots endpoint reachable |
| `/wp-sitemap.xml` | 200 | Sitemap response reachable |
| `/tours/` | 404 | Guessed path only; not proof of a broken configured archive |

Client-observed response times were approximately 6.8–17.8 seconds; these include the remote client/network path and are not PHP-origin timing measurements. A 200 response does not certify filters, AJAX dataset identity, canonical, hreflang or provider writes.

## Remaining independent acceptance

1. Current-head GitHub matrix, including new scoped PHP jobs, must finish. Superseded/cancelled checks do not count.
2. Exact-head owner attestation is an independent repository gate and cannot be generated as an owner approval by this audit.
3. Certify/install the exact reviewed package through the governed Staging path, then repeat session-safe identity/triage/full and discovery probes against one generation.
4. Opaque remote MCP failures still require correlated PHP/transport evidence if they persist. The source memory defect is proven; attribution of every external transport failure to it is not.
5. Execute real filter/AJAX/count/dataset/canonical/WPML checks using configured routes and providers; do not activate SEO as part of diagnostics.
6. Governed write/tour publication, provider certification, ticket and rollback acceptance remain separate. No live write, key enrollment, source install, Developer/Breakglass or Production change was performed by this audit.

## Actual sitemap route samples

The sitemap exposed the configured archive `/tours-and-activities/` and the detail page `/tours-and-activities/giza-pyramids-half-day-tour/`. Both returned HTTP 200 with one self-referencing canonical and no sampled fatal-error marker. No hreflang link was present in either sampled HTML document; whether a translated counterpart exists must be established before classifying this as a WPML defect. HTML samples were approximately 875 KB and 1.08 MB respectively (client-decoded text size, not compressed transfer bytes). No AJAX or browser interaction was executed by this HTTP-only sample.
