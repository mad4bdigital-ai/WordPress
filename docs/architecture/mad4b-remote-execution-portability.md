# MAD4B Remote Execution — Multi-site portability

**Scope:** the shared MAD4B Site Control Plane and the remote Operator tooling. The historical ARE/ETG operational reports and the ETG Dynamic Filter SEO Bridge remain separate, site-specific assets.

## Fixed

- The Operator runner profile is no longer a literal `are-staging-unified-v1` in WordPress or the Python verifier.
- `MAD4B_OPERATOR_RUNNER_PROFILE_ID` is an **explicit per-enrolled-site configuration value**, bound to a current Staging Site Profile. An unset/invalid value rejects plan and submission; it is never guessed from the hostname.
- The external verifier **requires** the worker's independently pinned runner profile ID and current target fingerprint. Correct signatures but the wrong profile are denied.
- The read-only diagnostic consumer requires explicit `--site-root`, `--runner-directory`, `--wp-cli-binary`, and `--runner-profile-id` configuration. No Hostinger user/path default remains; the runner directory cannot reside under the WordPress tree.
- Shared Python/PHP utilities, tests, and GitHub Actions now have neutral path and file naming. Synthetic acceptance covers distinct site-bound profiles.
- A source CI guard detects customer domains, Hostinger home directories, site-specific Runner IDs and obsolete shared path references in general-purpose MAD4B PHP and shared tools.

## Separation of responsibilities

**Shared:** OAuth Operator session admission, request-local capability scope, semantic planning, signed Host Bridge job, worker admission, receipt verification.

**Per enrolled site:** `site_uuid`, origin, environment, runner profile ID, exact WordPress root, verified target fingerprint, pinned signing keys, SSH destination, least-privilege DB identity and operational approval.

**Site-specific historical artifacts:** ARE diagnostic receipts and reports retain their original contract and names; this source refactor does **not** rewrite already-issued signed evidence.

## Operational safety

There is **no default Operator execution backend**: `MAD4B_MCP_OPERATOR_EXECUTION_ENABLED` remains disabled and no production mutation is authorized. Merely setting a profile ID never creates Developer grants, authenticates a worker or permits Shell/Raw SQL.

Existing ARE Staging private consumer copies are not updated or restarted by this GitHub refactor. To enroll another site, use a separate private runner profile, keys and verified SSH identity, and complete full staging acceptance.

## Broader MAD4B codebase review

A search of MAD4B runtime PHP and shared tooling found customer-domain literals mainly in tests, browser fixture cases, and acceptance documentation. Those remain valid when labeled as examples. The ETG-specific SEO plugin is intentionally not renamed. The shared source gate is focused on executable runtime and portable tooling, while preserving those legitimate fixtures.
