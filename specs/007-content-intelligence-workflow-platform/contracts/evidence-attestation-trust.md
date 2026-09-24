# Contract — Evidence Attestation and Trust Distribution

Contract: mad4b.evidence-attestation.v1

## Purpose

Allow certification and release evidence to be reused across sites without trusting mutable local database flags.

## Evidence envelope

Attestable evidence includes:
- evidence_id;
- evidence_type;
- subject/artifact/capability identity;
- exact source/build/package identities;
- site/environment scope when applicable;
- evidence payload hash;
- issued_at;
- expires_at optional;
- signer key id;
- signature;
- attestation policy version.

## Trust roots

A verifier maintains an explicit trusted attestation-key set.

Trust in an OAuth signing key does not automatically imply trust for certification evidence unless policy binds the key roles.

## Key lifecycle

Evidence signing uses:
- current;
- next;
- previous;
- revocation status.

Rotation preserves a verification overlap appropriate to evidence lifetime.

## Revocation

Revocation may target:
- signing key;
- evidence ID;
- provider artifact;
- capability fingerprint;
- site compatibility result;
- release-ring promotion.

Revocation propagates to dependent eligibility.

## Reuse

A site may reuse central evidence only when:
- signature verifies;
- signer role is trusted;
- evidence is not expired/revoked;
- exact artifact/capability dependencies match;
- site compatibility evidence passes;
- policy permits cross-site reuse.

## Offline verification

Verification SHOULD be possible using cached trusted public keys and revocation snapshot within an explicit TTL.

Stale trust metadata never widens authority.

## Local evidence

Local canary/readback evidence can be appended to centrally attested evidence but cannot alter the signed central statement.

## Tamper tests

- database status changed from BLOCKED to CERTIFIED without valid attestation;
- signature mismatch;
- unknown signer;
- revoked signer;
- revoked evidence;
- artifact SHA mismatch;
- stale trust snapshot beyond TTL;
- site tries to reuse evidence for another artifact.
