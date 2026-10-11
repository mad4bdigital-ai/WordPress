# MAD4B — Standalone Exact-HEAD Build Orchestrator (Feature 007)

## Purpose and scope

The canonical ZIP builder has **not** been replaced. `tools/mad4b_standalone_build.py`
runs that same producer outside GitHub Actions, against one immutable **clean**
checkout. The new read-only WordPress MCP Abilities are
`mad4b/standalone-build-discover` and `mad4b/standalone-build-plan`.
They describe a trusted-runner handoff; WordPress **never** invokes an arbitrary
PHP callback, external command, shell, GitHub Actions workflow or upload.

This is a standalone **build** capability, not a release shortcut.
A valid ZIP without separately certified tests is **BUILT_UNVERIFIED**.
Neither a GitHub Actions outage nor a clean source tree proves runtime acceptance.

## Select an immutable head (any PR, branch or commit)

Resolve the desired PR/branch to a **full exact SHA** through trusted GitHub
source inspection, then check it out in a disposable clone/worktree.
Check the SHA using `git rev-parse HEAD`; leave the worktree clean and do not
put build output inside the repository. This is not hard-coded to PR #258.

A trusted operator must separately obtain the MCP Adapter ZIP declared by
the committed policy and certified provider profile:

- `wp-content/plugins/mad4b-site-control-plane/config/runtime-release-policy.json`
- `wp-content/plugins/mad4b-site-control-plane/config/certified-provider-profiles.json`

The standalone builder verifies the **exact archive byte length, SHA-256 and
ZIP CRC** against that certification. It never accepts an arbitrary download
URL as authority; the archive is supplied as a local file, not downloaded by
WordPress. Do not add signing private keys to the repository.

## Build only, without CI

```powershell
python tools/mad4b_standalone_build.py `
  --repo-root "M:\\Users\\Nagy\\Repo\\WordPress" `
  --expected-head "<FULL_40_CHARACTER_REVIEWED_SHA>" `
  --adapter-archive "C:\\trusted-artifacts\\mcp-adapter-0.7.0.zip" `
  --output-dir "C:\\builds\\mad4b-<FULL_SHA>" `
  --profile build-only
```

For local checks, change `--profile build-only` to `--profile local-checks`
and supply `--php` pointing to an actual PHP 8.3 binary. The local suite
checks PHP 8.3 syntax and eight named runtime fixtures; these **do not** cover
every CI check, native disposable DB, browser/WPML or live Staging acceptance.

The tool only supports `build-only` and `local-checks`; there is no
`--publish`, `--install`, `--production`, free-form command, URL or
`--skip-validation` option. Exit 2 means fail-closed preflight/build refusal.
The source tree is rechecked after the build to detect source drift.

## Generated artifacts

- `mad4b-site-control-plane-<exact-SHA>.zip`
- `CANONICAL-PACKAGE-RECEIPT.json`
- `MAD4B-BUILD-PROVENANCE.json`
- `BUILD-FINGERPRINT.txt`
- `PACKAGE-MANIFEST-DIGEST.txt`
- `STANDALONE-BUILD-REPORT.json` with explicit `BUILT_UNVERIFIED`,
  observed local test results and **false** release/staging/production claims.

The existing canonical ZIP producer fixes ordering, timestamps, permissions
and `stored` compression for byte-for-byte reproducibility; run the builder
twice in separate output directories to compare SHA-256. Never claim a
release certificate solely from matching hashes.

## Produce actual five-gate evidence without GitHub Actions

A separate entrypoint re-runs G9 delivery and the eight named PHP runtime
fixtures on the exact clean source tree, lints the complete PHP tree under
**native PHP 8.3**, verifies the package/receipt and then unpacks the ZIP into
a disposable workspace for the original PHP package-integrity verifier:

```powershell
python tools/mad4b_standalone_evidence.py `
  --repo-root "M:\\Users\\Nagy\\Repo\\WordPress" `
  --expected-head "<FULL_40_CHARACTER_REVIEWED_SHA>" `
  --zip "C:\\builds\\mad4b-<FULL_SHA>\\mad4b-site-control-plane-<FULL_SHA>.zip" `
  --receipt "C:\\builds\\mad4b-<FULL_SHA>\\CANONICAL-PACKAGE-RECEIPT.json" `
  --output-dir "C:\\evidence\\mad4b-<FULL_SHA>" `
  --php "C:\\php83\\php.exe"
```

Outputs `GATE-RESULTS.json` with **five actual PASS/FAIL/BLOCKED values**
and `NATIVE-TEST-EVIDENCE-BUNDLE.json` with source/archive identity,
tested PHP version and bounded named-check results. Exit 2 is **BLOCKED**
or **FAIL**, not a fabricated PASS. These are correctly shaped inputs for
the separate existing owner-reviewed publisher, but they are **not themselves
trusted attestation**, even if all five gates pass: the test runner and
selected candidate might be from the same untrusted repository. The owner
must independently review source/test provenance and run in a trusted,
isolated runtime before using the existing Ed25519 signer. The script has
no signing, publishing, GitHub mutation or WordPress deployment capabilities.

## CI outage versus native-test failure

If GitHub Actions reports failed runs **with zero jobs created**, distinguish
runner/workflow provisioning from an actual failing PHP test. A jobless
failure is **CI evidence unavailable**, *not* a source test failure or PASS.

For Staging fallback, follow
`docs/runbooks/ci-outage-selected-head-staging.md` and use existing
`tools/mad4b_publish_offline_staging_candidate.py` **only after** the
independent native tests, trusted owner review, canonical receipt, isolated
ZIP runtime verification, source/head readback and Ed25519 signature exist.
Its five source-bound `PASS` gates cannot be inferred or synthesized from
`STANDALONE-BUILD-REPORT.json`, from partial local fixtures or from an
untrusted package producer. A key stays on a separate trusted signing runner.

Installed WordPress must independently validate its Site Profile, source
identity, exact approved plan, certified package bytes, backup, activation,
audit, post-install readback and rollback. Never modify Production or GitHub
branch protection as part of this path.

## Runtime and test coverage

The two new WordPress MCP Abilities require installation of this source.
Discovering an entrypoint only proves metadata; the read-only plan returns
`NOT_RUN` and never creates, queues or dispatches a build. Future orchestration
may dispatch through a separately enrolled, scoped, attested external runner;
that requires an independent signed-job/receipt contract and runtime acceptance.

Offline source tests:
```bash
python3 tools/test_mad4b_standalone_build.py
python3 tools/test_mad4b_standalone_evidence.py
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-standalone-build-control.php
php wp-content/plugins/mad4b-site-control-plane/tests/standalone-build-control-runtime.php
```

Feature 007 Spec CI also invokes these tests when the workflow service is
functioning. Tests on one SHA never certify later commits on the moving branch.

## Trusted runner integration gate (11 October 2026)

The current MCP Abilities are **read-only**. Enabling remote execution requires a separate owner-approved and exact-job-bound request, a registered semantic Remote Work Queue operation, and a pinned trusted runner identity. The worker must use only the existing deterministic ZIP entrypoint against a clean exact SHA, with verified MCP Adapter archive. The WordPress runtime must reject unsigned or expired receipts, mismatched job/site/plan/build identities, canceled or expired leases, and all Production requests. The signed receipt may prove only `BUILT_UNVERIFIED`, not installation, certification, or publish authority. Until the corresponding worker, verifier, and native acceptance tests are deployed, `automatic_execution_enabled` remains false; UI discovery and plans must not claim that a job was executed.

## MCP Staging queue and trusted runner receipt v1 (source delivered 11 October 2026)

These steps are separate from WordPress plugin activation and Production authority.
The WordPress MCP Ability `mad4b/standalone-build-plan` remains read-only. The
new `mad4b/standalone-build-request` is an **owner-governed queue mutation** on
`mad4b-admin`; it requires exact lowercase `expected_head`,
`expected_plan_sha256`, optional `profile`, and exact confirmation
`QUEUE EXACT STAGING SOURCE BUILD`. Existing actor enrollment, exact grant,
approval, OAuth step-up and authorization checks remain in force. A real
`WP_ENVIRONMENT_TYPE=staging`, valid Site Profile, installed current-build
provenance and match of enrolled origin are mandatory; no implicit Production
environment acceptance or automatic approval is added.

The request enqueues only `standalone_source_build` into the existing bounded
Remote Work Queue. It does **not** run a build on the WordPress host. A
separately enrolled runner uses the existing
`mad4b/remote-operation-work-claim`,
`mad4b/remote-operation-work-cancel-signal`,
`mad4b/remote-operation-work-provider-checkpoint` and
`mad4b/remote-operation-work-complete` abilities. The worker must record
`provider_entered` **before** invoking the isolated build and
`provider_returned` on successful return; cancellation or expired lease
blocks completion and requires reconciliation. No blind retry.

The external worker must run a **pinned, independently reviewed executable**
outside the untrusted source checkout and reproduce the ZIP using
`tools/mad4b_standalone_build.py` in an isolated, resource-limited sandbox,
with a hash-verified certified MCP Adapter archive. The separate runner
receipt implementation is `tools/mad4b_standalone_runner_receipt.py` and
uses `cryptography` Ed25519. Install its reviewed digest to the trusted
runner path and prevent the source under test from accessing the signing key.
Never store that key in Git, plugin options, WordPress uploads, or container
mounts. The trusted runner signs only when the canonical receipt, source
HEAD, manifest hashes, archive bytes, job claim/generation, site binding and
independent owner-approved runner policy agree.

The WordPress host must be explicitly enrolled with **public** key
`MAD4B_SCP_STANDALONE_BUILDER_PUBLIC_KEY_B64` (32 decoded bytes) and fixed
executor ID `MAD4B_SCP_STANDALONE_BUILDER_EXECUTOR_ID`. Configuration of
these constants requires the existing authorized host deployment process;
neither can be set by untrusted MCP payload. The signed pair
`build_receipt: {claims_b64, signature_b64}` accompanies the leased job's
current executor/lease token on the already-governed Work Complete ability.
The verifier checks Ed25519 signature, exact target SHA, plan, site/origin,
worker identity, claim generation, 5-minute receipt freshness, three digest
fields, actual Staging and provider-returned checkpoint. It then records
`BUILT_UNVERIFIED` only.

A created ZIP is not a certified release. Required follow-ups remain native
PHP 8.3 checks, independent ZIP acceptance/evidence, manual owner release
review, signing/approval authority, Staging deployment and live readback.
Never treat queued GitHub Actions, policy tests or this source update as live
runtime PASS. `automatic_execution_enabled=false` until a separately
enrolled, reachable runner executes the full protocol and provides verified
runtime readback.

## October 2026 parse-failure prevention

The reviewed PR head previously contained a malformed
`class-mad4b-scp-standalone-build-control.php`: a truncated input schema,
duplicate methods and premature PHP class termination. A build-only ZIP had
no mandatory PHP parser gate and could therefore package that source.

The recovered source was reconstructed from the last known-good standalone
control implementation with the exact typed build-request and signed-receipt
methods reattached once. **A source readback is not itself PHP acceptance.**

Starting with the corrected standalone builder, all packaging profiles
(including `build-only`) require the **real PHP 8.3 CLI**. Every plugin
`*.php` source must pass `php -l` before any ZIP or sidecar is written;
missing CLI, wrong PHP version, interrupted linter or one parse failure
must block building. This cannot be skipped by passing a profile or by
running only Python unit tests. The build report includes
`php83_syntax_gate` when successful. Actual Staging runtime acceptance
still requires a fresh environment-matched observation after installation.

To avoid unrelated site mutations during recovery, isolate the plugin
directory on Staging and preserve the prior package and error logs;
never deploy the unverified October 10 candidate or alter Production.
