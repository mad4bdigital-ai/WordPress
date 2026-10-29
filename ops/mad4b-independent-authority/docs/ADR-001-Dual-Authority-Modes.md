# ADR-001 — Dual Operational Authority Modes

Date: 2026-10-29
Decision: Accepted as project architecture / configuration policy.
Implementation status: Design agreed; **runtime selector, independent CA enrollment and live permits are not yet deployed or certified**.
Scope: MAD4B Control Plane, tenant release decisions, Shared Media, deployment permits and operational completion certificates.

## Two preserved modes

### Mode 1 — `independent_authority` (CURRENTLY SELECTED POLICY)

MAD4B Independent Operational Authority is the desired operational source of truth for approvals, permits, and completion certificates. It accepts release artifacts regardless of whether they came from GitHub, local builds, or a signed delivery package. It requires independent owner and platform-owner identity, MFA evidence, an authorized QA Runner, artifact SHA-256 digest, environment and company site UUID, a central trusted issuer, atomic one-time permit consumption, signed runtime attestation, audit integrity and revocation.

Neither a GitHub Pull Request, GitHub Actions run nor GitHub attestation is a prerequisite for its authorization decisions. GitHub can still be used to host and review source code.

**Important state:** the REV145 candidate and QA02 isolated InnoDB tests do not themselves constitute a deployed CA, an enrolled signing key, or a live authorization. Until these exist, authorization must remain DENIED/BLOCKED rather than defaulting to GitHub approval or creating an unsigned permit.

### Mode 2 — `github_governed` (PRESERVED, OPTIONAL)

The established GitHub-centric repository source / PR / CI / owner-attestation and release controls remain available as a separately selected workflow, with existing repository branch protections intact. It must be selected deliberately by an authorized platform owner for a particular release scope; it is not automatically activated when the independent authority is unavailable.

This mode may produce development/CI provenance and use its existing approvals, but cannot automatically mint an Independent Authority permit or operational certificate. Existing merged PRs remain historical source records; they do not retroactively become internal authorization.

## Cross-mode invariants

1. A release has exactly one **authoritative operational approval mode**, bound to a release ID, artifact digest, target site UUID and environment; mode switching requires a fresh scoped approval, not a fallback.
2. Exactly-once permit consumption is enforced by a transactional, centralized authority ledger; no replay/cross-tenant use.
3. Both modes require runtime readback, environment isolation, security/QA results, and a controlled rollback plan. Approval is separate from successful deployment verification.
4. Independent QA and MFA proof must not be inferred from a PR comment, a local unit test, a WordPress administrator role, or a test signing key.
5. Keys are held by central custody; private signing keys never ship with plugins or tenant files.
6. No customer Production changes, public Shared Media activation or certificates are authorized by this ADR alone.
7. No silent authority downgrade; if the selected authority is unavailable the release is BLOCKED.

## Target policy representation (NOT a live runtime setting)

```yaml
authority:
  preferred_operational_mode: independent_authority
  available_modes:
    - independent_authority
    - github_governed
  automatic_fallback: false
  allow_mode_change_only_with_explicit_platform_owner_approval: true
  enforce_artifact_sha256: true
  enforce_tenant_site_uuid: true
  enforce_environment: true
  operational_certificate_must_follow_runtime_verification: true
  independent_authority_live_issuance_enabled: false # pending real CA/MFA/QA enrollment
```

## Completion plan

1. Integrate REV145 ledger into dedicated Authority database with a distinct least-privilege service account, transactional schema and tamper-evident audit.
2. Enroll enterprise signing key in controlled custody, provision and pin trust anchors; institute key rotation/revocation.
3. Bind platform and company owners to an MFA-backed identity provider; log distinct reviewer identity, exact release intent and expiration.
4. Enroll QA Runner and verify signed test evidence against the exact binary artifact digest and target environment.
5. Run independent full loop on isolated Staging: propose -> verify -> human approve -> sign permit -> atomically consume -> deploy under controller -> signed post-deploy attestation -> certificate -> revoke/reject/replay tests.
6. After that test is certified, enable mode 1 for approved Staging scopes. Keep mode 2 available for explicit use. Production remains separately gated.

## Relationship to GitHub

GitHub is allowed as a source channel and collaboration tool in both modes. In mode 1 it is **not an operational authority**. Repository protections, if a GitHub PR is used, are not bypassed. This policy does not change GitHub settings, CI, code, server access, WordPress sites, or databases.