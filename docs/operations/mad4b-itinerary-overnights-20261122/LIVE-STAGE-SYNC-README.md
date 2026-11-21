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
- **Source Tours.dev:** same exact PHP code SHA checks pass; its mapped hotel catalog opt-in flags are still inactive under its *separate* existing eligibility policy, so source dynamic candidate counts do not match Wayfa. This migration deliberately did not change that unrelated business rule.
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
