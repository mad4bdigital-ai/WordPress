# CP4 stage assignments — native source census, 21 November 2026

This is a read-only source audit, not a pricing approval. Eight canonical Package tours and 28 distinct stage bindings were reviewed on each tenant. No new Property, Unit, Tour Rate, transport fare or supplier Cost Receipt has been published.

| Source property | Tours.dev | Wayfatours |
| --- | ---: | ---: |
| Hotel/resort stages | 19 | 19 |
| Stage × tier eligibility checks (Silver/Gold/Diamond) | 57 | 57 |
| Cases without an eligible native Property | 9 | 9 |
| Cases with exactly one eligible Property | 16 | 0 |
| Cases with multiple eligible Properties | 32 | 48 |
| Cases with candidate Properties having published Units | 0 | 48 |
| Cruise stages with a matched native program term | 0/7 | 7/7 |
| Sleeper stages with one original-route candidate | 2/2 | 2/2 |
| Commercially complete stage assignments | 0/28 | 0/28 |
| Recorded source Cost Receipts | 0/28 | 0/28 |

57 is the number of tier-specific reviews, not the number of bookings or unique room suppliers. Different stages may reference the same hotel, but must not inherit an independent stay's inventory, cost or validity period. An eligible hotel does not certify an available Unit.

Production has linked and published hotel Units for eligible properties; Staging needs reconciliation against original source receipts and publishing policy rather than copying Production IDs. Program term identity is present for all 7 Production cruise stages but not in the staging bindings; site-local term and WPML mappings need source-level review. Neither site has a selected vessel/cabin or signed sail-date supplier invoice.


## Exact source cause for the nine empty tier-stage reviews

The original Stage Sourcing reader confirms that native Properties are present, but they are unclassified in the package_levels taxonomy across the three Package tiers. The same result was independently read on both tenants:

- Tour 60437, stage hotel-aswan-02 (day 3): 5 original Property candidates are all tier_unclassified, including Obelisk Nile Hotel. Three nonlocal Aswan-area properties are separately rejected by the original locality guard.
- Tour 60627, stage hotel-luxor-02 (day 3): 11 discovered Property candidates are all tier_unclassified, including Sonesta St George Luxor and Hilton Luxor.
- Tour 60629, stage resort-sharm-03 (day 8): 43 discovered Properties are all tier_unclassified, including Steigenberger Alcazar and Baron Resort Sharm.

Each stage is checked at Silver, Gold and Diamond, yielding 3 × 3 = 9 empty tier-stage decisions. **These are classification/assignment gaps, not proof that the destination has no Property inventory.** Filling them requires an authorized source-level Package tier assignment and valid Unit/meal-plan evidence; do not auto-map luxury brands or star ratings to Diamond/Gold/Silver, and do not relax destination checks. No taxonomy posts or terms were modified in this checkpoint.

## Verified native sleeper candidates

Package 60623 has independent train stages. The original stage-sourcing module now resolves a unique, ordered passenger segment from itinerary text and native train-stops, without declaring booking approval.

- Outbound train 86, post 54552: Bashteel to Aswan, 19:45 to 09:25 next day. Bashteel Station ID 68930 on staging and 68922 on Wayfatours; Aswan Station 54258 on both.
- Return train 87, post 54553: **Luxor** Station 54257 at 20:35 to Bashteel at 06:25 next day. Train 87 starts its full route from Aswan at 17:15, but the traveller in this Package boards in Luxor, not Aswan.
- The route proposals are NOT Abela-approved schedules, confirmed seat stock, cabin classes or supplier costs.

The same existing MAD4B Package Stage Sourcing reader now supplies the segment to original Supplier Admission and CP4 Content Readiness alerts, and the original Cost Receipt Gate validates that the passenger's boarding station, arrival station, order and times agree with this route and the receipt. A mismatched station, unbound stage, wrong time or unsigned receipt remains blocked. No parallel rate writer was introduced.

## Tests and controls

- PHP syntax passes across all four selectively updated live MU source modules on both sites.
- Both live sites passed the original read-only inspection of Package 60623 with generation and checkout remaining false; all 28 CP4 cost alerts remain discoverable.
- Isolated in-process WordPress QA on both sites passed nine cost gate cases and mock-signed lifecycle checks. A mock signature is never a commercial receipt.
- Source test: tests/cp4_stage_segment_source_regression.py executes the original PHP source against memory-only fixture data and rejects wrong train/boarding/time and absent source evidence.
- Re-run the source census with ops/pricing-unified-checkpoints/checkpoint4/verify-stage-assignment-matrix-readonly.php, with a legitimate pricing-authorized WordPress CLI user. It cannot write.
- VPS reversible preimages: /opt/mad4b-company-provisioning/reports/cp4-assignment-investigation-20261121/backup.

Remaining: actual tier/property/room choices, site-local Unit and cruise-program parity, vessel/cabin/sailing assignment, sleeper cabin class and Abela fare for specific date/nationality, source contracts, currency/date/occupancy/inventory proof and independent signer. No Tour Rate or payment activation should occur before these.
