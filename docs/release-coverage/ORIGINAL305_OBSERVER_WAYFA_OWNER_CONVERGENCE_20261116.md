# MAD4B — Original305 observer and current company source ownership

Observed on 2026-11-16, source-only, not an independent release approval.

## Original305 diagnostic observer

The enrolled mad4b-original305-observer.timer is already active on the
VPS. The latest journal verified a periodic non-authoritative snapshot.
The observer never calls the signing issuer, modifies the historical HOLD,
enables the signed readiness timer or grants site release authority.
Its exact deployed source SHA-256 is
a6a5530aa461d8713fcc8a7f1517bfb3ef87d68a256b67468cf61dc0a9c59508.

This reconciliation imports the exact root-owned observer source and its
isolated installer source as reviewed reference only, with their 10 safety
tests and systemd source files. It does not install a second observer,
restart the signed issuer or adjudicate the legacy incident.

## Source owners

The installed Tours.dev Universal Media source SHA-256 is
6c9886eba4c26cce45951363bf46e3a78da1db6a6c794a7447d096be72c9b4a5.
It remains the canonical file under wp-content/mu-plugins, unchanged.

The installed Wayfatours Universal Media source SHA-256 is
ad673d68ed3948cebde90ff4f0769a83ecd2469c8c8c0229340617d05ab67666.
Its company overlay carries this exact byte image instead of overwriting
Tours.dev. This variant preserves noncommercial deferred media queues
without inventing image rights or auto-publishing tour content. The
newest Wayfatours composite gallery, SMTP recovery and provider-backed
notice modules are also stored in the company overlay with exact
observed live SHA-256s, recorded in
ops/site-company-overlays/wayfatours/live-source-mirror-20261116.json.

The Wayfatours administrator notice changed from a previous version
which checked Stripe only, to a version that also checks Fluent SMTP
transport recovery with independent native plugin evidence. Recovery
of the transport is explicitly NOT proof of inbox delivery. The older
source remains traceable in Git history; no historical signed review
was rewritten.

## Tests and boundaries

- Original305 observer 6 tests and source/install guard 4 tests.
- Company source-owner and no-release assertions 3 tests.
- Wayfatours Stripe/SMTP notice 15 tests on PHP7.4 and 8.5.
- Native Fluent SMTP recovery 8 tests on PHP7.4 and 8.5.
- Wayfatours deferred media queue 11 tests on PHP8.5.
- Historical notice SHA and current version 3 tests.

Both sites' real PHP runtime was 8.5.12 when inspected. The media owner
has PHP8 union types, so no unsupported PHP7.4 compatibility claim is made
and no type-safety downgrade was performed.

No live WordPress or Nginx files were written by this source operation.
Original305 historical HOLD, independently signed Master review and
Host312/Original313 release remain separate governance requirements.
GitHub exact-head CI has NOT run until an authorized Git source transport
publishes the final local source hash.
