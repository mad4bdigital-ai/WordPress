# MAD4B Content Hub - Intelligence Dedup Architecture

Scope: generic and specialist dedup discovery on Central Staging. No merge, overwrite, delete, public publishing, or auto-distribution.

## Two layers

Core: ops/central-company-rest/central/270-mad4b-hub-dedup-intelligence.php (MAD4B_Hub_Dedup_Intelligence_V1).
It scans the existing private imported Content Hub records across company and source type, within a bounded scope. It reports:
- Exact source-key duplicates with key company:type:language:source_id.
- Normalized same-title collisions within one tenant/type/language as review candidates only.
- Exact non-empty content fingerprint collisions; blank bodies are treated as unknown, never duplicates.
- Same-title cross-company candidates with each tenant's ownership preserved.
- WPML translation families when existing import translation-group metadata is available; language variants are not auto-merged.
- Specialized adapter evidence and linked canonical source records.

Specialists: register via the callable contract MAD4B_Hub_Dedup_Intelligence_V1::register_adapter('company_properties_v1', ['Company_Properties_Adapter','discover']) on WordPress init priority 35. Callback receives scope, read-only normalized records and source-key index; returns evidence only. More adapters may be built for location/place_id/address of properties, transport operator/route, tour itineraries, and provider canonicals without modifying core.

Tourism Nile specialist: central/271-mad4b-hub-dedup-tourism-nile.php uses existing MAD4B_Hub_Source_Reuse_Gate_V1 **previously evaluated** decisions and existing Hub meta. It does not re-run the hard-coded 18-category/35-tour admission gate over the 827-record import. It preserves category canonical links, WPML groups, native-post review holds, curated Esmerada legacy-demo evidence, and historical non-bookable rate records. All matches are proposed links, not merges.

## Admin interface
- Admin-only Content Hub -> Dedup Discovery.
- Admin-only GET /wp-json/mad4b-central/v1/content-hub/dedup-discovery with optional company_id and source_type query parameters.
- Only Staging host tours.dev.mad4b.com; read-only, max 3000 records; max 30 candidate examples/group. A specialist failure makes the report fail rather than suggest all candidates were checked.

## Verified acceptance, 2026-10-30
- 827 private imported records, 0 duplicate source identity keys.
- 3 normalized title-collision candidate groups (not confirmed duplication).
- 827 blank body fields, so no valid semantic content comparison yet.
- 35 exact legacy Nile source overlaps, 18 existing canonical category links, 16 known WPML multilingual families; 2 native collision review holds and 2 curated demo records.
- 417 preserved original Nile records (35 tours, 18 categories, 364 historical rates).
- 0 content merges/deletes or production mutations.
- Additional code regression test: tests/test-hub-dedup-intelligence.php.
- Existing strict source-reuse rules: SOURCE-REUSE-AND-DEDUPE-POLICY.md.

## Future data required for deeper intelligence
Do not auto-merge based on titles. Enrich the source read contract with actual descriptions, native source slug/canonical URL, WPML group identity and destination/type-specific fields. Then consider scored candidates with documented evidence and explicit review disposition. The current host-side WP-CLI SSH importer is independent of Control Plane; later Company Controls integration should reuse this discovery contract instead of constructing a second deduplication engine.
