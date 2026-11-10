# MAD4B Control Plane Self Update — site-neutral signed release policy

## Canonical admission

Environment names are **not** authority. The normal Control Plane update paths (WordPress-native manual update, `governed_file_upload`, and `governed_native_release_pull`) now use one shared private predicate `MAD4B_SCP_Self_Update::environment_allowed($remote)` with the same admission rules on `staging`, `production`, `development`, and `local`:

- `Site_Profile::status()['authority_ready']` and a valid nonempty Site UUID
- enrolled exact site origin, `home`/`siteurl` and deployment identity
- `Site_Profile::managed_runtime_enabled()`
- additionally, for any mutating remote apply, `Site_Profile::write_enabled()` (including the existing explicit Production write-confirmation requirement)
- independently: the original WordPress `update_plugins` capability, original Control Plane authorization/grants and exact reviewed one-time plan and approval

The admission predicate **does not issue a grant, enable write, perform a release, or bypass any signed release check**. A policy `ready` value means the local site prerequisites are met; it is not a successful update receipt.

## Release integrity

`governed_native_release_pull` still takes its target from the fixed repository-owned GitHub Release pointer and immutable manifest (not from a caller-controlled URL, ZIP, path or commit). The pointer and manifest must agree, with a successful Release Verdict, master publication, root trust, exact source SHA, archive SHA256, build fingerprint and package manifest digest. ZIP path/symlink checks, bounded archive size, embedded provenance, protected staging, backup, exact installed readback, audit, and rollback are unchanged. WordPress auto-update stays off under this policy. Do not transfer a company's Site Profile, product data or grants from the demo to another tenant.

`governed_file_upload` accepts only the bounded base64 archive tied to the signed release manifest and approved exact plan. The revised `self_update` handoff manifest is the **static support contract**; any `production_allowed=true` there means eligible **when** the same site and signed-release policy passes. `cached_status()` is the dynamic runtime evidence; it never authorizes itself.

## Bootstrap and unpromoted candidates

- `governed_bootstrap_release_pull` remains a narrow emergency repair for a **pre-existing** clean, exact grant whose candidate binding is stale. It requires enrolled owner/admin, verified step-up bearer with exact ChatGPT client, explicit confirmation, existing clean checkpoint and signed release. It must not grant or restore new authority or touch Breakglass. The bootstrap candidate-drift eligibility no longer requires the literal `staging` environment.
- Unpromoted PR/branch Candidate Upload remains a **separate, explicitly opted-in testing lane**. It retains the legacy `staging_candidate_upload` channel identifier, source selector and Staging opt-in to prevent accidentally treating a PR as a signed master release on Production. It is not a normal update lane, and must never be used to bypass the signed release review.
- `selected_head` experimental releases remain independently constrained and disabled by default.

## Regression evidence

Run from repository root:

```sh
python3 wp-content/plugins/mad4b-site-control-plane/tests/self-update-staging-candidate-contract.py
python3 wp-content/plugins/mad4b-site-control-plane/tests/self-update-dual-channel-contract.py
```

The second test calls PHP runtime fixtures; execute in an environment with PHP, or from an isolated PHP container with the current source plugin. Also run the following PHP fixture scripts against the same source:

- `self-update-auto-update-state-runtime.php` (positive Staging and Production, negative unmanaged Production/write-disabled)
- `self-update-candidate-drift-bootstrap-runtime.php` (positive Production clean binding and negative inaccurate grants/Breakglass)
- `self-update-selected-head-release-trust-runtime.php` (modern manifest validation and 26 tamper checks)
- `self-update-pointer-runtime.php`, `self-update-readback-runtime.php`, `self-update-continuation-policy-runtime.php`.

On an authorized WordPress CLI session, run read-only `ops/site-neutrality/tests/test-self-update-site-policy-wpcli.php`. On tours.dev: `managed=true`, `write=true`, signed release policy admits update-capable managers; on Wayfatours: `managed=false`, `write=false`, mutation is denied even though the environment is Production. No actual release apply was exercised during this rollout.

## Rollback and release reconciliation

Preimage-gated, atomic file deployments created backups in the `/opt/mad4b-company-provisioning/reports/media-universal-20261110/composite-write-candidate/` VPS report directory. Only the `self_update` subtree was merged into the local `config/staging-deployment-handoff.json`; all other live configuration (notably `target`) was preserved. Review exact hash/current-version before any rollback.

The source code and policy changes are committed locally. The local Git branch does **not** automatically become an official GitHub Release; merging/reconciling with the central GitHub release tree, publishing an approved signed ZIP, and testing one real signed release on an eligible noncustomer canary remain separate tasks. Never claim this code-file deployment is a successful original signed release receipt.
