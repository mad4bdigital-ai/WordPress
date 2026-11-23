# MAD4B JetEngine Operational Services v1

Live published on `tours.dev.mad4b.com` and `wayfatours.com` on 2026-11-23, using the **native JetEngine** CPT, taxonomies, relation registry and WordPress post storage. The MU plugin keeps the source contracts and falls back on registration *only* if native JetEngine has not registered the CPT at priority 10. Never register the same CPT twice.

## Native design

- CPT `operational-services`, **private** (`public=false`, `publicly_queryable=false`, `show_in_rest=false`), internal editor only.
- Taxonomies `service-family`, `service-type` same privacy posture, JetEngine owned.
- 12 editable meta keys `_mad4b_os_*`, matching preexisting MU field storage byte-for-byte.
- Nine Tour Leader service posts, `unpriced`, supplier `self` by default, with no retail price approval.
- Native JetEngine many-to-many relation `posts::tours-and-activities` -> `posts::operational-services` for **eligibility only**; itinerary-day operational assignment stays in original Operations overlay.
- Eight original Package Tours were linked with source-backed Airport Meet & Assist eligibility only after validating the day-1 explicit welcome/staff text.
- `MAD4B_Tour_Leader_Itinerary_Audit_V1` analyzes original itinerary days/assigned cruise program and maps candidate roles to real JetEngine service IDs; candidates are read-only, distinct from guide/driver and from retail and supplier confirmations.
- Native Woo `MAD4B_Booking_Operations_Source_Families_V1` composes itineraries and Jet relation evidence; existing 278/279 commercial order editors remain the only billing / payment authorities.
- `MAD4B_Operational_Services_V1::pricePreflight()` reports unresolved costs; NEVER adds a cost or approves a generated Tour Rate.

## Schema/data rollout, only if not already installed

These guarded, stepwise `wp eval-file` migration scripts are provided for an authorized site root with a verified and restorable backup:

1. `jet-engine-catalog-schema.php`: run without `MAD4B_JET_EXECUTE` for schema preflight, then with `MAD4B_JET_EXECUTE=YES` to create native JetEngine CPT/taxonomies and ownership marker. All are REST-private at the **initial create**; never expose the internal CPT publicly during migration. Native JetEngine stores these in `wp_jet_post_types` and `wp_jet_taxonomies`, not in `jet_engine_post_types` WP options.
2. `seed-tour-leader-catalog.php`: preflight, then `MAD4B_SEED_EXECUTE=YES` to create nine reusable internal posts and taxonomies, with cost unpriced and supplier self. Existing posts are reused by native service-type slug.
3. `jet-engine-service-relation.php`: preflight, then `MAD4B_RELATION_EXECUTE=YES` to create the M:N native Jet relation.
4. `link-native-airport-eligibility.php`: preflight, then `MAD4B_ASSIGN_ELIGIBILITY=YES` to link eight source-verified Packages to meet/assist; never create retail charges.
5. `lock-internal-rest.php`: migration safety check/idempotent privacy hardening of the three schema records if upgrading a previous version that accidentally had REST enabled.

Run migration commands against **each tenant's own** WordPress runtime, never copy numerical post/term/relation IDs across tenants. Preserve tenant-specific pricing and billing policies; do not copy 278/279 source files from Tours.dev to Wayfa, as they differ. Do not copy `/tmp` source artifacts into the live MU directory. Actual rollback backups for this run live under `/opt/mad4b/operations/operational-services-release-20261123/backup`; JetEngine native schema and saved posts require a deliberate scoped rollback (not just deleting plugin files).

## Verified

- Jet CPT and 12 fields + 2 taxonomies: both tenants; 9/9 internal services and 9 service-type terms, 1 family term each.
- Native M:N relation: Tours.dev `397`, Wayfa `399`; 8/8 eligible original Packages on each tenant.
- Original source day audit: Tours.dev 1,584 items, Wayfa 635, candidate vs guide roles distinguished.
- Original Wayfa Woo Order 69842: 5 Tour Leader review candidate source rows, 1 eligible Jet relation with no duplicate billing line; no live customer order writes.
- Anonymous REST `wp-json/wp/v2/operational-services`, `/service-family`, `/service-type` is HTTP 404 for both sites. Authenticated WP admin editor/catalog queries still work.
- Native Rate preflight reports candidate count and does not affect retail price. No cost receipts, supplier bookings, invoices, deposits or payment status changes were authorized by this release.
- PHP syntax and JavaScript syntax verified. **Browser UI save/readback is not claimed:** run privileged interactive browser QA before declaring that test complete.

## Deployment caution

The operational plugin assets are additive to a historical live Woo 278/279 order editor and source tour/rate Jet definitions that are not tracked by this repository branch. This branch **does not** bundle or overwrite those separate tenant-specific implementations. Reconcile them in a dedicated release, not by copying older financial code over newer production files.
