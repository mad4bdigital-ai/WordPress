# DFSB neutral attested review candidate — controlled release handoff

## Source and scope

- Source subtree at PR #538, PR #556 and current master: `c8545970917f17e46cb4d4476371840821f950a7`.
- Original reviewed Git source commit: `8b391bb8af0e2505d9a9c5296d37d9ce420e54e7`.
- Recorded exact owner attestations: PR #538 HEAD `72e2168e8ca35d9f632ee260d51d7e8f200c111d`, PR #556 HEAD `09dd5c5712c8a469698b29153bfda3d70a580a50`.
- Original deterministic `-local.1` candidate ZIP SHA-256: `0cdc9ea4e99117b4ca80a127fb3d89c54e6b595587f1f64fe136199d106533fb`.
- New workflow: `.github/workflows/dfsb-neutral-attested-candidate.yml`, triggered on **master only** after this workflow is merged, or by a separately authorized manual run on master.

## What this workflow certifies

The workflow proves immutable source ancestry, plugin subtree equality, GitHub owner-authored exact-head PR comments, deterministic double build, exact pinned ZIP SHA-256, independent installed-content manifest verification, and **GitHub Actions cryptographic build attestation**. It re-verifies its own attestation before uploading the review ZIP and verification bundle.

**It does not** publish a GitHub Release, assert that independent provider review has happened, register a certified provider, activate the plugin, clear Safe Boot, or modify Staging/Production. Its artifact state is deliberately `SIGNED_REVIEW_ARTIFACT_NOT_CERTIFIED`.

## Separate independent approval and Staging gates

1. Review the GitHub-issued attestation, its issuer/workflow identity and bound archive SHA. Ensure artifact publication is immutable; do not infer a provider certificate from this CI job.
2. An authorized independent provider certifier must review and approve the exact package, its installable source manifest and runtime compatibility. This approval cannot be simulated by an agent-authored receipt.
3. Only after independent approval, create a separate reviewed PR to bind the immutable signed package identity to `config/certified-providers.json` and promote the existing candidate row in `config/cross-plugin-sources.json`. Both records must match source commit, version, plugin path and ZIP digest exactly. Legacy `etg-dynamic-filter-seo-bridge` remains independent.
4. WordPress environment must actually be enrolled as **staging** and pass read-only `mad4b/plugin-package-plan`, including provider/source validation and required roles. The Woo Lab currently reports `wp_get_environment_type()=local`, so it is **not** a certified Staging deployment target.
5. Use the governed `mad4b/plugin-package-apply` only after explicit authorization, backup/rollback and postconditions. Test Elementor/JetEngine/JetSmartFilters/WPML/SEO integrations, then attempt a guarded Full Boot retry only when `releaseReadiness()` is true.
6. Production rollout requires its own approval and proof. Never use `DFSB_FORCE_FULL_BOOT` or create a synthetic detached receipt to bypass a hold.

## Evidence verified on VPS, 2026-10-28

- A fresh source build from latest master was deterministic and source files match PR #538; the rebuilt ZIP has a different digest because the embedded Git identity points to master. Thus it is *not* substituted for the immutable pinned candidate.
- Checked current Woo Lab in isolated read-only PHP container: installed source and manifest valid, provider certified=false, detached receipt=false, Safe Boot blocked (`local_source_candidate_not_certified`).
- Signed-artifact workflow YAML and local boundary contract parsed and passed. **The GitHub attestation has not been issued until the workflow runs on approved master.**
