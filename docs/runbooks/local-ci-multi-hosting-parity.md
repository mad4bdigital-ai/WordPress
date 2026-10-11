# Feature 007 — Local CI Evidence Across Docker, Native and Hosted WordPress

**Scope:** Optional, exact-source, **non-authorizing** CI evidence for a user-selected GitHub PR, branch or commit. It complements Build Kit V7 and MCP Selected-HEAD plan/apply. **Hostinger is the live hosting platform, not a Docker container.** Docker, when available, is only an isolated execution engine for disposable source tests.

## Separation of environments

| Layer | Supported runtime | Can modify hosted WordPress? | What it proves |
|---|---|---|---|
| Source selection | GitHub PR, branch or immutable commit | No | Exact SHA, rechecked before and after |
| Local code gates | Docker on Windows/Linux/macOS, or explicitly approved native PHP/Python | No | Named G9, Selected-HEAD, IMP07, release-channel, enrollment fixtures |
| Site reachability | Read-only WordPress HTTPS REST (Hostinger or other provider) | No | Public WordPress REST availability only |
| Site runtime evidence | Export from governed **read-only MCP diagnostics** or provider diagnostic | No | Reported environment, installed SHA and binding, **not** signed acceptance |
| Staging acceptance | Governed MCP and Hostinger/live WordPress operations | Separate approval required | Activation, integrations, change plan, rollback/readback and certified result |
| Production | Existing root-trusted release channel | Not supported by this runner | Requires independent Production authorizations |

The tool NEVER deploys, installs, calls Hostinger SSH, bypasses an approval, writes to WordPress, or converts local PASS into GitHub CI success.

## Run against the selected HEAD (Windows)

Ensure the scripts exist in your local repository (for this feature, first fetch the reviewed branch/Hub when merged). Examples use a dynamic PR parameter, **not a hard-coded #258**:

```powershell
& "M:\Users\Nagy\Repo\WordPress\tools\Build-MAD4B-Local-CI-MultiTarget.ps1" `
  -RepositoryPath "M:\Users\Nagy\Repo\WordPress" `
  -SourceType PullRequest -Reference 258 `
  -Runtime Auto -TargetPlatform Hostinger `
  -Profile Extended `
  -ExpectedSiteUrl "https://staging.allroyalegypt.com" `
  -ProbeSiteReadOnly
```

The launcher fetches the source HEAD from GitHub, pins the exact SHA, runs a disposable clone, and rechecks GitHub when complete. To ensure it matches an already-reviewed Build Kit V7 candidate, pass `-ExpectedSha <full_SHA>`; the launcher refuses any drift.

- `Auto` prefers Docker for untrusted PR test code. This does **not** deploy WordPress in Docker.
- `Docker` uses **no network**, a **read-only source mount**, ephemeral `/tmp`, CPU/memory/pid limits and dropped capabilities. An image missing from the local Docker cache gives `NOT_RUN`, not fabricated PASS.
- `Native` is possible without Docker **only** with `-AllowNativeExecution` and installed PHP/Python. Executing arbitrary PR test code natively can read local files and access the network; use only reviewed code on a trusted machine.
- `Hostinger`, `WordPressHosted`, and `WordPressLocal` are separate *target types*. Neither forces nor forbids the Docker test runner.
- Use a different PR reference, a named branch or a full commit by selecting `-SourceType PullRequest|Branch|Commit`.

The Python entry point is `tools/mad4b-local-ci-parity.py`; it also runs on Linux/macOS with the corresponding flags.

**Pinned review path:** When testing an approved PR HEAD, prefer `-Runtime Docker -ExpectedSha <reviewed_40_character_SHA>`. Running a launcher taken from an older merge commit can omit later runner hardening; review and execute a trusted runner revision that includes the required baseline checks. The source under test is always a disposable exact-SHA checkout, not the mutable working tree.


## Hosted WordPress evidence through MCP

Use existing governed **read-only** WordPress calls to collect site information, profile environment, installed source SHA and deployment binding. Export a minimal JSON file with no passwords, tokens, WP salts, API keys or PII:

```json
{
  "target_type": "hostinger",
  "site_url": "https://staging.example.com",
  "wordpress_environment": "staging",
  "profile_environment": "staging",
  "installed_source_sha": "aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa",
  "deployment_binding_ready": true
}
```

Add `-SiteEvidence "C:\path\to\site-observation.json"` and `-ExpectedSiteUrl https://staging.example.com`. The runner validates shape, host type and exact URL and compares installed/candidate SHA. The externally supplied JSON is **not signed or trusted as an installation certificate**; reported state is `OBSERVED_NOT_CERTIFIED`. A public HTTPS `/wp-json/` GET is optional and does not expose authenticated MCP capabilities. On Hostinger this is a network *read*, not a server shell or Docker dependency.

If WordPress reports `production` implicitly while Site Profile reports `staging`, or deployment binding is missing, preserve that mismatch in the evidence. Do not enable candidate install just because the URL contains `staging`.

## Verdict, coverage and release binding

The runner produces `LOCAL-CI-PARITY-REPORT.json` containing source SHA, source type/reference, runtime, every actual gate result, `NOT_RUN` counts, optional host observation and untested coverage. Existing targeted gates include G9, Selected HEAD, staging selector/upload, IMP07, release-set, enrollment and update-channel contracts. The suite is configured by the checked-in, exact-source `tools/mad4b-local-ci-gates.json` manifest, rather than a hard-coded PR or site. New tested providers can add bounded, named PHP/Python fixtures in the same source review; shell snippets, unreviewed paths, and external executables are rejected. The report binds the manifest SHA-256 to the selected commit.

As of the 2026-10-10 Feature 007 hardening, the named `Extended` subset includes **21 gates** (`Core`: **12**), including the Local CI runner's own contract; 15 distinct fixture files are referenced. The executable has a required baseline of gate identities, interpreter types, fixture names, arguments and profiles, so an edited manifest cannot silently omit or rewrite these baseline gates. Additional vetted gates remain supported. **This is not a cryptographic trust anchor:** if a candidate changes the runner itself, its code must be reviewed or verified against an independently trusted copy before local evidence can be treated as dependable. Source file existence is not test execution.

A complete pass of this named subset sets `tested_gate_set_passed=true` **but retains** `LOCAL_CI_PARITY_PARTIAL`, because the repository includes many more GitHub workflows and the runner does not run disposable WordPress+MySQL integration, MariaDB upgrade tests, Playwright, multisite/browser suites, and actual live Staging mutation/rollback. Any unrecognized or malformed gate result is counted under `INVALID` and fails closed. A missing Docker image, absent PHP runtime, or any other `NOT_RUN` gate yields `LOCAL_CI_PARITY_BLOCKED` with `tested_gate_set_passed=false`. An empty gate set is also blocked. A failed gate or source drift yields `LOCAL_CI_PARITY_FAIL`; a failure takes priority over a simultaneous `NOT_RUN`. The three verdicts never authorize publication or deployment.

The Python entry point also refuses `--probe-site` without an explicit `--expected-site-url`. Site REST reachability remains observational, not authenticated WordPress/MCP acceptance.

The report always contains:

```json
{
  "github_ci_certified": false,
  "staging_certified": false,
  "production_authorized": false,
  "release_promotion_authorized": false,
  "host_mutation_performed": false
}
```

**Selected HEAD MCP integration:** `mad4b/control-plane-selected-head-plan` now includes `local_ci_multi_environment` policy metadata, bound to the resolved SHA, clearly saying local reports are non-authorizing. On-demand apply still requires a separate certified, repository-owned immutable ZIP (not the Build Kit's local ZIP by itself), host opt-ins, explicit Staging environment and clone-proof deployment binding, owner OAuth Step-Up, exact plan digest, independent archive verification, backup/readback and rollback. No local CI JSON may sign or publish a GitHub release or replace those gates.

## Unit contract

```sh
python3 wp-content/plugins/mad4b-site-control-plane/tests/local-ci-multi-environment-contract.py
python3 wp-content/plugins/mad4b-site-control-plane/tests/selected-head-update-contract.py
python3 -m py_compile tools/mad4b-local-ci-parity.py
```

The feature cannot claim end-to-end certification until these tests, actual matrix fixture execution and Hostinger Staging acceptance succeed on one immutable SHA. Do not update `master` or Production based only on this offline evidence.
