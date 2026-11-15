# Original MAD4B Tour Highlights — non-visible content guard

Applied on Wayfatours Production and Tours.dev source on 2026-11-15.

## Root cause

The original field highlight_rows (and its presentation mirror
tour_highlights_content) in Tour 60439 ended with a formatting-only
paragraph containing a non-breaking space. The previous parser allowed
this markup to become a blank eighteenth Highlight and generated the
hl-e3b0c44298fc1c149afb hash of an empty string.

## Permanent engine change

The existing native presenter
class-mad4b-scp-tour-highlights-presentation-v1.php now discards
formatting-only fragments at the input, paragraph/line and segmentation
boundaries, and returns empty HTML if the whole source has no visible
text. It decodes HTML entities and ignores Unicode separator and
control/format characters for visibility checks.

The parser preserves authored markup, meaningful editorial copy,
WPML-specific translations and the existing stable key generation.
Explorer, Split and Story use the same canonical cards. No second
engine, JavaScript-only hide patch or Elementor template change.

## Minimal original-content cleanup

Only published English Tour 60439 changed on each tenant, and only
these source meta fields:

- highlight_rows
- tour_highlights_content

Each stored string lost one verified terminal blank paragraph followed
by newline. Old SHA-256:
baf954759ac7d41fa5d5377f79533c91763a9311ccba8b045eb374a3147b930b

New SHA-256:
ee2eeaf63f2481c8d9bef49d81ae5888b9862e92627bcc964441cd873976ff05

Both fields were updated with the original preimage CAS and independent
readback. All 17 visible Highlights and stable keys are preserved. Per-site
original PHP and original source meta preimages are stored in permission-0600
VPS backups under:
 /opt/mad4b-company-transport/backups/highlights-blank-cards-20261115

No unrelated Tour/Itinerary, Term, or WPML source was modified.

## Acceptance

Read-only regression:
wp eval-file scripts/qa/tour-highlights-visible-text-contract.php

The contract checks 11 whitespace/HTML/Unicode fixtures, the exact
17-card key-preservation invariant and all published/draft Tour source
fields. Live desktop/mobile browser readback showed zero blank cards,
17 selectable cards, Split mode and no overflow. Wayfatours cached HTML
served the corrected markup on both FastCGI MISS and HIT.

A source Git merge does not authorize a signed Original313 release;
native release governance remains separately required.
