# Host Authority v127 — signed historic Final Receipt when Anchor expires

**Base:** current master as at 20 November 2026, `68cab1a0ce0322eac89fffd443ea6b8b16e6b914`.
**Company:** All Royal Egypt / Staging / hosting-2.
**Safety:** Review-only owner UI source; no WordPress or Root mutation.

## Correct present-tense state

The original Broker-signed `Revision 1` record was issued at **04:05:34 UTC**
on 20 November 2026, expiring at **04:06:19 UTC** (45 seconds).
The independent checkpoint and Original313 Final Receipt were signed;
the checkpoint expires after its own 45-second period.

At the live WordPress readback, Original318 correctly reports
`state=EXPIRED`, `revision=1`, `readback_verified=true`,
`current_anchor_signature_verified=true`,
`signed_anchor_active_in_option=false`,
`prior_grants_ineligible=true`,
`write_authorized=false`.
The records are historic, **not current Host write authority**.

The live v123 owner console refused to render its previously verified
Final Receipt once `state` became `EXPIRED`, falling back to
`ANCHOR_STATE_REVIEW`. This is a UI presentation gap, not a signature
failure or a reason to issue a new Anchor.

## v127 nonauthorizing change

Three owner UI sources are staged under
`deploy/host-authority/review/v127/`:

- `signed-final-certificate.php` verifies the **same three original Ed25519
  signatures**, same single first-Anchor Job, revision, independent public key,
  original nonce and Original318 signed Anchor message hash. It accepts
  historical display only if Original318 itself cryptographically verifies
  `EXPIRED` and reports every authority and prior-grant flag as false or
  ineligible. `REVOKED` and unknown states never pass this helper.
- `393-mad4b-host-authority-owner-console.php` displays the signed historic
  Original313 receipt with an explicit **EXPIRED · Revision 1**, signed
  original expiration timestamp, and "No Host write authorized". It
  distinguishes the display phase
  `ANCHOR_EXPIRED_CERTIFIED_HISTORICAL` from
  `ANCHOR_CERTIFIED` (only for active original318).
- `host-authority.js` never enables POST actions for an existing revision,
  and shows the expired certificate status in owner-only polling without
  misclassifying historical issuance as a fresh Owner Request.

**No new Owner Request, Original312/313 signing, Root record, anchor extension
or new Host privilege is possible from these changes.** This is *not* a
token renewal. The dedicated v102 Original313 checkpoint renewal only
signs a fresh independent checkpoint for the same historical event; it does
not extend the WordPress original318 signed Anchor expiry or create a
new current Host authority.

## Verification

- A fresh synthetic Ed25519 Broker/independent pair (generated in RAM by
  an isolated PHP 8.5 Docker test) signs fake first-Anchor data.
  The pinned real public key is swapped to the synthetic test public key
  **in the ephemeral test copy only**. The production source remains pinned.
- `tests/host-v127/test_historical_expired_certificate.php` proves positive
  historical display, active preservation and strict negative denial for
  wrong signatures, nonce, Job, scope, revoked/unknown states and expired
  authority flags.
- `tests/host-v127/test_expired_certificate_source.py` checks UI phase,
  read-only REST metadata, owner gates, pinned public key and no new POST.
- PHP 8.5 `php -l`, Node `--check`, source-regression tests and
  Git tree byte equality are required.

This candidate **must not be installed as an active WordPress MU plugin**
without an independently reviewed staging deployment/rollback. Its source
paths are intentionally not autoloaded. An eventual new active Host Anchor
requires separate signed owner authorization and the actual original
Original306/312/313/318 workflow, not a read-only UI change. Production
is untouched.

The verifier additionally compares the original Broker-signed expiry with the live state: a historical expired ledger cannot be relabelled ACTIVE by a stale snapshot. Synthetic fixtures exercise both signed unexpired ACTIVE and historically expired Revision 1.
