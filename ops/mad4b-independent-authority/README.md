# MAD4B Independent Operational Authority — Source Parity REV153

This directory preserves the **exact-source PHP classes and standalone MariaDB integration test** that passed isolated QA on Staging on 2026-10-29. It is a **source-review candidate**, not an installable WordPress plugin, a runtime deployment, an owner approval, or an operational certificate.

## Contents

- `src/IndependentAuthority.php`: canonical signing/identity/QA evidence verification contract; private signing keys are never included.
- `src/AuthorityLedger.php`: transactional permit register/reserve/complete state machine with replay protection.
- `tests/standalone-ledger-qa.php`: isolated MariaDB test with in-memory temporary signers and an ephemeral QA database. It **must not** be invoked against the operational database or public deployments.
- `evidence/REV153-REAL-MARIADB-LEDGER.json`: authentic 18/18 Staging isolation test results, not an external CA attestation.
- `evidence/SHA256-REV153.json`: exact source digests verified on the VPS before upload to GitHub.
- `docs/ADR-001-Dual-Authority-Modes.md`: mode decision and fail-closed invariants.

## Operational modes

- Preferred: `independent_authority`, presently **not enabled for real operational permit issuance**.
- Preserved: `github_governed`, as an explicitly selected alternate workflow with no automatic fallback.
- GitHub is allowed for source provenance, PR, CI and code review, **not an independent operational issuer or permission grant**.
- No automatic company-site, Shared Media, Staging deployment or Production activation is authorized by the existence or merge of these files.

## Acceptance boundary

REV153 verified real InnoDB row locking, single-use nonce/reservation, exact company/artifact/plan binding, signed *synthetic* permit and completion receipt, audit transitions and cleanup of QA users/schemas. Live owner MFA, separate production CA custody, registered operational QA Runner and independent runtime execution attestation are **not** completed. Operational issuer and service writes stay blocked.

Keep runtime keys and credentials outside GitHub. Do not run test against production or operational Authority databases.
