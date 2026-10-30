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

## Field-level Intelligence Fusion — implementation (2026-10-30)

The non-destructive **field comparison and canonical draft** service is `central/273-mad4b-hub-field-fusion.php`, class `MAD4B_Hub_Field_Fusion_V1`. Tourism-specific metadata rules live separately in `central/274-mad4b-tourism-field-policy.php`. The generic typed-dedup specialist `272` attaches an individual `field_fusion` proposal to each review candidate. No new post type, side database, vendor certificate, product override or public content endpoint was introduced.

**Granularity** is exactly `company_id + source_type + field_key`. Each rule stores a strategy and 0–100 **relative weight** in standard WordPress option `mad4b_hub_field_fusion_policies_v1`. Available strategies are richest, freshest (only with verified modification timestamps), union_review, exact, manual and keep_separate. All proposals are **review required** and all raw records remain untouched. Weighted richness is a heuristic, NOT a probabilistic duplicate identity guarantee.

**Data fields** include localized title/description/summary; source URL and slug as provenance, WPML TRID as immutable group identity, company-owned source taxonomy references, origin place/location identity, hotel address/place ID slots, transport from/to station identities and named reviewed source metadata. No copying arbitrary source postmeta. Source exporter allows only `tour_mode`, `tour_type`, `trans_route_estimated_duration`, `trans_train_operator` as safe add-on scalar fields. `_mad4b_hub_import_source_metadata` is saved in the private imported Hub CPT. Original 827 records, source company identities and historical 417 Nile records remain intact.

**Quality policy:** missing vs present, simple placeholder detection, normalized exact-equivalence, bounded nonempty description richness, optional source modification recency, identities with explicit conflicts and field evidence hashes. For each candidate the service reports per-field state, quality of each source value, proposed source Hub ID when suitable, competing values and hashes, unresolved fields and a *read-only canonical draft* with the original source IDs. `can_apply=false`, `auto_merge=false`, `auto_publish=false` regardless of score. An identity conflict marks `identity_conflict_hold`. Cross-company and cross-language pairs cannot be fused by this function; taxonomic numeric IDs are never auto-applied across WordPress sites.

**Sensitive fields:** source rates/prices/credentials/customer data are not in the exporter field allowlist. Extension fields may be marked `sensitive=true` (then always keep separate; no exposed preview or hashes). Exact identity fields cannot be demoted by an administrator's strategy override. WPML group identity is never merged.

**Company UI:** Content Hub → Field Fusion Policies on Central Staging. Admins can choose their registered company, content type, per-field strategy and weight; each row has its own nonce-protected admin POST. Admins can also add more typed fields in a company-specific adapter using `MAD4B_Hub_Field_Fusion_V1::register_field(type, field, spec)`; do not hard-code company-specific data in generic engine.

**Live Staging acceptance:** source export and private Hub import count 827, fields populated on 229 source rows (217 tour_mode and 12 transport duration), descriptions available on 231; 596 mostly-hotel descriptions genuinely unavailable from the existing source. Typed scan: 72 review candidates, no truncation, 72 attached field-level proposals, no automatic merges. Hotels Regency Pyramids 36286/36288 and M.S. Esplanade 35966/36062 are still *candidate_not_verified* rather than confirmed same entity. Staging site HTTP 200; distributor disabled.

**Next stage** for independently authorized sources: register hotel identifiers (Google Place ID, address, business reference, geolocation, chain/brand and property categories) in that provider's field adapter, establish provenance and content-reuse rights, and review conflicts before producing any durable unified entity. Do not fabricate missing metadata or automatically select one company's content for another company's site.
