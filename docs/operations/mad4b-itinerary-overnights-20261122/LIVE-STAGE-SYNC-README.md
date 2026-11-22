# MAD4B — Native itinerary overnight stage synchronization (live-first)

Status: deployed and verified on **Tours.dev** and **Wayfatours** on 2026-11-22, **not a commercial checkout release**.

## What changes

The native JetEngine CPT `tours-and-activities`, `itinerary_item` repeater gains two select subfields per day:

| Field | Options | Meaning |
| --- | --- | --- |
| `mad4b_overnight_kind` | `hotel`, `resort`, `cruise`, `sleeper_train`, `none` | Type of stay **after** this day's itinerary |
| `mad4b_overnight_destination` | `cairo`, `luxor`, `aswan`, `sharm`, `hurghada`, `nile_cruise`, `train`, `none` | Overnight destination; not a daytime visit |

The last/departure itinerary day must be `none/none`; the other days must each specify a valid type/destination pair. Consecutive nights with identical type and destination form one accommodation stage. Therefore nights = number of itinerary day entries minus one, not an independently maintained Hotel nights field.

Cairo/Giza is the same metropolitan group only under existing explicitly approved destination-scoping rules. Aswan hotel stages require native Aswan City eligibility rather than Aswan governorate alone; Abu Simbel remains excluded.

## Additive runtime files (identical on both production/staging hosts)

- `wp-content/mu-plugins/mad4b-itinerary-overnight-source-v1.php`: parses structured itinerary, builds deterministic source hash and projected stages; updates sync status on itinerary edits; mirrors only overnight metadata into Spanish translated Repeater without overwriting Spanish titles, descriptions or images; returns *editorial* hotel candidates from stage destination plus package tier and owner-approved cost-source references.
- `wp-content/mu-plugins/shared-product-core/pricing/package-stage-rate-guard-v1.php`: preserves existing candidate references and selections; adds only stage-eligible dynamic candidates and blocks commercial readiness when structured source projection is missing/stale or stage geometry diverges.
- `wp-content/mu-plugins/mad4b-package-pricing-lab-readiness-v1.php`: displays the same new stage-city/tier candidate pool as the native Rate Guard. These are **not** Unit Rate bindings, confirmed inventory, or booked Rooms.

No rate, paid booking, lodging inventory, margin override, or supplier receipt is authorized by this package. Pricing companies keep their distinct preexisting 20% (source) / 25% (Wayfa) **target gross margin** rules.

## Mandatory JetEngine schema migration (database, not file alone)

The field definition lives in the JetEngine-native `wp_jet_post_types` row `id=15`, slug `tours-and-activities`, `meta_fields` serialized array. Existing `itinerary_item` has four native subfields (`number`, `title`, `image`, `description`). The guarded migration `tools/wp-cli/enroll-itinerary-jetengine-fields-20261122.php` appends just two select fields, IDs `2026112201/2026112202`, with an atomic old-content SQL comparison.

The schema was already migrated and read back on **both live sites**; the six-subfield hashes match. When enrolling *another* company, run on the authorized WordPress installation:

```bash
MAD4B_ITINERARY_SCHEMA_PHASE=preflight wp eval-file tools/wp-cli/enroll-itinerary-jetengine-fields-20261122.php --allow-root --user=1
MAD4B_ITINERARY_SCHEMA_PHASE=apply wp eval-file tools/wp-cli/enroll-itinerary-jetengine-fields-20261122.php --allow-root --user=1
MAD4B_ITINERARY_SCHEMA_PHASE=verify wp eval-file tools/wp-cli/enroll-itinerary-jetengine-fields-20261122.php --allow-root --user=1
```

Requires an authorized admin. The migration is idempotent and rejects unexpected CPT schema drift. Never simply replace the whole database CPT meta-field array with a copied older array.

## Initial content backfill and locale treatment

Eight existing English package tours each have verified `_mad4b_package_accommodation_source_plan_v1` geometry. A one-time, guarded backfill populated 16 itinerary posts per site (eight EN + eight ES), **132 day records / 264 new subfields per site**, using the old verified geometry as initial evidence and setting final departure day to `none/none`. It did not reword old itinerary titles or descriptions, and did not mutate existing source plans or `_mad4b_package_stay_verified_room_program_links_v1` bindings. One-time approvals and backups are stored in the host checkpoint below; do not repeat migration on unrelated tours without checking their old plans and translations.

## Runtime synchronization and failure behavior

On itinerary metadata changes or tour save, reconciliation runs on WP shutdown and writes only a read-only projection plus a sync state when current nightly stages match the existing source plan. A changed itinerary source hash invalidates the Rate Guard's previous readiness immediately.

When stage geometry changes, automatic reconstruction of the stage plan and its binding record is restricted to the case where no explicit Property, Unit, supplier cost receipt, sailing, vessel/cabin, or native commercial assignment exists. All new stage binding entries remain pending quote, with no inventory authorization. Otherwise the sync status requires editorial/manual reconciliation, and existing bindings remain unchanged.

For new stays the default board is BB, except Sharm/Hurghada resorts/hotels where the default is AI; it must still respect any later explicitly assigned board or signed supplier terms.

**Do not treat this as completed commercial package costing.** Date-qualified hotel selection, occupancy-aware Room Unit/Property Rate fallback, cruise cabin/sailing, transport, guide and extras costs and taxes must be reconciled before a complete customer package price can be committed.

## Live verification (2026-11-22)

- **Both sites:** 8/8 canonical package plans; 16/16 original EN+ES repeater contents preserved after removing only the two added fields; 132 day records per site; all 8 original stage plans and bindings byte-for-byte unchanged.
- **Both sites:** 253 owner-approved Hotels-sheet positive price cells with per-person-per-night basis (some inferred from the owner's approved consistent template); global 20% source / 25% Wayfa margin unchanged, detailed stay margin policies inactive.
- **Wayfatours:** 57 hotel/resort stage × tier instances across eight package tours; 379 eligible candidate mentions (84 stage-specific, owner-approved newly discovered candidates) without selecting any property; *Tolip Hotel Aswan* (Property 71980) becomes a valid Gold/Diamond candidate for its one-night Aswan stage.
- **Source Tours.dev:** its 21/21 source-cell-matched Hotels were subsequently enrolled as native dynamic candidates (9 missing package-tier assignments filled, 12 original explicit tiers preserved) via a separate guarded company-only activation receipt. Its 57 hotel/resort stage×tier instances now have 251 eligible candidate mentions, including 164 stage-specific dynamically discovered mentions. **Wayfatours** remains at 379 total mentions, including 84 dynamic additions, because its older editorial stage candidate pools are wider. Matching 21/21 Hotel eligibility does not justify inventing identical legacy stage candidate counts.
- **Both sites:** exactly **21/21** owner-approved sheet-mapped Hotels opted into native stage candidate discovery (company profits remain 20% source / 25% Wayfa). The 56 approved Property cost-reference Rates/site remain Stop Sale with no checkout authority. Source/public Property catalog status remains separate from Wayfa's intentionally hidden public Property catalog.
- **Rate Guard:** no checkout-ready package stage, no Unit inventory, no commercial rate publication or supplier cost activation.
- **Pure test cases:** 8/8 valid/invalid structured day scenarios pass, including invalid accommodation pairs and final-day overnight, city regrouping and new overnight day, with no DB writes during tests.
- **Pricing Lab** shows Aswan Gold/Diamond candidates on Wayfa, while no unsupported source-site Aswan selection is invented.
- **No authenticated graphical browser regression was claimed.** Prior tests were PHP syntax, WP-CLI native state/logic, postmeta immutable comparison, and independent readback.

## Evidence, rollback and release controls

Full live checkpoint:
`/opt/mad4b-package-checkpoints/2026-11-22-itinerary-overnights-live/`.
Includes original CPT serialized schema, all eight English/Spanish itineraries and source plans/bindings per site, pre-deploy PHP files, backfill scripts and post-apply QA JSON.

For rollback, restore the exact previous code files and prior CPT field definition and original posts using the checkpoint after validating that subsequent editors did not introduce newer legitimate changes. **Never bulk restore a stale DB backup over new user edits.** The added fields and stores are additive and do not authorize changing Rate/booking settings.

This is a **review-only, live-first reconciliation branch**, independent of PR #1003 (transport). Check parity with already deployed live versions and integrate selectively; **do not wholesale deploy master** or merge a stale PR to production.


## Smart JetEngine itinerary dropdowns — live extension 2026-11-22

The `itinerary_item` native select fields are **not left as unconditional manual choices** in the WordPress editor. Two additive files, deployed identically on both live sites, overlay contextual editor-only eligibility on the native JetEngine `<select>` elements:

- `wp-content/mu-plugins/mad4b-smart-itinerary-choices-v1.php`: private nonce-protected WordPress AJAX; `edit_post` + editor authorization. Uses the current tour's **own** attached Silver/Gold/Diamond tiers, actual overnight stage cities and exact tour destinations, the native `stageDestinationScope()` and `propertyEligibility()`, `prop_use_in_tour_rate_generation`, published owner-approved Hotels cost-source receipts and official property-type taxonomy. It must not import tier IDs from the other company or infer supplier availability from published state.
- `wp-content/mu-plugins/assets/mad4b-smart-itinerary-overnight-v1.js`: scoped exclusively to the `post.php` editor of `tours-and-activities`. Per repeater day, hides/disables new selections without a qualified source; reacts to Hotel type vs city and the reverse; allows **None** only on the last departure day; allows a Nile Cruise only during the native, duration-and-direction-verified program days **and** when a published owner-approved Cruise Property exists in the company catalog. A cruise program is editorially admissible but not confirmed vessel or sailing inventory. Sleeper Train is hidden until its overnight supplier is certified. Actual legacy values remain visible/selectable with an explicit warning, never silently removed or replaced.
- Day/locale rule: if an existing seeded itinerary has changed and its projection no longer matches source stage geometry, backend rejects new eligibility until repaired. WPML post day keys must match the canonical EN itinerary. No new commercial Rates, dates, supplier receipts, or checkout authority.
- **New draft editor (`post-new.php`)**: when the Tour has no saved identity, the native select options are fail-closed with an in-repeater instruction to save the draft and define destination/tier first. After saving, a scoped `post.php` editor session can query authorized eligibility. Never guess tiers for an unsaved Tour. If native Package Level taxonomy changes but has not yet been saved, new selections are temporarily disabled rather than evaluated against stale levels. Script and AJAX URLs are forced to HTTPS and JS cache versioned using its content hash.
- Site-wide setting is untouched: there is no new public itinerary panel, public REST endpoint, or behavior for editing unrelated posts. Admin-only choices are translated from **WordPress dashboard locale** (Arabic, English and Spanish); the script uses HTTPS with content-hash version for correct cache invalidation.

**Important:** This is editor **structural eligibility**, not date-specific Hotel inventory. A published Hotel cost receipt or a verified Cruise Program can offer an itinerary choice without being commercially bookable.

Validated native protected AJAX matrix for Tour 60437:
- **Tours.dev:** Cairo 9 qualifying Hotels, Aswan 1, Resort 0; EN tier IDs Silver/Gold.
- **Wayfatours:** Cairo 15 qualifying Hotels, Aswan 1, Resort 0; EN tier IDs Silver/Gold/Diamond.
- Both sites: native Cruise Program days 4–6 eligible for itinerary planning; overnight Train at that stage not supplier-qualified; no Checkout.
- 8 package Tours / 66 itinerary day records audited per site. Existing selections requiring review are **9 source, 5 Wayfa**. Reasons: source Tour 57562 missing native package-tier terms (4 Hotel nights); 2 Sleeper Train nights in Tour 60623 without qualified overnight supplier (both sites); 3 Sharm Resort nights in Tour 60629 without owner-approved and correctly categorized Resort cost Property (both sites). **All remain intact; they are flagged, not auto-rewritten.**

Tests: `node --test tests/mad4b-smart-itinerary-overnight-v1.test.js` (**10/10** with a synthetic matrix on both runs). With real private AJAX evidence on an authorized VPS environment, the same test reads `MAD4B_MATRIX_JSON=<signed-backend-result.json>` and validates the real nonbookable contract. Live PHP lint, source and Wayfa native editor-only script registration, signed AJAX, permission-denial, and HTTPS URL (HTTP 200) were verified. A GUI login-based browser walkthrough of actual JetEngine Vue controls **has not been independently certified**; do not claim browser QA. In particular, any future switch to a non-native custom select renderer needs UI adapter testing.

Evidence: `/opt/mad4b-package-checkpoints/2026-11-22-smart-itinerary-options/`. Existing native JetEngine option data is deliberately kept as an emergency editorial fallback if the overlay is removed; normal editor mode uses a fail-closed authenticated filter, and missing eligibility data does not authorize newly selected services.
