# Host v136 — Owner-facing Arabic-first Authority Admin UX

## Problem observed on Tours.dev Staging

The prior WordPress screen displayed two Owner review forms. "Copy handoff"
copied technical JSON, which the owner pasted into the separate signed
Owner-intent JSON textarea. This JSON was a status document, not a
cryptographic signature; the Verify button could not become active because
no independently signed Original313 offer exists yet.

## Human UI contract

- One visible Owner review action, not duplicate forms.
- Arabic-first single screen for "اعتماد صلاحية الكتابة الدائمة".
- Show actual Host Write state as NOT AUTHORIZED, without a success badge.
- Recover the logged-in owner's latest exact registered remote review from
  a bounded read-only query of up to 100 WordPress option names, checking
  real owner UID, site UUID, company, environment, ID and expiry.
  Never show another user's request.
- If current review exists, show "تم تسجيل طلب المراجعة", expiry and copy
  request ID ONLY. Do not ask for another review while it is active.
- If review expired/absent, a single button uses the existing v135
  remote-requests REST route, without new signing or approval paths.
- Four states: site identity verified, review recorded, complete Original313
  predecessor pending, independent Owner signature and Host Write pending.
- Move previous v134 and v135 technical forms and audit panels intact to
  "تفاصيل فنية للمسؤول التقني (اختياري)" disclosure, not visible by default.
  Keep existing input handlers and technical API unchanged.
- Native WordPress styling, neutral colors, RTL only in simplified content,
  clear errors, accessible buttons, aria-live status, small/mobile layout.
- No new signing, Root access, REST routes, grant, user permission or WordPress
  write backend. User-facing workflow is a presentation adaptation only.
- Fail-safe: on any JS load failure the old interface remains available.

## Actual authority boundaries

Original318 and Original313 readbacks plus v135 REST are the source of
truth. A WordPress review request is not an Owner step-up signature.
The separate Owner v3 signer is not enrolled; complete Original313
cross-generation predecessor is not established; no Original305 approval,
Original312 v3 enrollment or standing Host Write is authorized. The design
does not hide these facts.

## Test and rollout

New MU presentation adapter:
deploy/host-authority/reconciliation/v136/430-mad4b-host-authority-human-ui.php

Unit tests execute the actual PHP source with isolated WordPress stubs to
verify current owner review, expiration, cross-account filtering and denial
to unauthorized users. Node parses the rendered JavaScript and checks
synthetic DOM transformations in active, expired and absent request states.

Publish only on exactly verified Tours.dev Staging after source SHA match,
preimage ABSENT, root-owned immutable installation, PHP lint, WordPress
admin_footer callback, HTTP 200 and signed read-only Current, and confirm
Original318, v134 and v135 remain unchanged. Rollback removes only the exact
new 430 MU file. No Production mutation. Logged-in real-browser manual
verification remains a separate acceptance step.
