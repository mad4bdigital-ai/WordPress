# Host v134 — Original305/312/313 owner review in REST and WordPress Admin

## Same contract for both interfaces

**Site**: Tours.dev Staging, pinned Site Profile UUID, origin, protected
Root public verifier SHA, and Original318 v3 loader/reader SHA.

**Admin**: Tools > Host Authority v3, or the existing MAD4B Control Plane
submenu when registered. Read-only access is allowed for users with WordPress
manage_options or MAD4B company-operations capability. Only an actual
administrator with both manage_options and activate_plugins AND a valid
browser auth cookie, session token and WordPress REST nonce may POST the
review request. That action does not approve deployment or mint a signature.

**REST** (same WordPress runtime and same status/plan validation):
- GET /wp-json/mad4b-host-v3/v1/status — current Original318 v3 state,
  exact Staging identity, public verifier readiness and true operational
  authorization; never trusts a submitted site UUID.
- GET /wp-json/mad4b-host-v3/v1/history — a timestamped, explicitly
  historical and non-authorizing Root audit. 11 protected archive
  directories plus one commissioning document, 13 observed JSON documents,
  one independently signed v2 chain (All Royal Egypt). No new signed
  Original313 predecessor is represented.
- GET /wp-json/mad4b-host-v3/v1/review-plan — exact candidate digest,
  source HEAD, expected Original318/Root verifier SHA, immutable site scope,
  existing review state and precise missing authority gates.
- POST /wp-json/mad4b-host-v3/v1/review-requests — browser-owner request
  only. JSON has exactly request_id (32 lowercase hex), expected_plan_sha256
  (64 lowercase hex), confirmation "REQUEST EXACT V3 OWNER STEP-UP".
  The review record is a **pending request**, never a signed Owner approval.

The POST uses a WordPress options-table unique-key insert through add_option
to prevent concurrent duplicate requests. If the same request_id, plan and
actor are retried after an ambiguous response, the exact stored request is
returned. A different request for the same plan yields HTTP 409 and requires
review instead of overwriting evidence. No automatic signer retry, no WP role
migration, no option containing a signed enrollment, no private key use,
no arbitrary shell, no web-server Root execution, no Production capability.

## Error contract

- 403 missing WordPress capabilities or real browser Owner session
- 409 exact site, existing request or candidate digest changed
- 422 malformed/more-than-exact input fields
- 503 review write readback was not verified (never assume success)
- GET returns private no-store and never public site authority

## Human-reviewed authority is separate

Owner review request + WordPress admin rights are NOT a cryptographically
signed Owner step-up. To turn the request into actual Original305 approval,
the registered separate Owner-stepup service must sign the exact intent
against independent Original313 predecessor and Root's registered
Original305 v3 issuer. The Root original312 issuer must then separately
issue the signed enrollment and Original313 must verify its installed
Original318 readback and monotonic high-water. Only after that may a
signed standing Write capability and bounded operation-specific grant
be enrolled.

## History audit

The Root-only source auditor verifies the old v2 revision signature with
the original Broker public key, the checkpoint and final receipt signatures
with independent Original313, and the event/receipt digest links.
It sees no Tours.dev v2 record in the checked archive but cannot prove
the latest revision is NONE because this is not a complete fleet
cross-generation inventory. It never signs a predecessor from absence.
Full archived audit remains in a Root-owned private report; REST exposes
only its non-sensitive summary and timestamp.

## Acceptance / rollback

The MU source file is intentionally isolated in deploy/.../v134; it must
be installed only to verified Tours.dev Staging with an exact preimage
record, PHP lint, real WordPress REST permission checks, Admin menu
registration, review tests and HTTP 200 before marking it active. The
Root private audit code and the Owner issuance source stay separate.
If validation fails, revert only the additional MU console and confirm
no new approval/write options have appeared. Never replace Original318 v2
or clear Original305's preserved Nov 18 ambiguous-write HOLD.

This staging implementation is NOT a fleet-wide production authority
or a signed v3 enrollment. Other companies require their own enrolled
site identities and owner approval.
