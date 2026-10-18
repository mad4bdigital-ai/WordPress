# ETG Cross-Plugin Deployment — P0 Gate Acceptance

Source candidate (not a deployment certificate): [`76ef918d8485c42e92dd38b3725efd4de5471556`](https://github.com/mad4bdigital-ai/WordPress/commit/76ef918d8485c42e92dd38b3725efd4de5471556)
Plugin: `wp-content/plugins/etg-dynamic-filter-seo-bridge`, `0.4.0-alpha.13`.
Candidate source is an immutable, merged `master` commit. PR #423 has not changed ETG plugin files relative to it.

## Separation of responsibilities

The source registry carries `candidate_source_commit_sha` and `candidate_artifact_version` **only as an input to a non-authorizing deterministic build**. The authoritative `source_commit_sha`, `artifact_sha256`, `artifact_version`, `package_certification`, `review_state`, and `production_promotion_attestation` remain pending/empty until independently reviewed against the exact packaged bytes. **No source registry edit alone creates a valid authority grant**.

The workflow `MAD4B Cross Plugin Source Artifact Candidate` creates an artifact and a machine-readable receipt on a PR touching the pinned registry/build code. It checks out the **exact pinned source SHA**, prohibits dispatcher SHA override, runs hermetic tests, and does not install, publish or certify anything. Archive contents and digest must be audited from the actual Actions artifact. A workflow receipt expressly has `outcome_authorizes_deployment=false`.

## P0 acceptance matrix

| ID | Scope | Required evidence | Status on PR #423 candidate |
| --- | --- | --- | --- |
| P0-01 | Source identity | Immutable SHA, registered directory/entrypoint, matching version, reviewed diff | Source candidate pinned; independent source review pending |
| P0-02 | ZIP certificate | CI candidate archive + receipt, two-build hash equality, SHA-256, size and file list; separate certification | Build workflow available; certificate **not** issued |
| P0-03 | Production promotion | Exact digest-bound human promotion approval with Production scope and expiry | **Blocked**, no attestation |
| P0-04 | Production executor | Independently reviewed Production installer and rollback, one-time approval, audit and readback | **Blocked**, executor intentionally unavailable |
| P0-05 | Staging acceptance | Install/replace on authorized Staging, separate readback for ETG/ARE where applicable, activation state, WPML, Rank Math, AJAX, browser parity, backup/rollback | **Not certified** by repository CI |
| P0-06 | MAD4B Write Authority | Post-deployment fresh site-specific Owner Review digest, OAuth Step-Up, no stale/unreviewed grants, current Candidate Binding, audit/journal parity | **Blocked** until new build installed and owner approves each site |

**Decision:** Candidate build is source-only and not a release. Staging/Production Cross-Plugin Apply remain NO-GO while certificate/provenance is absent. Do not set `package_certification=certified` or fill a speculative archive digest. Production additionally requires a separately implemented and certified installer; Staging authorization cannot be reused. Keep Developer/Breakglass and raw SQL disabled.

## Closure evidence

1. Download the exact GitHub Actions artifact `cross-plugin-etg-dynamic-filter-seo-bridge-76ef918d8485c42e92dd38b3725efd4de5471556` and independently verify the archive SHA and internal `etg-dynamic-filter-seo-bridge/etg-dynamic-filter-seo-bridge.php` version.
2. Attach review of source and archive contents, source tree equality, and archive receipt. Certified archives must be independently addressable as trusted artifacts under an immutable SHA, not assumed from the mutable workflow run.
3. Update the final certificate fields **only** in a dedicated review commit, verifying certified MAD4B provider authority resolves the same source/version/archive. Do not claim this just because the deterministic build succeeded.
4. Obtain owner-approved Staging plan; perform rollback/readback tests, including unchanged front-end behavior and independent admin activation. Confirm audit and operation journal states.
5. After Staging acceptance, separately review Production installer + promotion attestation and pass production-specific no-breakglass, one-time approval and rollback acceptance.

This document does not assert that an ETG package was installed, certified, or approved.
