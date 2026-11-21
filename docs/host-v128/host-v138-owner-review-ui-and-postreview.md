# Host v138 — Stop duplicate reviews and blank WordPress buttons

## Evidence from actual Staging administration

The signed-in administrator's November 21 screen showed a blue button
with no text next to "Copy request ID", despite the current review message.
The v137 code creates a primary WordPress button without a label, then
sets its hidden attribute when an active review exists. WordPress author
CSS for .button can override the browser's default UA hidden display.
The blank button is a real CSS and DOM state defect.

Read-only WordPress DB inspection confirmed two existing remote review
records for Tours.dev Staging, in the state
AWAITING_INDEPENDENT_ORIGINAL313_PREDECESSOR. Both review windows had
expired, but their audit records are preserved; neither contains an
Original305 signed approval, Original312 signed enrollment, Owner
signature or standing Host Write.

## Root-cause fix

The v138 change only affects the existing protected 430 presentation MU
file; it does not add a review path, server signer, REST permission or role.

- Enforce a high-priority author CSS [hidden] display:none !important
  rule for every element inside the human-owner UI.
- Synchronize hidden attribute, inline display, disabled state,
  aria-hidden and text for both primary and secondary buttons.
- For a recorded active review, show only "Copy request ID"; never a
  ghost primary button or a redundant button margin.
- For a recorded expired review, keep its recorded milestone checked.
  While no independently signed Original313 offer exists, show
  "Your review is recorded — no further action now"; do not create
  another short-lived request.
- If no review exists, preserve the singular original request action.
  Once an independently signed offer is available, an expired review
  can be renewed via the existing v135 REST path without bypass.
- Localize new guidance across the six WordPress user-profile locales
  (English, Arabic, French, German, Spanish, Italian). Do not translate
  machine protocol fields or identifiers.

## Tests

The focused v138 suite verifies the CSS author rule and DOM states,
disabled/aria, recorded-review milestones, no duplicate POST for active
and expired cases, actual PHP-rendered script in English and Arabic,
no-review action, six translations, and no signer/server mutation.
Existing v136/v137 tests are updated for expired, pending review states.

Full Host, Release, Content Hub, Staging fencing and Host Runner
regressions must remain green before publication.

## Post-review authority boundary

A review request establishes initiation, not a cryptographic Owner
step-up. Before any next Owner signature or standing Write can be
issued, the already governed first-party Original305 signer must verify
a separately enrolled independent Owner identity and Original313 must
prove complete cross-generation predecessor history. Then Original305/312
signed issuance and Original318 readback are separately required.
A WordPress session, Root system key or guessed NONE revision is not
a valid substitute.

## Rollout/rollback

Publish only the v138 exact Git source into Tours.dev Staging, with a
protected private v137 preimage, PHP lint, the original 420/425 hashes,
WordPress boot, REST guest 403, homepage HTTP 200 and signed read-only
Host Runner Current. Rollback restores only the protected v137 430 file
and immutable attribute. Production and Host Write unchanged.
