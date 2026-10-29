# MAD4B Single-Owner Authority — Staging source candidate

One verified owner may cover Platform Owner, Company Owner and Security Reviewer for an explicitly enrolled site. The identity assertion is recorded once, not cloned into fake approvals.

## Compatibility and controls

- Legacy three-human issuance and its policy digest remain unchanged.
- A separate versioned policy accepts one current WebAuthn/MFA platform-owner identity and an independently signed owner/site grant covering exactly the required roles.
- Identity issuer, enrollment signer, QA signer and operational CA have separate trusted keys. Independently signed QA, exact site/intent/plan/artifact/rollback hashes and nonce remain mandatory.
- The signed permit explicitly identifies single_owner mode, the covered roles, one identity receipt and a hash of the independently enrolled owner grant.
- Revocation is checked at issuance, verification and atomic reservation; the old ledger refuses new owner permits unless given an authenticated registry check.
- Test keys are ephemeral and kept only in QA process memory. No operational CA, human identity, provider certificate or trust anchor was fabricated.

## Deployment gates

1. Review PR and CI; run isolated PHP contract tests.
2. Apply schema-migration.sql only to an isolated QA authority DB first; test concurrent ledger, revocation, duplicate nonce, and rollback. Actual Staging DB migration requires separately approved database change.
3. Enroll real site ownership, one WebAuthn/MFA principal, trusted revocable owner-grant registry and operational CA through authorized enrollment.
4. Integrate a real issuer and controller using the trust registry and independent signed QA; keep live_issuance_enabled=0 until exact native review permits change.
5. DFSB package certification and immutable release receipt remain independent prerequisites. Never treat single-owner approval as plugin provenance.

This bundle is staged and test-only. No real issuance or Production mutation.
