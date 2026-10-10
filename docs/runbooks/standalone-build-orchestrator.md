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
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-standalone-build-control.php
php wp-content/plugins/mad4b-site-control-plane/tests/standalone-build-control-runtime.php
```

Feature 007 Spec CI also invokes these tests when the workflow service is
functioning. Tests on one SHA never certify later commits on the moving branch.
