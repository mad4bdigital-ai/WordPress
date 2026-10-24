---
name: wordpress-filters-workflow
description: Repeatable JetEngine and JetSmartFilters setup, filter-context integrity, AJAX parity and query condition acceptance.
---

1. Inventory actual CPTs, taxonomies, relations, Query Builder queries, field keys, templates, and linked filters; never assume project-specific IDs.
2. Treat Elementor/JetEngine live bindings and approved native replacements as source of truth; avoid recreating retired CPT metadata or unnecessary migration fields.
3. Derive package, program, season and date choices from currently eligible related rates in the current Single Tour, not from hardcoded taxonomy IDs, titles, or query numbers.
4. Preserve an independent availability query for parent visibility; do not let active user filters hide the entire filter area.
5. Handle SSR, AJAX and URL reload consistently. Verify and bind signed provider context, queried tour ID, referer and provider settings rather than trusting caller-supplied query IDs.
6. Return exact result identities, counts, and deterministic empty states. Test combinations, no results, out-of-range dates, expired rates, filtered selections, refresh and back navigation.
7. Confirm that re-rendered listing actions retain correct selected state. Use component-owned event delegation, not one-time per-element listeners that are lost after AJAX.
8. Record filter schema/values, before/after template digests, AJAX response identity, browser parity and reversible change details.
