# Host v132 — Original305 Owner approval for persistent v3

## Live Root facts, Tours.dev Staging, 2026-11-21

| Existing public identity | Public SHA-256 | Proven authority |
| --- | --- | --- |
| Original Broker | 46ea6fbf1b37f9507916b44c4537653c76a9d69afbf1e129379b10d75357a617 | Current legacy Original312; cannot also be an independent v3 Original305 signer if Original312 retains that key |
| Master Approval Root file | 4e4630b58f94bf437c34acf3d23bee07eaedc4fe3492e390a45a2350fe7c7a11 | NOT enrolled as Original305 v3; no implicit authorization from its filename |
| Dedicated Original312 Root file | 70ee0a3a682696521427b65ae32a715acf28dbd86d9831757eb485982f640975 | NOT enrolled as Original312 v3 |
| Independent Original313 | 63120a3b6b7f2dd82113a2a5791c6cdbd5396222962a2efc8cdc8cd538a06d5f | Registered existing independent verifier |

Root public verifier bindings are installed on Tours.dev Staging and immutable.
The v3 WordPress reader correctly returns MISSING_OR_INVALID because the
owner-approved v3 enrollment and independent high-water are absent.

The original Original305 signed host-readiness service has a real sticky
ambiguous-write HOLD from 2026-11-18. Do not retry its ambiguous write or
delete the marker. The older diagnostics have an obsolete static index SHA.
The new read-only v132 preflight validates the CURRENT enrolled v8 provider
manifest against its index and reports this gap without changing the HOLD.

An independent systemd safety condition was added to the Original305
readiness service: while HOLD exists, no automatic signer retry is launched.
The timer remains active, and the existing incident stays preserved.

## New signed owner-review admission

The file ops/host-authority-v128/original305_v3_owner_provider.py:

1. Requires exactly the pinned site's proposed v3 enrollment and a fresh
   independent Original313 signed predecessor including previous revision.
2. Creates an unsigned request containing exact enrollment SHA-256, prior
   proof SHA-256, source HEAD, target UUID and host, operation ID, request ID,
   human Owner actor digest and expiry.
3. Requires an independently authenticated and signed human Owner-stepup
   decision, verified against the Owner-stepup public identity already
   enrolled in the first-party Root trust. No Master Admin role, Site
   Profile option or ChatGPT text can substitute for this signature.
4. Uses a separately registered Original305 private signer, requiring
   independence from Original312, Original313 and Owner stepup.
5. Emits the exact Original305 v3 owner approval accepted by the existing
   Original312 v3 issuer, and stores each signed request just once in a
   Root-private replay-fenced ledger.
6. Never issues direct Host Write. A real v3 enrollment, independent
   Original313 high-water and exact per-operation grant remain necessary.

The source module does NOT load keys, register a generic signing endpoint,
make WordPress edits or bypass Owner consent. The new
tools/mad4b_original305_v3_preflight.py gives a Root-only read-only view
of current readiness, not an issuance operation.

## Remaining required first-party admission

- Independently reconcile the historic Original305 ambiguous-write incident.
- Enroll an authenticated Owner-stepup public key and a distinct Original305
  signer public key, both through the existing first-party Owner/Root trust
  admission mechanism. Unregistered private key files do not qualify.
- Register Original312 v3 independently from Original305, retaining the
  legacy Original312 v2 pins and verifier for its existing anchor.
- Obtain a fresh current Original313 signed predecessor from a complete,
  checked monotonic Root ledger; never declare NONE simply because one
  older directory does not include this site.
- After signed human consent, issue Original305 -> Original312 -> install
  at Original318 -> independently sign Original313 high-water.
- Finally enroll bounded persistent Write with exact signed job, protected
  previous backup, commit CAS and independent postwrite readback. Production
  retains its separately signed exact-site consent.

An unsigned persistent-write default is a policy preference, not a grant.
