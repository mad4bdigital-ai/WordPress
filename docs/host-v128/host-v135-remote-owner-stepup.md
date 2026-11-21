# Host v135 — Remote Original305 Owner Step-up handoff

## Remote browser and REST support (Staging only)

v134 remains the single Host Authority v3 Admin screen. v135 adds a
"Remote Owner Step-up" section to it; no second settings page or parallel
authority registry is created.

The authenticated owner may open:
https://tours.dev.mad4b.com/wp-admin/admin.php?page=mad4b-host-v3-owner-review

Under the same mad4b-host-v3/v1 REST namespace:

| Method | Route | What it does |
| --- | --- | --- |
| GET | /remote-status | Authenticated read of remote handoff readiness |
| POST | /remote-requests | Creates unsigned request with fresh 128-bit server ID, 30-minute expiry and 60-second user rate limit; real WordPress admin browser cookie, session, REST nonce and manage_options plus activate_plugins required |
| GET | /remote-request?request_id=ID | Exact request and independently signed Root offer readiness, if any |
| POST | /remote-signed-proof | Accepts signed evidence only after an Original313-signed exact Root offer and registered Owner step-up key; quarantines proof, never an approval |

No unauthenticated route, one-click approval, private keys, generic remote
Shell executor or WordPress-to-Root signing endpoint.

New remote requests are append-only and renewable with a distinct ID.
Older v134 requests remain intact. Browser review is never a signature.

## Independent Original313 Root offer

WordPress uses an untrusted option transport:
mad4b_original313_v3_signed_owner_offer_v1

The option becomes meaningful only if carrying an exact Ed25519 document
with contract mad4b.original313.owner-remote-handoff.v1, signed with domain
MAD4B_ORIGINAL313_OWNER_REMOTE_HANDOFF_V1 plus newline.

Required scalar fields: company, environment, exact Staging Site UUID and
Host ID, request ID, plan SHA-256, Original313 predecessor SHA-256, v3
enrollment SHA-256, source commit SHA, Owner step-up public Ed25519 key,
independently enrolled Owner step-up identity receipt SHA-256, Original305
issuer Ed25519 key and enrollment receipt SHA-256, true first-party identity
and monotonic history claims, issuance/expiry (up to 600 seconds),
state READY_FOR_OWNER_STEPUP, and an independent Original313 signature.

The WordPress option is not a trusted pin. v135 checks the immutable Root
Original313 verifier, Original305/312/313/Owner key independence, Root
signature, target identity, request, hash and freshness.

No signed Original313 offer exists yet. The complete predecessor ledger
and independent Owner step-up identity have not been established.

## Independent Owner proof transport

An externally enrolled Owner Step-up identity signs the exact Owner consent
contract mad4b.original305.owner-v3-exact-stepup-consent.v1, signed with
domain MAD4B_ORIGINAL305_OWNER_V3_EXACT_STEPUP plus newline. The evidence
must match the Root offer's request, predecessor, enrollment and source
commit and includes the signed Owner actor digest, nonce, APPROVE decision,
and a validity window of at most 180 seconds.

POST valid evidence gives SIGNED_PROOF_QUARANTINED_NOT_AN_APPROVAL.
The only stored evidence is an append-only quarantine record in WordPress,
never an Original305 approval or Host Write grant. The independent Root
issuer must re-verify the exact offer and proof with source module
ops/host-authority-v128/original305_remote_intake_v135.py and ORIGINAL
Root-pinned keys, then independently re-read the full Original313 ledger
and approved Owner-stepup identity before any Original305/312 signing.

A fake, stale, cross-site, cross-request, altered, reused or expired proof
is rejected. Tests use ephemeral keys solely within isolated test fixtures.

## Live authority boundary

Until a separately enrolled Owner Step-up signer and a fresh verified
Original313 predecessor exist, only unsigned remote review is supported.
REST proof intake responds with HTTP 409. Original318 v3 remains
MISSING_OR_INVALID, with write_authorized=false. Production is untouched.

## Deployment and rollback

The new MU file is additive (425) to v134 (420). Deploy only after exact
Git source hash, absence/preimage, existing protected trust/318 source
integrity, PHP syntax, REST route and guest permission checks, HTTP 200 and
signed read-only Host Current. Remove only the exact 425 file on rollback.
Do not clear the legacy Nov 18 Original305 ambiguous-write HOLD.
