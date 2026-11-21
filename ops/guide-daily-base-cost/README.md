# Guide daily Base Cost | Company self

Owner-defined internal guide/day policy for Tours.dev and Wayfatours:
- EGP 2,000 / Arabic guide / guided day.
- EGP 2,500 / English guide / guided day.
- EGP 3,000 / any **other verified** Guide Language term / guided day.
- Supplier: company self (internal cost basis). Unit: per_guide_day.
- Total base cost = unit cost × **explicit guided days** × **guide quantity**. **Not** per traveler.
- The optional Guide Language field in Fluent Forms #6 identifies the same taxonomy term, but never changes a checkout price.

## Schema
Option: mad4b_guide_daily_base_cost_v1
Resolver: MAD4B_Guide_Daily_Base_Cost_V1::preview(termSlugOrId,guidedDays,guideCount).

The resolver validates taxonomy identity from _mad4b_guide_locale_code, not translated names or browser ISO. It rejects missing identities, QA terms, invalid quantity and malformed policy. It returns currency=EGP, amount_type=internal_cost_not_selling_price, apply_to_commercial_total=false, checkout_ready=false.

This is an internal base cost only. No automatic Tour Rate overwrite, inventory allocation, guide confirmation, FX conversion, margin, tax, checkout or payment activation. Include it in a Package Cost Breakdown only once after classifying whether a Tour Rate already contains the guide service. Missing guided-day coverage must not be treated as zero.

The Guide Language taxonomy identifies the service language; no per-translation duplicate prices. Do not apply the legacy guide-language surcharge on top of this component for the same service.

## Execute and verify
From an authorized PHP/WordPress container:
- wp eval-file /tmp/mad4b-guide-base-cost-apply.php --allow-root
- MAD4B_GUIDE_COST_APPLY=1 wp eval-file /tmp/mad4b-guide-base-cost-apply.php --allow-root
- wp eval-file /tmp/mad4b-guide-base-cost-verify.php --allow-root
- wp eval-file /tmp/mad4b-guide-wpml-price-verify.php --allow-root

Installer does nothing by default, is idempotent, refuses to overwrite a conflicting policy and saves a pre-change snapshot before adding the option. Copy the snapshot off-container.

Applied 19 Nov 2026 to Tours.dev Staging and Wayfatours Production. Both settings SHA256: 64b2dc8faefff1541d09f8f33d19ef9e6440e7038ed8d73c92f4476ecde838b7.

Acceptance: each site 7 positive and 4 negative policy cases, plus 6 form-to-cost preview scenarios. Wayfatours only has EN/ES active; requesting other WPML locales may return the active language.

## Future integration
Connect the resolver only to a guarded, read-only Package Pricing component breakdown. Guided days must come from validated itinerary/service stages, not trip duration. Add quote provenance and verify inclusion in an existing Tour Rate before commercial promotion.
