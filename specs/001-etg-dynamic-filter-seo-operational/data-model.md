# Data Model

## Configuration (`etg_dfsb_settings`)
Global groups remain kill switch, query-state settings, result-count authority, legacy Tours migration defaults, diagnostics and `profiles_json`.

## SurfaceProfile
- identity: `id`, `enabled`, `inherit_global_defaults`
- Post Type: `post_types[]`, `require_post_type_binding`, `post_type_authority`
- archive: `archive_paths[]`; legacy inherited profile may additionally use `archive_slugs[]`
- routes: exact `routes[{provider,query_id}]`; provider/query arrays are legacy inheritance only
- taxonomy: `taxonomy_rules{taxonomy => TaxonomyRule}`, `allowed_taxonomy_sets[]`, `max_filters`
- combination: `require_exact_combination_approval`, `require_exact_for_single`, `indexable_combinations[]`
- thresholds: `min_results_by_depth{depth=>count}` plus per-taxonomy single threshold
- content/presentation: `composition_mode`, `canonical_mode`, `content{}`

## TaxonomyRule
`role, priority, gallery_priority, index_single, min_results, required_meta_key, required_meta_values[], meta_constraint_scope, field_map{}`.

## ParsedRequest
`active, request_path, archive_path, archive, provider, query_id, filters, unknown_filters, malformed, duplicates, query_params, canonical_query_params, tracking_query_params, unsupported_query_params, pagination_page`.

## PostTypeBinding v2
`required, authority, observed, matches_profile, reason, post_types[], allowed_post_types[], source, sources{query_builder,main_query}`.

## RuntimeContext v3
ParsedRequest plus `profile_id, profile, scope/scope_valid, language, taxonomy_roles, terms, missing_terms, translation_fallback, readiness/runtime_ready, provider observation/match, post_type_binding, combination_authority, content_readiness, result authority, combo`.

## CombinationAuthority v2
`required, approved, signature, legacy_signature, taxonomy_set, source`.

## ContentReadiness v3
`required, ready, base_ready, override_applied, override_direction, reasons, content_chars, unique_segments, minimum_chars, require_meta_description`.

## ResultCountAuthority
`count|null, source, authoritative, detail`.

## IndexDecision v3
`index|null, base_index|null, follow, policy_class, override_applied, reason, profile_id, taxonomy_set, minimum_results, post_type_authority, post_type_source, post_type_binding_reason, post_types, result authority, combination signature, content readiness, configuration revision`.

## ProfileBlueprint v1
`synthetic=true, authorizing=false, warnings[], profile{enabled=false,...}`.

## SimulationResult v1
`synthetic=true, configuration_revision, profile_id, taxonomy_set, bounded scenario, combination_authority, decision`. Simulation is diagnostic evidence only.


## RuntimeInventory v1
`contract, authorizing=false, read_only=true, profile_mutation=false, limits{}, snapshot_fingerprint, collected_at_gmt, inventory{post_types{},taxonomies{},languages[],query_builder{available,source,queries[]}}`. Raw Query Builder arguments are not exported.

## InventoryReconciliation v1
`contract, authorizing=false, read_only=true, profile_mutation=false, requires_operator_review=true, snapshot_fingerprint, previous_snapshot_fingerprint, state, summary{}, findings[], disabled_candidates[]`.

### DriftFinding
`severity=info|warning|blocking, code, subject, details{}`. Blocking findings do not mutate runtime state; they only prevent treating the evidence as accepted.

### DisabledReconciliationCandidate v1
`authorizing=false, requires_operator_review=true, evidence{observed_archive_paths[],suggested_routes[]}, profile{enabled=false,...}`. Authority-bearing profile fields `archive_paths`, `routes`, `allowed_taxonomy_sets`, and `indexable_combinations` remain empty even when evidence exists.
