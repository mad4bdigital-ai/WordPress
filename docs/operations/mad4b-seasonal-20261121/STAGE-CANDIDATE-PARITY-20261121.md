# Checkpoint — exact Company Property candidate parity

Date: 2026-11-21
Source: Tours.dev
Target: Wayfatours

## Root cause

Five existing published Properties had matching post IDs, normalized titles,
destination terms, and Property types on both companies, but Wayfatours lacked
their original Silver/Gold/Diamond package classification. Their source
package stages additionally had zero candidate refs: the target binding
property `room_candidate_refs` was null, not merely misclassified.

Both root causes are now resolved by guarded, selective WordPress changes.

## Changes applied to Wayfatours, with exact preflight/readback

Native package_level terms synchronized on exactly five verified Properties:
- 36142 Iberotel Luxor: Silver
- 36481 Sonesta St. George Luxor: Gold, Diamond
- 36302 Helnan Marina Sharm: Silver
- 36084 Steigenberger Alcazar: Gold, Diamond
- 36181 Obelisk Nile Hotel: Silver

Native stage metadata `_mad4b_package_stay_verified_room_program_links_v1`
updated ONLY for three original Tour stages:
- 60627 `hotel-luxor-02`: Silver 36142, Gold 36481, Diamond 36481
- 60629 `resort-sharm-03`: Silver 36302, Gold 36084, Diamond 36084
- 60437 `hotel-aswan-02`: Silver 36181

All seven candidate references are **editorial Property candidates only**.
Each inserted reference has `unit_ids_en=[]`, `source_relation_id=0`,
`commercially_approved_unit_ids=[]`. No `selected_property_id`,
`bookable_unit_ids`, supplier receipt, Rate, sale, checkout or WooCommerce
setting was changed.

Source stage plans differ between companies and were NOT cloned or
overwritten. The target binding, its source-plan hash, original itinerary
geometry, WPML default-language term IDs, Property names/types/destinations
and live seasonal eligibility were verified before updating. Changes used
an exact old-binding digest check and saved preimages. Readback succeeded.

## Before/after native package-stage review

Wayfatours had nine unqualified Hotel stage/tier candidate gaps.
After the selective writes: **two** remain. Tours.dev also has **two**.
Both remaining entries are `hotel-aswan-02`, Gold and Diamond in tour
60437. Price readiness remains 0/24 tier reviews on BOTH companies. Every
one of the 28 source stay stages lacks a signed supplier cost receipt.

Available hotels under the Aswan governorate term do not automatically
pass the original guard's `aswan-city` location standard. Official
supplier pages show:
- The Zen Wellness Resort: Gazirat Bahrif, New Aswan, not proven Aswan City
- Artika Wadi Kiki: Gharb Soheil, West Bank
- Kato Dool: Nubian Village, Gharb Soheil region
- Nuba Dool: Elephantine Island

Reference pages:
https://thezenwellnessresort.com/contact/
https://artikahotels.com/contact
https://www.katodool.com/contact/
https://www.tripadvisor.com/Hotel_Review-g294204-d17546067-Reviews-Nuba_Dool_Guest_House-Aswan_Aswan_Governorate_Nile_River_Valley.html

Changing their taxonomy to `aswan-city` to game the guard would be
unsupported. Two separate property drafts on Tours.dev, Tolip Hotel Aswan
and Pyramisa Isis Island Aswan, have `aswan-city` taxonomy but lack
featured images and an approved company identity on Wayfatours. Their
source numeric IDs collide with unrelated Wayfatours revision posts:
never copy source IDs or publish incomplete drafts automatically.

## QA / regression

- 12 original suites: 221/221 on Tours.dev and 225/225 on Wayfatours
- New 3-stage, 7-link, full-locale source candidate parity regression:
  307/307 on Tours.dev across EN/FR/DE/ES/IT/AR;
  103/103 on Wayfatours across EN/ES
- Prior cost what-if and supplier-evidence-queue protections remain
  unchanged: the 47+56 supplier quotations are Draft/Stop Sale.
- Package pricing, Unit occupancy and real stock are separate from
  company taxonomy and editorial candidate links. No commercial activation.

## Evidence and rollback

Host checkpoint:
`/opt/mad4b-package-checkpoints/2026-11-21-quote-scenario/evidence/`

- `hotel-five-before-source.json`, `hotel-five-before-wayfa.json`
- `hotel-five-applied.json`, `hotel-five-after.json`
- `source-binding-audit-source.json`, `source-binding-audit-wayfa.json`
- `stage-parity-preflight.json`, `stage-parity-apply.json`,
  `stage-parity-readback.json`
- `candidate-after-source.json`, `candidate-after-wayfa.json`
- `STAGE-CANDIDATE-PARITY-CHECKPOINT.json`

An exact preimage can be restored only if the current metadata still matches
the applied digest; never revert later user edits. The original guarded
one-time scripts remain in the checkpoint `stage/` directory. No price
records or secret supplier notes are included in the source repo.

