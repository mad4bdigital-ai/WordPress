---
name: jetengine-content-modeling
description: "Analyze JetEngine custom post types, meta fields, taxonomies, relations, listings, and dynamic-content models when the user asks how content should be structured or why a JetEngine-backed value is not resolving."
---

Use this skill for read-first JetEngine content-model diagnosis and design.

1. Confirm JetEngine provider status and exact runtime certification before assuming version-specific behavior.
2. Identify the content entity: post type, taxonomy, meta field, relation, listing, query, or user/object relation.
3. Trace the requested value from its source object through relation/query context to the consuming Elementor or JetEngine widget.
4. Distinguish data-model defects from presentation defects. A missing relation, wrong meta key, wrong object context, or empty record should not be treated as a widget styling issue.
5. Prefer stable identifiers and explicit relation direction over title-based matching.
6. When proposing a new model, specify entity, field/relation cardinality, source and destination objects, query context, and how the presentation layer will consume it.
7. Do not alter schemas or records from this read-only skill. Return a migration-safe proposal when a model change is required.
8. Return current model, observed gap, proposed model or binding, evidence, migration impact, and verification steps.

## Shared Listing frontend integrity — MAD4B Frontend Quality v1

1. Identify Listing source, JetEngine content type, Query Builder context, dynamic field relationships and every Elementor consumer. Include homepage, archive, destination, single pages, translated Listings and injected banners.
2. Separate data-model defects from presentation defects. Source Listing owns data field bindings and image source; Elementor Listing Grid owns columns, spacing and injection placement. Site-wide radius and typography tokens belong to shared presentation. When consumers need contradictory aspect ratios, use scope-specific presentation or distinct Listing variants.
3. Measure actual card dimensions and text placement. For a circle enforce a 1:1 rendered ratio plus 50 percent radius; for landscape enforce the agreed rectangular ratio. Avoid rigid min-height with fluid grid widths, especially on tablet and translated pages.
4. Keep heading, result count and CTA in normal flex/grid flow. Old absolute positions, fixed pixel offsets and inherited margins cause overlap when text wraps or RTL changes.
5. Inspect injection_items slot, colspan, injected template ID, published locale, source card height, banner height, heading hierarchy and grid rows. Never shrink the entire page section just to align injected banners.
6. Determine availability from published, language-correct post/query relationships; do not interpret taxonomy existence as tours available. Keep child categories nested, avoid promoting untranslated children into root menus, and keep truly dynamic shared reviews as one data source.
7. Verify the WPML term_taxonomy_id language and Listing translation IDs. When get_terms with object_ids appears empty, compare actual wp_get_object_terms relations before treating it as no data.
8. For image performance inspect bounding rectangles of visible carousel slides, especially the rightmost desktop image; compare background image computation, responsive crop and LCP. Avoid hiding a slide only because Swiper has not marked it active.
9. Authorized provider-native mutation requires a fresh source fingerprint, impact graph, rollback, post-write data readback, Elementor CSS regeneration where relevant, and cache invalidation.
10. Regression-test all consuming page types and languages; return codes such as shared_listing_regression, listing_geometry_mismatch, stale_compiled_elementor_css, listing_injection_mismatch, lazy_visible_image, wpml_relation_fallback with supporting observations.
