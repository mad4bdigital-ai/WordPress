# Original 18 hotel cohort — Wayfatours (20 Nov 2026)

## Verified live publication and package tiers

This audit is read-only. It confirms WordPress Published status on all
18 original Property records and their assignment to the JetEngine taxonomy
package_levels. No new posts, translations, Units, archive, listing,
rates or booking workflow were activated.

| Tier | Hotel | Property ID | Published Units |
|---|---|---:|---:|
| Silver | Pyramids Park Resort Cairo | 70732 | 13 |
| Silver | Barceló Cairo Pyramids | 36475 | 2 |
| Silver | Saraya Pyramids Hotel | 36316 | 0 |
| Silver | Tolip Gardens Hotel | 36197 | 4 |
| Silver | Triumph Plaza Hotel | 36209 | 8 |
| Gold | Hilton Grand Nile Tower | 36479 | 2 |
| Gold | Mövenpick Media City | 36473 | 19 |
| Gold | Hilton Pyramids Golf | 36474 | 3 |
| Gold | Ramses Hilton (currently titled Hiton Ramsis) | 36477 | 2 |
| Gold | Triumph Luxury Hotel | 36140 | 10 |
| Gold | Sonesta Hotel, Tower & Casino Cairo | 70733 | 9 |
| Diamond | Sofitel Cairo Downtown Nile | 36355 | 13 |
| Diamond | Four Seasons First Residence | 70734 | 12 |
| Diamond | Fairmont Nile City | 70735 | 5 |
| Diamond | Steigenberger Pyramids Cairo | 70736 | 10 |
| Diamond | Hilton Cairo Heliopolis (original Hilton Airport label) | 70737 | 1 |
| Diamond | Sofitel Cairo Nile El Gezirah | 70738 | 14 |
| Diamond | Sheraton Cairo Hotel & Casino | 70739 | 4 |

Tier totals: 5 Silver / 6 Gold / 7 Diamond. The 131 published
Unit records are linked to 17 hotels by the original JetEngine relation 223.

## Deliberately not claimed complete
- Saraya Pyramids Hotel #36316 is published and tagged Silver, but has
  zero Units linked. Do not invent room types, occupancy or room prices.
  Verify hotel identity and official/supplier details before building Units.
- WPML languages EN and ES: all 18 source Properties are published EN.
  None has an attached ES translation post: Spanish coverage is 0/18.
  Publishing EN does not auto-create translated content.
- Existing hotel name typo Hiton Ramsis was not changed by this audit.
  Original Hilton Airport label corresponds in this cohort to Property
  #70737 now titled Hilton Cairo Heliopolis, which Hilton lists beside
  Cairo International Airport. This does not assert a new separate hotel.
- Invalid import Property #36260 (#VALUE!) and Units #37553/#37554/
  #37555 remain Draft/quarantined outside the 18-hotel cohort.
- The preceding status write batch covered 8 previously Draft Hotels
  and 131 Units. The other 10 target Hotels were already Published.
- Property Rates remain 0 Published. Accommodation unmanaged stock,
  WooCommerce stock configuration, checkout and payment stay unchanged.
- /properties/ and /units/ archive URLs continued to return HTTP 404
  after publication. An underlying Published post is not evidence of
  an enabled customer-facing Property catalog. It remains disabled
  per the user direction.

## Repeatable verification
Use authorized Wayfatours WP-CLI eval-file with
scripts/mad4b-stay-original-18-hotel-publication-audit.php. This read-only
checker asserts exact IDs, Gold/Silver/Diamond terms, Unit publication,
EN/ES translations, bad-import quarantine, rates and catalog plan.
Missing ES and missing Saraya Units are reported as distinct work
remaining, not falsified as published.
