# Alpha11 Runtime Topology & Dynamic Content Contract

Version: `0.4.0-alpha.11`

## Purpose

Alpha11 removes side-channel/manual identity discovery from the normal operator workflow. The plugin must discover the Elementor → JetSmartFilters → JetEngine relationship itself and expose the evidence without turning discovery into indexing authority.

## Runtime topology

1. JetSmartFilters/provider `query_id` and JetEngine Query Builder custom `query_id` are separate namespaces.
2. Elementor `_element_id` may identify the provider/filtering surface while Elementor `custom_query_id` is only a locator to the Query Builder object.
3. A numeric internal Query Builder ID is evidence/locator only and is never stable route authority.
4. A provider query ID may resolve directly when it exactly equals one unique Query Builder custom ID.
5. Otherwise the plugin may correlate Elementor `_element_id` + `custom_query_id` with the Query Builder inventory.
6. Correlation must fail closed on missing, ambiguous, unbounded, non-posts or custom-ID-missing records.
7. Runtime topology discovery is read-only, non-authorizing and profile-non-mutating.

## Inventory scale

1. Detailed Query Builder output remains bounded to 100 records for explainability and payload safety.
2. Identity evaluation uses a separate bounded index of up to 2000 records.
3. Reconciliation may use the complete identity index even when the detailed list is truncated.
4. A collision on the exact Query Builder identity used by a profile remains blocking for that profile.
5. Unrelated collisions are visible evidence and do not automatically invalidate an otherwise uniquely resolved profile binding.

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
