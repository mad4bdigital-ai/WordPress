# Alpha11 Runtime Topology & Dynamic Content Contract

Version: `0.4.0-alpha.11`

## Purpose

Alpha11 removes side-channel/manual identity discovery from the normal operator workflow. The plugin must discover the Elementor → JetSmartFilters → JetEngine relationship itself and expose the evidence without turning discovery into indexing authority.

## Runtime topology

1. JetSmartFilters/provider `query_id` and JetEngine Query Builder custom `query_id` are separate namespaces.
2. Elementor `_element_id` may identify the provider/filtering surface while Elementor `custom_query_id` is only a locator to the Query Builder object.
3. A numeric internal Query Builder ID is evidence/locator only and is never stable route authority.
4. When the exact matching Surface Profile route explicitly declares `query_builder_query_id`, that value is the deliberate namespace bridge for the route. Runtime consumers must resolve that exact Query Builder custom ID first and fail closed if it is missing or ambiguous; they must not silently fall back to an equal provider query ID or topology for that route.
5. Without an explicit route bridge, a provider query ID may resolve directly when it exactly equals one unique Query Builder custom ID.
6. Otherwise the plugin may correlate Elementor `_element_id` + `custom_query_id` with the Query Builder inventory.
7. Correlation must fail closed on missing, ambiguous, unbounded, non-posts or custom-ID-missing records.
8. Runtime topology discovery and explicit route resolution do not by themselves enable a Profile, Global bridge, indexing or publication authority.
9. Runtime topology discovery is read-only, non-authorizing and profile-non-mutating.

## Nested Elementor template topology

1. An Elementor `template` widget with a scalar `settings.template_id` is an explicit local reference to another Elementor Library template and may be followed for diagnostic topology discovery.
2. Referenced-template traversal is read-only and bounded independently from the global Elementor Library catalog: maximum cross-template depth, maximum on-demand referenced templates, maximum observed references and the existing global element budget all apply.
3. Traversal must be cycle-safe and must never scan the same template twice in one discovery pass. A template already present in the bounded global catalog remains a single topology source; its parent→child relationship is recorded without duplicating bindings or query surfaces.
4. Each observed relationship exposes non-authorizing provenance including source template, source widget node, target template, depth, resolution status and reference chain where available.
5. Missing, malformed, cyclic, depth-limited or budget-limited references remain explicit warning/review evidence. They must not be guessed, rewritten or promoted to indexing authority.
6. Provider-group enforcement remains scoped to the template in which the Listing/filter surface is actually observed. A composed root such as a Destination archive may contain multiple nested sections with different provider groups and post types; the root relationship alone must not merge those child groups into one authority scope.
7. The legacy bounded global template scan remains available for independent template inventory. Explicit references may additionally load an Elementor Library template that falls outside that global catalog bound, but only through the same read-only `_elementor_data` source and allowed template statuses.
8. Runtime topology cache reuse requires the nested-reference evidence fields introduced by this hardening so an older same-contract transient cannot mask the new discovery semantics.

## Provider-group drift

1. A verified Elementor Listing → Query Builder binding establishes a provider-group anchor for the template in which it was observed.
2. JetSmartFilters widgets in the same template may be compared with those verified anchors without mutating Elementor or Profile configuration.
3. A widget that names a different provider query ID must remain visible as topology drift evidence.
4. If the foreign provider group can be resolved to a bounded Posts query whose post types do not intersect the verified anchor post types, the drift reason is `provider_group_post_type_mismatch` and its severity hint is `blocking`.
5. For an enabled Surface Profile whose route is one of the verified expected provider groups, a proven cross-post-type mismatch is a fail-closed reconciliation blocker.
6. The same proven drift on a disabled Profile remains warning/review evidence and does not create activation authority.
7. If a foreign provider group cannot be resolved to sufficient post-type authority, the drift reason is `provider_group_unbound_in_template`; it remains advisory `warning` evidence and must not be promoted to a route blocker merely because the Profile is enabled.
8. Inventory-level drift summaries remain warning evidence even when individual proven route mismatches can block an enabled Profile.
9. Provider-group drift detection is diagnostic only: it does not rewrite Elementor settings, Query Builder objects, Profiles, URLs or SEO publication state.

## JetSmartFilters definition drift

1. A taxonomy-based JetSmartFilters definition has distinct source and query-target evidence. `_source_taxonomy` does not prove that `_query_var` or `_custom_query_var` targets the same taxonomy.
2. The diagnostic inspector may read Elementor filter surfaces and the referenced JetSmartFilters filter metadata, but it remains read-only, non-authorizing and profile-non-mutating.
3. Only strict `_tax_query::<taxonomy>` targets are interpreted as taxonomy target authority. Unknown or unsupported query-variable shapes must not be rewritten or guessed.
4. When `data_source=taxonomies` and a non-empty source taxonomy differs from a non-empty parsed target taxonomy, the inspector emits `source_taxonomy_query_target_mismatch` evidence.
5. Inventory-level filter-definition drift remains a `warning` so a facet outside SEO scope does not globally block an otherwise safe profile.
6. A mismatch becomes `profile_filter_taxonomy_target_drift` for a Surface Profile only when:
   - the Elementor filter surface uses the exact provider query ID of that Profile route, and
   - either the source taxonomy or target taxonomy is explicitly governed by that Profile's `taxonomy_rules`.
7. For an enabled Profile, a governed route mismatch is fail-closed and blocking. For a disabled Profile it remains warning/review evidence.
8. A mismatch whose source and target are both outside the Profile's governed taxonomy rules must not be promoted to a route blocker; it remains visible only through the inventory-level diagnostic finding.
9. Filter-definition diagnostics must never mutate JetSmartFilters posts/meta, Elementor templates, Profiles, URLs, or SEO publication authority.
10. JetSmartFilters definition inspection distinguishes source availability from observation completeness. Every observed JetSmartFilters widget is a candidate surface even when its filter identity cannot be resolved; unresolved identities and unavailable definitions remain explicit evidence rather than disappearing as zero-count clean state.
11. Runtime Inventory may cross-check JetSmartFilters candidate surfaces against independent Elementor topology query-surface evidence. When complete topology observes more JetSmartFilters query surfaces than the definition inspector, the inspection is `incomplete` with `topology_filter_surface_parity_mismatch`; it is never treated as clean drift evidence.
12. Incomplete JetSmartFilters definition evidence remains non-authorizing inventory warning evidence. It does not grant or revoke Profile, URL, canonical, sitemap or indexing authority by itself.

## Elementor Listing saved-configuration drift

1. Saved Listing Grid configuration may be inspected only after Runtime Topology has verified the exact Elementor provider route and its bounded Query Builder post-type authority.
2. When a matching `jet-listing-grid` uses `custom_query=yes`, a non-empty saved `custom_post_types` set that differs from the verified Query Builder post types is `listing_custom_post_type_mismatch` with `blocking` severity hint.
3. Saved local `posts_query` taxonomy clauses may be compared with the Runtime Inventory taxonomy registry. A taxonomy proven to belong only to foreign post types is `listing_taxonomy_post_type_mismatch` with `blocking` severity hint; an unknown taxonomy remains `listing_taxonomy_authority_unresolved` warning evidence.
4. The Listing Grid may reference a JetEngine Listing Item through the live vendor spelling `lisitng_id` or the forward-compatible spelling `listing_id`. The inspector may read only that referenced item's `_listing_data` metadata for source diagnostics.
5. When `_listing_data.source=posts` and its non-empty `post_type` differs from the verified Query Builder post types, the inspector emits `listing_item_source_post_type_mismatch` as `warning` / review evidence. This metadata is not promoted to blocking authority until its runtime effect under `custom_query=yes` is independently proven.
6. Non-post Listing Item sources are not reinterpreted as posts authority, and malformed or absent `_listing_data` must not be guessed or rewritten.
7. Blocking Listing Grid findings may fail closed an enabled affected Profile through `profile_elementor_listing_configuration_drift`; Listing Item source metadata warnings flow only through `profile_elementor_listing_configuration_review` and do not block by themselves.
8. Template and referenced Listing Item metadata may be cached only in request/reconciliation memory. No transient, option, post-meta, Elementor document, JetEngine Listing Item, Profile, URL, or SEO publication mutation is authorized by diagnostics.

## Inventory scale

1. Detailed Query Builder output remains bounded to 100 records for explainability and payload safety.
2. Identity evaluation uses a separate bounded index of up to 2000 records.
3. Reconciliation may use the complete identity index even when the detailed list is truncated.
4. A collision on the exact Query Builder identity used by a profile remains blocking for that profile.
5. Unrelated collisions are visible evidence and do not automatically invalidate an otherwise uniquely resolved profile binding.
6. JetSmartFilters filter-definition diagnostics are optional inventory evidence. Their absence does not reclassify the core Runtime Inventory as unavailable; when present they participate in the snapshot fingerprint and drift review.

## Inventory-driven dynamic content

1. Runtime Inventory may be transformed into a non-authorizing content token catalog.
2. Tokens can reference composed context, result counts, URLs, taxonomy-role term fields, bounded observed term-meta keys and verified topology evidence.
3. Operators may create reusable Dynamic Content Slots from catalog tokens.
4. Slot configuration controls ID, label, output type, template, fallback, prefix, suffix and maximum length.
5. Saving or enabling a slot does not enable Global bridge, a Surface Profile, indexing, sitemap publication, canonical emission, hreflang or schema publication.
6. Slots record the Inventory fingerprint from which they were edited for auditability; drift does not silently authorize changes.

## Presentation adapters

The same presentation resolver must be available through:

- Elementor Dynamic Tags (primary authoring UX),
- legacy/portable shortcodes,
- PHP functions for JetEngine callbacks or other integration layers.

Dynamic Tags include fixed common values plus an Inventory Value selector and Dynamic Content Slot selector.

## Production boundary

`merge_authorized=false` and `production_activation_authorized=false` remain mandatory. Alpha11 can be installed for dark validation with Global bridge OFF. Static CI success does not authorize Production indexing.
