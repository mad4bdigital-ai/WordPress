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
