# DFSB 0.4.0-alpha.15-rc.1 — release review, no deployment authority

## Motivation

Issue #565 tracks signed source review => independent package certification => governed Staging apply.
The previously signed `0.4.0-alpha.14-local.1` review ZIP remains a **review artifact** with valid Sigstore provenance; it cannot be installed as a new exact-version release or promoted just by changing its name.

## Scope of this PR

1. Separate **`0.4.0-alpha.15-rc.1` committed source version**. It is a release-review candidate, not a certified provider release.
2. `build_candidate.py` retains its default `-local.` behavior and deterministic bytes at the original reviewed source SHA. The new `build_release_candidate.py` requires **exact repository HEAD, explicit RC version, committed WordPress version parity and clean Git source**. It returns unsigned, `certified:false` ZIPs and embedded source/file integrity manifests. It cannot attest or publish.
3. A new *manually dispatched* `dfsb-release-review-attestation.yml` on **master only** requires the exact reviewed PR number and head SHA, verifies that the PR was merged and approved by the owner through the exact `OWNER_ATTEST_SINGLE_OWNER` comment, then rebuilds twice and verifies source/installed-content manifest. The workflow produces Sigstore and GitHub-indexed verification evidence and uploads a **30-day review Artifact only**. It has `contents: read`; it cannot create a GitHub Release, certify a provider or install a plugin.
4. The runtime `BootGuard` now always requires:
   - Valid embedded source identity and complete installed-content manifest.
   - A **valid detached exact-package receipt**.
   - Independently reviewed exact `MAD4B_SCP_Provider_Contracts::get_for_version` contract for `dynamic-filter-seo-bridge` (matching version, archive digest, plugin file, integrity requirement).
   - Exact source registry record with approved `source_commit_sha == candidate_source_commit_sha == embedded git_sha`, same version and SHA, `package_certification:certified` and `review_state:source_reviewed_artifact_certified`.
   - These gates are rechecked even after a stored hold=false and even when `DFSB_FORCE_FULL_BOOT` is defined. The override can never replace a provider certificate.

## Review evidence produced on isolated VPS QA

- PHP 8.3 and 8.5 mocked-authority tests pass (missing receipt, SHA mismatch, source mismatch, unapproved source, version mismatch, forced unsafe boot all deny).
- Versioned ZIP builds reproducibly with 180 tracked source files plus embedded identity and content manifest.
- `verify_candidate.php` confirms exact identity and Safe Boot hold; deliberate modified BootGuard source fails verification.
- `workflow-exact-head-hygiene.v2` examines 108 workflows without issues. The new YAML and all shell blocks pass parsing.
- These mocked authorities are **unit-test fixtures**; not real provider certificates.

## Separate approval / pending gates — not completed by this PR

1. Merge this PR only after **new exact-head owner attestation and required GitHub CI**. Run the versioned signing workflow **on merged master** with PR number, merged PR head and exact version; require successful attestation and Artifact readback.
2. Obtain independent provider certifier approval of the exact signed archive and source/identity, along with a version-matched detached receipt. Never synthesize approval from an unsigned ZIP or trust an agent-generated certificate.
3. Publish a long-lived immutable release only with GitHub's **Immutable Releases enabled** and confirmed through the administrator-authenticated `GET /repos/mad4bdigital-ai/WordPress/immutable-releases` API. The public unauthenticated endpoint returns 401; its state was not verified and immutability has NOT been enabled here. A short-lived Actions Artifact does not satisfy long-term immutability. The permanent publication lane requires separate explicit authorization.
4. After independent approval, submit a separate reviewed certificate PR to update `certified-providers.json` and the neutral source row of `cross-plugin-sources.json`. Do not modify the legacy ETG source.
5. Update the **already running** `etg-commerce-staging-php` Control Plane via its governed deployment process with backup, checksum/readback and rollback; it currently has only the legacy source. Never paste a registry file onto live Staging to bypass review.
6. Obtain a read-only eligible `mad4b/plugin-package-plan`, then separately authorize guarded Staging apply and optional Full Boot retry. No Production rollout or policy changes are part of this PR.

## Current disposition

`RELEASE_CANDIDATE_SOURCE_REVIEW`; `CERTIFICATION_PENDING`; `STAGING_APPLY_NO_GO`; `PRODUCTION_NO_GO`.

This PR modifies GitHub **source only** and does not install or activate anything.
