# Host v122 — Fix real Original313 first Owner WP-CLI freshness false-negative

**Scope:** All Royal Egypt / Staging / hosting-2, first Anchor only.

## Live reproduction, 20 Nov 2026

Owner approved a new Original305 Request, ID
`d521f2665e8de96ac26311032b751946`.
Original306 Root stored actual original QUEUED preimage and separately signed
Broker witness. Original306 successfully signed Claim. Original313 v105
stopped before Enrollment with:
`ORIGINAL313_FIRST_OWNER_FRESH_CENTRAL_WP_NOT_PRISTINE`.

A subsequent read from the authentic WordPress source matched all five
identities exactly: company, environment, site_uuid, canonical_origin and
profile_digest. Original328 was active and Original318 remained pristine at
Revision 0, without checkpoint/enrollment/receipt.

The old v105 source measured `instant` BEFORE its synchronous WP-CLI
read (which can take 10–38 seconds), then required the *new* WordPress
observed_epoch to be <= instant + 5 seconds. Any genuine new WP reading more
than five seconds after initial call was wrongly rejected. The request and
Claim have since expired; neither may be replayed or time-extended.

## Corrected original source v122

Preserves original v105 immutable signed enrollment, dual-source verification,
Root key authorization and storage transactions unchanged. A narrow
`verified_fresh_readback_epoch` test runs **after** the WordPress read,
requires this call to finish within 45 seconds, bounds observed_epoch relative
to its actual completed read, and independently rechecks **current** Owner
Request expiry (>=25s remaining) and Claim freshness (<=90s). Any stale
request, stale Claim, untrusted time type or old readback still fails closed.

Original v105 remains untouched. The purpose-scoped v121 Root coordinator
pins the v122 source by its exact SHA; the v119 preclaim and v86 original
claimed verifier still use their existing pinned originals. A new Runner SHA
requires a regenerated Worker source SHA, updated read-only UI Root readiness
pin, and new exact signed Root commissioning marker. Neither signing key,
Original318 policy nor generic Registry is modified.

## Validation

- 9 new v122 temporal and source-integrity tests, including 20-second valid
  WP-CLI, stale/future observed_epoch, expired Owner Request and stale Claim.
- 139 original and new Python regression tests PASS, plus existing PHP/JS
  Owner scope and signed readiness tests.
- Live original documents / signed first-Anchor receipts after expired
  Request: zero. Revision remains 0; no authorized retry.

## Commissioning

Deploy v122 file 0600 Root and update the exact v121 Runner/Worker/UI
snapshots atomically with rollback, rebind Root commissioning marker to exact
new Runner SHA, republish Root-signed Staging owner readiness and read back
the actual UI owner state. Never resurrect the expired Request. Then await
one NEW explicit authenticated Owner approval and confirm live signed
Original313 Enrollment, original312 docs, single original318 write and
Revision 1 / signed Final Receipt before closure.
