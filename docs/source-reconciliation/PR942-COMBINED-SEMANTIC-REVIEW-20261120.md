# PR #942: composite conflict semantic safety review (non-authorizing)

Date: 2026-11-20. Base source: reviewed PR #972 head `a16eba6e34178e3f0e9075207a1578e911afdadc` (stacks on PR #969); historic PR #942 commit `ed7ae0508cd927aa89294e56840d468f9ea8233e`. All 57 historic conflict rows remain **without manual functional signoff** and none is production-authorized.

## Source-backed findings for the 11 combined/revised paths

| Path family | Source behavior reviewed | Verification | Remaining acceptance |
|---|---|---|---|
| `provider-field-intelligence.php` | Reject foreign/unbound site before CPT, taxonomy, provider or post lookup; generic `post` is the default | Dedicated PHP field fixture plus SHA-bound negative regression | Tenant-specific on-site WPML/JetEngine behavior and signed release admission |
| `class-mad4b-scp-skill-registry.php` | Explicit Skills opt-in + enrolled/matched origin required | Skills catalog negative/positive fixtures; runtime readback of opt-in from Tours.dev and Wayfatours | Exact site catalog/API/role acceptance before promotion |
| `class-mad4b-scp-skills-admin-ui.php` | Company operations viewer uses `mad4b_manage_company_operations`; administrator Skills writes remain `manage_options` and nonce-controlled | Company deny-before-read harness, source-provider guards, admin route and surface fixtures | Authorized browser/UI acceptance on both companies and future granular capabilities |
| `mad4b-site-control-plane.php` | Local Ollama ability is kept as source but is not auto-included | Bootstrap source invariant and existing local-Ollama isolated negative fixture | Explicit Local Lab activation decision only; not a global default |
| `site-neutrality-policy.json` | Remove tourism CPT as a general field-intelligence default | Full standalone Site Neutrality source/PHP smoke | Independently inspect cloned/foreign-site behavior after staging release |
| `integration-pr415-pr416-pr420-pr421-contract.py` | Exact Admin successor negative gate remains required | Original integration and Admin successor fixtures | New exact-head repository/runtime release matrix |
| `readiness_test_execution.py` | Only exact source-reviewed CLI regressions admitted; unknown paths refused | Execution allowlist self-test, source-invariant gate | Verify entire future CI workflow selection and SHA-bound source review policy |
| `capability-coverage.json` | Explicit CI-only review evidence; no Production promotion | Source/governance flags and current source blob | Review generated capability history against new composite tree |
| Feature007 post-merge closure JSON | Historical provenance updated without setting `authorizing=true` | Exact source/JSON contract | New build identity + QA31 acceptance |
| Country/nationality switcher workflow | Pin actual Playwright image and Chromium binary, retaining assertions | Workflow source-invariant gate and prior GitHub workflow evidence | Fresh real-browser run on exact integration head (not asserted by this review) |
| Local OAuth standalone workflow | Pinned browser install without relying on unstable runner Apt mirror; still runs test | Workflow source-invariant gate | Fresh browser/E2E on exact release candidate |

## Reproducible controls

`tests/source-reconciliation/pr942_combined_semantic_contract.py` checks the **exact Git blob hashes** for all 11 paths against the pinned non-authorizing successor ledger. It proves the source guard placement in Tenant Field Intelligence, catalog opt-in, Admin write-source nonce checks, generic Bootstrap opt-out, browser workflows, site-neutrality policy and execution allowlist. It never changes the original 57-row ledger or sets a signoff. `--verify-report` compares a generated report to the committed JSON and fails on drift. Eight tests include deliberate synthetic mutations of tenant order, default CPT, nonce, Skills feature gate, Ollama include and approval claims. The company-scoped PHP fixture exercises both denied and authorized read-only paths in hermetic mocks without a database or SMTP.

**Already executed on source under review:** 9 existing PHP fixtures passed inside an isolated PHP 8.5 image with network disabled; the integration/admin/SKill-certification Python checks and full site-neutrality harness (including multiple PHP subprocesses through an isolated runner) passed, plus the Readiness allowlist self-test. This is hermetic source evidence, **not signed WordPress on-site acceptance**. The workflow created by this change reruns all relevant checks under PHP 8.3/8.5 in GitHub.

## Release gates still open

1. The 57 collisions need explicit human or owner functional acceptance with correct exact-head source and audit receipts; source hash equality by itself is not functional approval.
2. Source-copy differences remain material (fresh mounted-file readback: Tours.dev 207 different tracked names, Wayfatours 226). Company Media is preserved separately in Draft PR #972 and must not be removed before governed rollout.
3. Final integrated candidate must retain the eight newer transportation commits on `master`, receive full CI + PHP/WordPress staging tests + rollback evidence, and acquire a **new exact-head** release authorization. The prior PR #882 owner attestation must never be reused for this different candidate.
4. No changes were applied to Site Profile, WordPress roles, SMTP, Fluent Forms notifications, source site-copy, live PHP containers, existing PR heads or `master`.
