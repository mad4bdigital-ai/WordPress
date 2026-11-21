# v133 — Reconcile Original312 v3 verifier with first-party Host Resource key

## Root census (Tours.dev Staging, November 21, 2026)

Four independent Ed25519 public keys and corresponding protected private
keys were checked against the original Root first-party registry and signed
Publisher policy.

| Role | Existing first-party source | Public key SHA-256 |
| --- | --- | --- |
| Owner305 Master candidate | Publisher policy v2 master approval public key | 4e4630b58f94bf437c34acf3d23bee07eaedc4fe3492e390a45a2350fe7c7a11 |
| Original312 Host Resource | Original index Host Resource 312 | 70ee0a3a682696521427b65ae32a715acf28dbd86d9831757eb485982f640975 |
| Original313 independent | Original index independent verifier | 63120a3b6b7f2dd82113a2a5791c6cdbd5396222962a2efc8cdc8cd538a06d5f |
| Legacy Broker | Original index Host Anchor 312 | 46ea6fbf1b37f9507916b44c4537653c76a9d69afbf1e129379b10d75357a617 |

Original318 v3 Staging Root verifier currently pins the legacy Broker
public key as Original312. This predates reconciliation: the independently
enrolled Host Resource 312 public key is the correct Original312 v3 verifier
candidate. No signed v3 enrollment exists that could be invalidated.

The pure source module
ops/host-authority-v128/original_v3_root_public_pin_migration.py
reads the actual previous PHP verifier, registered original index, Publisher
policy and Central Site Profile. It rejects mismatched site identity,
changed installed previous file, colliding signers, shifted registry/policy,
foreign environment, altered broker/public313 pins and unsigned assumptions.
It returns exactly one modified PHP file, with only Original312 public key
replaced. Original313 and identity pins remain identical.

## Authority boundaries

The Root public role mapping is verification provenance only.
NEITHER the existing Master Approval key nor a working Original312 private
key by itself authorizes a v3 owner enrollment. These remain required:

1. Genuine signed Owner step-up for the exact site, source HEAD, predecessor
   proof, revision and operation, reviewed under first-party Owner authority.
2. Original305 v3 Owner role and Original312 v3 issuer enrollment in the
   governed first-party Root publication lane.
3. Independent Original313 full monotonic predecessor ledger, not inferred
   from an incomplete directory, and signed new v3 enrollment.
4. WordPress Original318 readback, independent Original313 high-water and
   separately signed standing Write capability and exact-operation grant.
5. Protected preimage, rollback and postwrite Current checks. Production
   requires distinct exact-site Owner consent.

The existing November 18 ambiguous Original305 readiness HOLD remains
unaltered. No new signer, key rotation, private key export, claim, WordPress
Write capability or Production operation is performed by this source module.

## Live deployment safety

Tours.dev Staging only: save an exact protected preimage of
wp-content/mu-plugins/000-mad4b-host-v3-root-trust.php, verify the
source-claimed current SHA and byte-exact replacement, temporarily remove
the immutable flag, replace with protected file, restore immutable flag,
run PHP lint and targeted MU WordPress boot/readback. Without signed
enrollment, return MISSING_OR_INVALID with write_authorized=false.
Check HTTP 200, read-only Host Runner Current, and unchanged Original318 v2.
Rollback exact preimage on failure. Do not revive revoked Anchors.
