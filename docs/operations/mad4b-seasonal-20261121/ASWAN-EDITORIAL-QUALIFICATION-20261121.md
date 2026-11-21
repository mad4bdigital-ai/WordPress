# Aswan hotel editorial qualification and supplier blockers — 21 November 2026

## Live facts and changes

The original eight package tours on Tours.dev and Wayfatours each have
native Stage Guard pricing safety. Aswan stage `hotel-aswan-02` still
lacks eligible **published** Gold and Diamond Properties, and original
Cost Receipts/Unit availability remain unapproved. No source Tour Rate
or booking capability was enabled in this rollout.

Two source-backed Property Drafts were previously staged on Tours.dev:
- 71495 — Tolip Hotel Aswan: Nile Corniche, 5-star hotel, official site.
  https://tolipgroup.com/tolip-hotel-aswan/
  https://tolipgroup.com/tolip-hotel-aswan/contact-us/
- 71501 — Pyramisa Isis Island Aswan: island resort near central Aswan,
  boat transfer requirements require verified supplier policy.
  https://www.pyramisahotels.com/isis-island-aswan-home

The original Drafts carry unapproved Gold/Diamond **editorial**
classifications and original official-source provenance. They have zero
clear featured images and no validated commercial room inventory or
signed supplier rates. Existing meta explicitly says that publication,
commercial level approval and booking authorization are false.

The same numeric IDs on Wayfatours were occupied by unrelated revision
posts. Rather than overwriting or assuming identical IDs, two new
English WPML Draft Properties were safely created:
- Wayfatours 71980: canonical source 71495 (Tolip)
- Wayfatours 71981: canonical source 71501 (Pyramisa)

The new Draft records preserve original English source text, original
destinations/types, editorial tier terms and official-source evidence,
with an explicit `_mad4b_company_source_aswan_property_id_v1` relation.
No rights-controlled images, Units, prices, stock, Tour Rates, Woo orders,
live catalog availability or customer quotes were generated.

## Read-only admin quality report

The existing native Package Readiness response (Dynamic Pricing Controls
-> Package & Rates) now includes
`aswan_editorial_draft_review` for Gold/Diamond in tour 60437,
stage hotel-aswan-02. This is NOT part of the Guard's eligible list.

It lists both editorial Drafts and their company-local IDs, official
evidence URL, native edit link and actionable blockers:
- Draft / not published
- Tier commercial approval not recorded
- No cleared featured image/license
- No signed supplier Cost Receipt
- No dated Unit allotment, capacity or occupancy confirmation
- For Pyramisa: resort kind versus hotel stage and unverified island
  boat transport logistics

Source taxonomy is evaluated under the original WPML language and then
the site's current admin language is restored. No stale static ID
mapping is used on Wayfatours; the original-source meta locates the
target post.

Official hotel facts: Tolip five-star mainland Corniche. Pyramisa's
official site describes an island and a short boat trip to downtown.
The Mandarin Oriental Old Cataract is under renovation; official press
notes the heritage building may serve guests during a Nile Wing
renovation until the full 2027 opening. No availability claim or
substitution with Old Cataract was generated for December 2026.

References:
https://www.mandarinoriental.com/en/aswan/old-cataract
https://press.mandarinoriental.com/mo-luxor-aswan-nile-cruise/?lang=eng

## Supplier contracting

Current connected Drive/Gmail search identified no verified company
contract for either hotel's relevant 2026/27 periods. Original quotation
sheet values remain per-person references without supplier-signed
per-night basis. Two **unsent** supplier-information email drafts were
created for official contacts:
- info@tolipaswan.com — signed B2B net rates, basis, occupancy, board,
  season, allotment, release, Stop Sale, gala and image rights
- info@pyramisahotels.com — the same plus island ferry timing, transfer
  and late arrival/early departure provisions

Only the account owner may review and send these. No supplier
acknowledgment or real inventory has been obtained.

## Release and rollback

Live runtime edits on Tours.dev and Wayfatours are only:
- pricing-registry/includes/package-component-readiness.php
- pricing-registry/assets/transport-distance-time.js
- the two new Wayfatours **Draft** Property records, not existing records.

Full canonical source copies, original SHA-256 preimages, replay-verified
patches and post readbacks are stored in:
`/opt/mad4b-package-checkpoints/2026-11-21-aswan-commercial/`

Do not restore an old code file over later changes; check exact
preimages. Do not delete the two new Drafts if a human has since edited
or published either; first reconcile current fields and evidence.

No owner exact-HEAD signature or commercial approval was fabricated.
GitHub PR #1003 remains subject to actual CI and release governance.

