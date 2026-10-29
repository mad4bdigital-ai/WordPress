# DFSB RC15 — signed review intake (NOT Provider Certified)

**Date:** 2026-10-29  
**Tracker:** [Issue #565](https://github.com/mad4bdigital-ai/WordPress/issues/565)  
**Source PR:** [#567](https://github.com/mad4bdigital-ai/WordPress/pull/567), exact reviewed/owner-attested head `fbcfed264231a2553d197927617a9cdae67f5567`  
**Merge SHA:** `419b5ab0655ec7093eaf0c8b8968e81dbbd82fd9`  
**Owner-comment dispatcher PR:** [#576](https://github.com/mad4bdigital-ai/WordPress/pull/576), merged at `d6483dedaef8dba31afc8dabc94bafe7dd8b8737`.

## Review signed artifact — exact evidence

- Signed review workflow: [run #40826819043](https://github.com/mad4bdigital-ai/WordPress/actions/runs/40826819043), event `workflow_dispatch`, master at `d6483dedaef8dba31afc8dabc94bafe7dd8b8737`, **PASS**.
- Receiver workflow: [run #40826793466](https://github.com/mad4bdigital-ai/WordPress/actions/runs/40826793466), owner command [comment #6489267074](https://github.com/mad4bdigital-ai/WordPress/pull/567#issuecomment-6489267074), **PASS**.
- Signed review artifact: `dfsb-release-review-fbcfed264231a2553d197927617a9cdae67f5567`; GitHub Actions Artifact ID `12877465319`; archive digest `sha256:7482f104c7830b64f91068a12d748e81de452b8a89b244435447e81694dcb2d6`; retention until **2026-11-28T06:03:23Z**.
- Exact inner plugin archive: `dynamic-filter-seo-bridge-0.4.0-alpha.15-rc.1.zip`.
- **Inner plugin ZIP SHA-256:** `bb7ab6bf995f477d075198e766ba7b41954a0b3f0eb5fb4bef906c5ee73d9b66`. This is distinct from the outer Actions Artifact digest.
- Two reproducible **independent VPS isolated QA builds** from exact reviewed source SHA produced identical ZIP bytes with this same SHA; 180 source files verified, PHP 8.3 integrity `PASS`, Safe Boot `full_boot_blocked=true`.
- Signed workflow's `attestation-bundle.json`, `bundle-verification.json`, `github-verification.json` and `release-boundary.json` were downloaded and inspected. Both verification reports record the same ZIP subject SHA-256, GitHub OIDC workflow signer and `refs/heads/master`; `runDetails.metadata.invocationId` identifies workflow run #40826819043, attempt 1.
- Separate local inspection of the bundled DSSE envelope validated the ECDSA signature using the embedded Fulcio leaf certificate public key, the exact ZIP digest, the signer SAN and SLSA source dependencies. Local inspection did **not** independently re-validate the Fulcio root chain or transparency log trust anchor; GitHub's `gh attestation verify` results are part of the signed workflow evidence.
- `release-boundary.json`: `status=SIGNED_REVIEW_ONLY`, `provider_certified=false`, `staging_authorized=false`, `production_authorized=false`, `safe_boot_override=false`.

## Purpose of this PR / Registry authority

Change **only the neutral source's review candidate pin**:

```text
source id: dynamic-filter-seo-bridge
candidate_source_commit_sha: fbcfed264231a2553d197927617a9cdae67f5567
candidate_artifact_version: 0.4.0-alpha.15-rc.1
candidate_state: REVIEW_ONLY_SOURCE_PINNED
review_state: source_reviewed_artifact_pending
package_certification: pending
source_commit_sha: (empty)
artifact_sha256: (empty)
artifact_version: (empty)
production_promotion_attestation: (empty)
```

Do **not** mutate the legacy `etg-dynamic-filter-seo-bridge` source row; no Provider Contracts record for neutral DFSB is authorized by this change.

**Why the exact registered source remains PENDING:** SLSA/Sigstore signature authenticates a reviewed build's provenance. A trusted signer is not a substitute for an **independent provider certification decision**, an immutable durable asset, a detached release receipt bound to the installed package, or a separate Staging/Production permit.

## Next independent gates (NO-GO applies)

1. Confirm `GET /repos/mad4bdigital-ai/WordPress/immutable-releases` via a repository-admin-authenticated API response yields `enabled=true`; do not assert it from unauthenticated HTTP 401.
2. Publish a uniquely named immutable signed review Prerelease and verify release+asset digest readback. The 30-day Actions Artifact is not immutable distribution.
3. Independent provider reviewer issues **an explicit, versioned, digest-bound certificate** after verifying exact signed package+source. Never synthesize this record in a code change.
4. Separate owner-governed PR promotes `certified-providers.json` + neutral `cross-plugin-sources.json` version/source/archive fields, while retaining legacy ETG row.
5. Governed backed-up Control Plane rollout to **ETG Commerce Staging**; read-only `plugin-deployment-certification-plan` and `plugin-package-plan` must become eligible before any approved Staging installation, guarded Full Boot or site-side integration QA.
6. Production remains explicitly outside this review.

**Last read-only live Staging observation (29 Oct 2026):** `etg-commerce-staging-php` and `etg-commerce-staging-db` running; installed DFSB `0.4.0-alpha.14-local.1`; deployed registry contains legacy ETG source only; `BootGuard::releaseReadiness()={ready:false,reason:local_source_candidate_not_certified}`. No Staging or Production mutation here.
