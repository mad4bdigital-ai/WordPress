---
name: wordpress-archive-audit
description: Audit a WordPress archive, listing, taxonomy, search, or post-type template when the user asks to assess completeness, dynamic data coverage, filtering, pagination, template conditions, or content quality.
---

Use this skill to inspect an archive as a system, not only as a visual template.

1. Identify the archive type, post type or taxonomy, query context, template, and the dynamic providers involved.
2. Confirm that the archive query returns the intended entities and that template conditions target the intended archive.
3. Inspect the listing/card fields and trace every important value to its actual dynamic source.
4. Check filtering and pagination contracts when JetSmartFilters or another filter provider is present.
5. Flag orphaned media, missing dynamic values, duplicated fields, empty states, inconsistent cards, query-context mismatches, and provider/runtime blockers separately.
6. Evaluate discoverability and operational usefulness as well as presentation: title, primary media, key facts, taxonomy context, CTA destination, and filter semantics.
7. Do not perform edits from this read-only skill. Produce prioritized changes with acceptance criteria.
8. Return current architecture, confirmed issues, evidence, recommended additions or removals, priority, and verification checklist.
