# Owner signer v143 — genuine device-held Ed25519, no VPS private key

## Why

WordPress review and the account Owner's chat statement are NOT signed
Owner Step-up. Original305 v3 requires a distinct registered Owner public
key and a fresh Ed25519 exact-intent signature. Original313 must independently
certify the exact historical predecessor, including expired old revisions,
before offering the bound short-lived signing request.

Original313's 13-document v2 archive proves only a chain for All Royal Egypt.
It does NOT prove the Tours.dev predecessor or issue a certificate.
The 2026-11-18 Original305 ambiguous-write HOLD is unchanged.

## Added client, but NOT authority

Source: ops/host-authority-v143/offline_owner_stepup.py.

This **offline device-side tool** creates a real, encrypted Ed25519
private key under the Owner's control on their Windows/Linux/Mac machine.
The public proof is an UNENROLLED request, not approval. It must be
verified by the independent original first-party Owner identity registrar;
a WordPress account or user confirmation here cannot substitute.

When and only when Original313 publishes a genuine signed v135 handoff
offer for the enrolled Owner public key, the helper verifies:
- pinned independent Original313 Ed25519 signature;
- correct company/environment/site/host;
- fresh offer, identity authority confirmation, full predecessor status;
- exact public key match, source commit and request digest;
- interactive Owner typed approval after displaying scope.

It signs exactly the canonical v3 Original305 Owner consent contract
with an intent valid for at most 180 seconds (and never beyond the offer).
The resulting JSON still requires Root v135 verification, Original305
first-party issuer, Original312 independently signed enrollment, and
Original318/313 readback. **Neither the public proof nor signed intent
itself grants WordPress/Production Host Write**.

The tool never posts on a network, uploads a private key, uses a Root
signer or creates a WordPress option. It cannot fabricate an Original313
certificate or bypass the legacy signed-write HOLD.

## Run on the OWNER'S COMPUTER only

Requirements: Python 3.11+ and cryptography>=41,<48, from verified
installation source. Keep downloaded code checksum pinned to an
approved Git commit before execution.

Install the dependency in a local environment:

    py -m pip install -r requirements.txt

Generate the real local Owner signing key and a public-only proof:

    py offline_owner_stepup.py init --device-dir "C:\MAD4B\owner-signing"

Enter a strong passphrase twice; NEVER type it into ChatGPT or WordPress.
File mad4b-owner-device-ed25519-private.pem remains encrypted locally.
Only mad4b-owner-device-public-proof.json is safe to transmit to the
independent Owner identity registrar for verification. The proof is not
an automatically accepted certificate.

Once the independent identity registrar has enrolled the public key
and **Original313 has issued a genuine signed offer**, the exact owner
approval can be signed on the same Owner device:

    py offline_owner_stepup.py sign-offer --device-dir "C:\MAD4B\owner-signing" --offer-json "C:\MAD4B\original313-offer.json" --actor-digest FILL_WITH_VERIFIED_IDENTITY_DIGEST --expected-source-commit FILL_WITH_APPROVED_40_CHAR_GIT_HEAD --output-json "C:\MAD4B\signed-owner-intent.json"

The local tool verifies the Original313 signature, Owner public key,
site scope and expiry before offering interactive approval. The
resulting signed-owner-intent.json may then be posted through the
existing authorized v135 Root-verification courier (not a user-pasted
private key). The Root authority must independently recheck all proofs.

If Original313 is still missing, no enrollment approval or signed offer
can be produced by this tool; this is intentional.

## Rollout boundary

This is a source-only local computer helper: DO NOT install or run it on
Tours.dev VPS; do not ship it inside public WordPress assets or make
the web server generate the Owner private key. The one-owner signing
device stays separate from WordPress, Original305/312 and Original313.

All production code and standing Write privileges remain unchanged.
