---
name: jetengine-content-modeling
description: Analyze JetEngine custom post types, meta fields, taxonomies, relations, listings, and dynamic-content models when the user asks how content should be structured or why a JetEngine-backed value is not resolving.
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
