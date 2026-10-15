# Optional MCP Selected-HEAD Control Plane Update

Status: Source implementation on Feature 007 PR #258. **Not** enabled by default.
This is an additional capability; it does **not** replace or redirect the
ordinary immutable master Release channel and never sets WordPress auto-update
preferences.

## Usage through MCP

1. Discover `mad4b/control-plane-selected-head-plan`.
2. Supply an explicit source selection:
   ```json
   {
     "candidate_source": {
       "repository": "mad4bdigital-ai/WordPress",
       "type": "pull_request",
       "reference": "258"
     },
     "reason": "Review and install an explicitly chosen exact build on enrolled Staging"
   }
   ```
   The `type` can also be `branch` or `commit`; for `commit`, provide the
   **entire** lowercase 40-character SHA. A mutable PR/branch resolves to the
   actual GitHub HEAD and the plan includes the immutable `resolved_sha`.
3. Review `eligible`, every `blocker`, `source.resolved_sha`, the certified
   `archive_sha256`, and the `plan_sha256`. Any missing or mismatched identity
   prevents applying.
4. After separate owner approval, call
   `mad4b/control-plane-selected-head-apply` with the same selection/reason,
   `expected_plan_sha256` from the reviewed plan, and exact
   `confirmation: "INSTALL SELECTED HEAD ON STAGING"`.

No update occurs on discovery, Status, ordinary MCP connection, an unrelated
MCP request, or changes to the GitHub branch. There is no automatic polling or
background deployment. An approved execution is one exact, on-demand action.

## Host-level opt-in

For an approved Staging environment only, require:

- `MAD4B_SCP_SELECTED_HEAD_UPDATES_ENABLED === true` (new, **off by default**);
- existing `MAD4B_SCP_STAGING_CANDIDATE_UPDATES_ENABLED === true`;
- explicit `WP_ENVIRONMENT_TYPE=staging` and a bound, clone-protected,
  enrolled Site Profile; an implicit Production WP environment is not enough;
- `manage_options` / `update_plugins`, enrolled owner/admin, same-app
  verified OAuth bearer and step-up scope, current effective mutation policy
  and central approval for both selected-head and candidate-upload actions.

All Production calls fail closed, even when a staging hostname is present.
Do not set these flags in Production.

## GitHub artifact gate

Selection is **not** an arbitrary Git source install. It does not download
`archive/refs/heads/...`, accept a caller URL, or run source code in WordPress
to create a package.

The resolver uses the fixed GitHub API to pin a PR, Branch or Commit, then
looks under the repository-owned immutable release tag for:

- `mad4b-site-control-plane-update-<exact_SHA>.json`;
- `mad4b-site-control-plane-<exact_SHA>.zip`.

The manifest must provide exact `source_commit_sha`, `archive_sha256`,
`build_fingerprint`, `package_manifest_digest`, `size_bytes`,
`package_url`, `version`, `release_verdict_run_id` and
`release_verdict_success=true`, plus either an approved
`staging_candidate_certified=true` or the existing
`published_from_master=true` and `release_root_trust_verified=true`
release-root attestations. Missing packages or absent certification are
**blockers**, not reasons to bypass artifact verification.

Certification and publication of PR-specific ZIPs are separate governed
build/release operations; this patch does **not** assert that every arbitrary
PR already has a certified installable artifact. The current master pointer
remains unchanged even when a selected candidate exists.

## Mutation and recovery

The selected-head implementation downloads the exact release-owned ZIP, checks
its byte length and SHA-256, rechecks the source HEAD and OAuth permissions,
then hands off to the already existing Staging candidate update coordinator.
That coordinator rechecks immutable ZIP/provenance/build identity and source
again and owns runtime maintenance lease, backup, installation, activation
preservation, independent installed readback, and compensating rollback.

If the PR/branch moves, the release changes, a grant is revoked, GitHub
is unavailable, or Staging binding is stale, the execution aborts and
requires a new reviewed plan. A valid 40-byte Git SHA is **not** itself evidence
that a ZIP exists or that its installation is safe.

## Acceptance matrix

Run on the exact checked-out PR HEAD:

```sh
php -l wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-selected-head-update.php
python3 wp-content/plugins/mad4b-site-control-plane/tests/selected-head-update-contract.py
for scenario in disabled normal master missing uncertified drift bad-archive; do
  php wp-content/plugins/mad4b-site-control-plane/tests/selected-head-update-runtime.php "$scenario"
done
```

Native suite success is **not** live site acceptance. Before enabling the host
flag, certify the ZIP, install only to approved Staging through its separate
step-up approval, execute MCP discover → plan → apply → readback, independently
verify the installed filesystem and source digest, then test rollback and
unavailable-transport recovery. A source-only fix on PR #258 does not upgrade
the currently installed All Royal Egypt rc.96 by itself.

## Not supported or implicitly authorized

- Changing default WordPress auto-update behavior;
- automatic install when a PR gets a new commit;
- Production deployment, auto-promotion, or master merge;
- retrieving private repo access tokens from MCP callers;
- accepting user-provided URLs/paths/untrusted ZIPs;
- bypassing Release Verdict, external Staging acceptance, or maintenance locks.
