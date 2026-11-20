# Pyramids Park Resort Cairo — owner approves Google Hotels five stars

Date: 20 November 2026. Wayfatours Production.

The owner instructed: **اعتمد تصنيف جوجل**. This expressly selects the Google Hotels operational five-star rating for Pyramids Park Resort Cairo.

Source evidence:
- Google Hotels https://www.google.com/travel/hotels/entity/CgoIwejS8Zm4vfcBEAE displays 5-star hotel.
- Previous review found a differing four-star designation on Booking.com https://www.booking.com/hotel/eg/pyramids-park-resort-cairo.html; the hotel's own website https://pyramidsparkresort.com/about-us/ did not clearly identify star accreditation.

The source disagreement remains in the new receipts as a nested copy of the original record. This is **owner selection of a secondary reference**, not official hotel accreditation, supplier contract validation, or price verification.

## Executed / verified

- EN Property 70732: stars term 490 — 5 Star.
- ES Property 71709: WPML translated stars term 649 — 5 estrellas.
- Both source receipts: status owner_approved_google_hotels_five_star, stars 5, owner approval true, selected Google Hotels URL, original four-star disagreement preserved.
- Silver / Plata package levels (terms 2353 / 2991) are unchanged. Star rating is independent of package-level Silver.
- All 18 English properties now classified, error_count=0.
- The native hotel component resolver, without any old manual rates or pricing, passes 48/48 Tour × language × tier cases and retains 5 Silver, 6 Gold, 7 Diamond hotel choices. 48/48 still require accommodation stage plans.
- Published Tour Rates 0; Published Property Rates 0; public hotel catalog not activated. No room pricing, booking, inventory, supplier contract or checkout modifications.

Pre-change snapshot at private authorized VPS:
/opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/pyramids-park-owner-google-stars-before.json

Reproducible scoped one-time CLI and verify: scripts/mad4b-stay-pyramids-park-owner-google-five-star-cli.php. Future rollback must be a separately authorized exact restoration of only the two added stars term relationships and the previous source review meta, leaving all other terms and pricing unchanged.

Release PR #952 stays Draft. Upstream governance and code-version reconciliation still apply.
