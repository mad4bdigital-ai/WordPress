# MAD4B — one central Staging for all managed companies

## Deployment policy

`https://tours.dev.mad4b.com` is the **single canonical MAD4B Staging** for shared code. A dedicated Staging site per company is **optional**, not a prerequisite to a company Production deployment.

The source may be `local_git` (a registered, protected local Git repository) or `remote_git`. GitHub and WordPress.org are not mandatory. An optional MAD4B Package Certificate is not a deployment prerequisite for private source builds. Exact source identity, safe paths, artifact integrity, authorization, backup, actual Staging tests and rollback checks are required.

The target sequence is:

1. A fixed Git commit is turned into one immutable artifact, with manifest SHA-256.
2. Install and test that exact artifact on **central** Staging `tours.dev.mad4b.com` (install, smoke, rollback, any relevant schema rollback).
3. Central Control MCP publishes an **independently verified, server-owned** release readback to its trusted release registry.
4. At each target company Production site, use `mad4b/central-company-rollout-plan` to inspect live WordPress/PHP versions, installed and active dependency plugins, target version, and risky schema changes without mutating the site.
5. Only a company-specific plan with **fresh, verified central evidence** and no compatibility blockers may be handed to an **independently authorized** Production executor. The installer must compare the same immutable artifact hash again, back up first, perform installation, verify real readback and roll back if a check fails.
6. Record each company's outcome. One company's failure does not change another company's data or promotion state.

There is no `company_staging_required` gate. For risky database migrations or unusual plugin configurations an **optional temporary isolated rehearsal** may be used; it is not required for every company.

## Implemented in this source change

- Registered read-only ability: `mad4b/central-company-rollout-plan`.
- The request accepts **only** `release_sha256`; client-provided PASS, company environment, origin, package URL or compatibility values are rejected.
- Live company compatibility comes from the target WordPress runtime, not the caller.
- The central Staging origin is fixed to `https://tours.dev.mad4b.com`.
- The read-only plan requires a verified central readback matching the exact immutable artifact, source commit, manifest and a recent install/smoke/rollback PASS.
- It does not require company Staging or a MAD4B package certificate.
- Nothing in the plan approves or executes Production, grants owner authority, rewrites a Production policy, or turns a customer-supplied receipt into trust.
- The hermetic tests cover normal compatibility and failure cases: missing trusted central evidence, wrong Staging origin, mismatched artifact, stale result, unpinned source, failing tests, unverified target identity, PHP/WordPress/dependency incompatibility, downgrade, migration rollback and unsafe paths.

## Integration contracts still pending

The ability intentionally fails closed until **`MAD4B_SCP_Central_Release_Registry::verified_readback($sha256)`** exists. This provider must be backed by authenticated MAD4B central Control MCP evidence and independently verify the central site's identity, release content and execution readback; it cannot accept arbitrary JSON copied into an option or sent by the caller. It returns a trusted record with:

- `contract=mad4b.central-staging-release-readback.v1`
- `staging_origin=https://tours.dev.mad4b.com`, `environment=staging`
- `registry_readback_verified=true`, `staging_pass=true`
- `source_type=local_git|remote_git`, exact `source_commit` (40 hex)
- exact `artifact_sha256` and `file_manifest_sha256` (64 hex each)
- bounded integer `tested_at`
- `checks.install`, `checks.smoke`, `checks.rollback` and conditional `checks.schema_rollback`
- bounded `requirements` including target plugin file/version, PHP and WP minimums and required active dependency plugins.

**This readback is an internal verified result, not a separate mandatory Package Certificate.** Its issuer must be the central MAD4B runtime, with controlled authenticated server-side delivery and no editable PASS overrides.

Remaining implementation: central release registry provider, governed Production company dispatcher, exact artifact transfer and readback, data migration lifecycle, centrally recorded company rollout journal and explicit mapping to Content Hub/tenant identity. The existing Production automation still requires its old signed test-attestation certificate for plugins; do not remove it before the trusted central evidence path is connected and validated.

## Migration / rollback

- Rollout starts as an additive read-only capability. Existing Production updater and per-site staging assumptions in old promotion paths are not globally removed by this PR.
- During rollout, use the new central plan only for new company deployments after the trusted evidence provider and dispatcher are connected.
- The old Staging instances must not be deleted automatically. Sunset each only after company-origin/binding migration, verified central artifact delivery, backup restore testing and recorded owner approval where required.
- If a central release or company preflight fails, do not advance that company; preserve independent other-company operation.
- Revert the plugin-transaction boot registration to disable the plan without changing installed plugins, orders or content.

## Release state

Source and hermetic CI candidate only. **No Production mutation and no automatic company Staging deletion.** The production executor and central evidence provider are not yet connected.
