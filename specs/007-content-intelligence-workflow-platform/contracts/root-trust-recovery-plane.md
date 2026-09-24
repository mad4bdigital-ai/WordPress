# Contract — Root Trust, Platform Provenance and Out-of-Band Recovery

Contract: mad4b.root-trust-recovery.v1

## Root principle

The running MAD4B Control Plane MUST NOT be the sole authority that certifies its own executable identity.

## Release provenance root

A platform release identity is established outside the candidate runtime using repository/build evidence such as:
- source commit;
- protected build workflow/run;
- artifact digest;
- package manifest digest;
- dependency/SBOM evidence where applicable;
- release attestation/signature;
- trusted release signer/issuer.

Site runtime only reads back and proves it matches the externally established identity.

### Canonical package bytes vs producer attestation

The installable Control Plane package has one canonical byte identity for one exact source/dependency/package-manifest identity. Package-internal provenance therefore MUST be producer-neutral and reproducible: workflow name, workflow run ID, artifact-upload ID, timestamps, ZIP entry ordering and filesystem mtimes MUST NOT make two otherwise identical trusted builds produce different plugin archive bytes.

Producer identity is still mandatory trust evidence, but it belongs outside the canonical package bytes in the release/package attestation, artifact metadata and exact-build receipt. The outer evidence binds the protected workflow/run and signer to the canonical archive SHA-256.

Both General Distribution and Live Acceptance MUST use the same deterministic package builder. For the same exact source and dependency identity they MUST therefore converge on all of:
- package manifest digest;
- build fingerprint;
- canonical package provenance;
- exact Control Plane archive SHA-256.

A matching manifest/fingerprint with a different archive SHA is insufficient for exact-artifact acceptance. It is treated as packaging lineage drift until byte parity is proved.

## Key-role separation

Trust roles are separate:
- OAuth token signing;
- evidence attestation;
- release/package attestation;
- emergency recovery credentials.

Trust in one role does not imply the others.

## Recovery Plane

A minimal out-of-band recovery path MUST remain usable when the WordPress Control Plane itself cannot boot.

Recovery capabilities are intentionally narrow:
- read host/runtime health;
- retrieve bounded logs;
- identify installed package hashes;
- disable a known-bad MAD4B/adapter package;
- restore a previously attested known-good package;
- restore a governed backup where separately authorized;
- revoke/rotate designated compromised credentials;
- recover connector/tunnel/service required to re-establish normal control.

## Isolation

Recovery Plane:
- is outside ordinary Content/Workflow capability catalog;
- has separate identity/grants/audit;
- cannot publish content or perform arbitrary business mutations;
- cannot silently create new steady-state authority.

## Recovery artifact

Every recovery action binds:
- incident/reason;
- exact target/current state;
- known-good target identity;
- approval/emergency policy;
- recovery receipt;
- post-recovery normal-control verification.

## Self-test

Release readiness includes a proof that a broken/disabled Control Plane can be restored through the Recovery Plane without relying on the broken plugin path.
