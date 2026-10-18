# PR #423 open-issue source review — 2026-10-18

Repository: `mad4bdigital-ai/WordPress`. Reviewed all 10 open Issues, excluding pull requests. Issue #71 is the permanent governance attestation ledger; it is not a defect and must remain open. No issue is closed from this source-only review.

## Changes in this expansion

- #409: generate a distinct correlation ID for each bounded read invocation, preserve it through dispatch and compact-read errors, and label measured elapsed time as origin callback attempts only. A projection exception becomes a bounded error, with no raw message or automatic retry. Host/proxy correlation and latency attribution still need origin/gateway evidence.
- #413: canonicalize associative grant-record keys recursively before ordering and hashing. Keep semantic list order, duplicates, explicit denies and every record field. An existing owner receipt becomes stale when its exact plan digest changes; never normalize or substitute a receipt to force acceptance.
- #378: rejected, empty and truncated remote catalogs cannot report ready. Registered-operation readiness is explicit; total discovered automation coverage stays unknown. Existing passive Admin Surface Coverage and adapter-blueprint routes remain the separate discovery path. No generic callback execution is introduced.
- Add a deterministic, deduplicated regression inventory covering all nine defect/acceptance Issues. PHP executor, SQLite and mbstring are mandatory. CI executes the suite and retains its non-authorizing report. Missing executors, missing test files and failed/timed-out fixtures fail the source regression check.

## Source vs independent acceptance

The executable catalog is `open-issue-regressions.json`; it lists concrete PHP/Python fixtures and independent acceptance requirements. Success means these repository fixtures passed, not that a site, provider, host or release has been certified. The catalog is a review snapshot of the listed open Issues, not automatic discovery of future GitHub Issues.

| Issue | Regression scripts | Independent closure evidence still required |
|---|---:|---|
| [#424](https://github.com/mad4bdigital-ai/WordPress/issues/424) | 3 | Certified release pointer publication and archive provenance; ETG and ARE exact installed-build readback: ancestor/same/successor/unknown UI and native Plan |
| [#413](https://github.com/mad4bdigital-ai/WordPress/issues/413) | 10 | ETG and ARE same-build Admin/OAuth exact-plan comparison; Fresh per-site signed owner review and OAuth step-up; Elementor and JetSmartFilters bounded write/readback/rollback canaries; Generated CSS font network/CORS/cache and frontend acceptance |
| [#409](https://github.com/mad4bdigital-ai/WordPress/issues/409) | 4 | 20 serial reads per site and 10 controlled cross-site reads without opaque errors; Host/gateway/origin correlation trace with PHP and wall timings separately |
| [#384](https://github.com/mad4bdigital-ai/WordPress/issues/384) | 4 | Certified install/discover/connect/govern/approve/execute/verify/recover on each site; Provider consent, approved Brand Core, browser, performance and signed site receipts |
| [#378](https://github.com/mad4bdigital-ai/WordPress/issues/378) | 5 | Live inventory completeness for installed admin, REST, CLI, provider and host surfaces; Independently reviewed semantic adapter admission and paginated coverage proof |
| [#300](https://github.com/mad4bdigital-ai/WordPress/issues/300) | 5 | Disposable WordPress/MCP and MySQL/MariaDB CAS crash/race acceptance; Signed provider observation and exact package dependency proof; Governed install/activate/configure/readback/rollback with certified host |
| [#296](https://github.com/mad4bdigital-ai/WordPress/issues/296) | 3 | Direct API and operator browser acceptance for safe create then typed resume/unfreeze; Disposable WordPress/database integration and legacy active-profile compatibility |
| [#292](https://github.com/mad4bdigital-ai/WordPress/issues/292) | 5 | Server-origin explicit environment and same-origin clone binding proof; Current-build candidate/grants, kill-switch, restore/replay adverse cases and signed per-site receipt |
| [#291](https://github.com/mad4bdigital-ai/WordPress/issues/291) | 3 | Disposable database concurrency, legacy orphan/duplicate market migration and API readback; Current-build governed Staging/browser acceptance |

## Run

```sh
python3 wp-content/plugins/mad4b-site-control-plane/tests/open-issue-regressions.py --output dist/mad4b-open-issue-regressions.json
```

The report keeps each issue `OPEN_INDEPENDENT_ACCEPTANCE_REQUIRED` even when all source fixtures pass. Shared fixtures execute once, then their result is attributed to each relevant Issue. Live site validation, a provider certificate, owner approval, merge and deployment are separate actions. This branch changes no site, grants, protections or Production state. PR #425 and #426 remain separate readiness-governance workstreams and are not folded into this branch.

## Live open Issue admission — source-neutral CI

The prior catalog was an issue snapshot and did not detect newly opened GitHub
issues. This integration now calls GitHub REST **read-only** in the exact-head
Pre-Staging workflow, excludes pull requests, and compares all open Issues
against `open-issue-regressions.json`. Missing/duplicate/unknown Issues fail
CI; ledger exceptions are source-reviewed by `ledger_issues` and remain open.
Open defects must retain explicit source regressions and independent-acceptance
gates, and the generated `dist/mad4b-open-issue-live-coverage.json` reports
their closure as still **OPEN_INDEPENDENT_ACCEPTANCE_REQUIRED**. A live API
read error also fails closed; no old snapshot can certify current completeness.

This is a dynamic coverage and review requirement, **not** automatic repair or
issue closure. A new Issue should first receive reviewed ownership, mapped
PHP/Python regressions, documented site/host/provider acceptance and exact-head
CI. No GitHub token is written to the report. GitHub Actions needs only
`issues: read` and `contents: read`, never write scopes.

Two other open items (#425, #426) are pull requests, not defect Issues; they
remain independent stacked readiness/capability work. Their future source
cannot be claimed merged by this branch, and any later integration will need
its own exact-head review and CI.
