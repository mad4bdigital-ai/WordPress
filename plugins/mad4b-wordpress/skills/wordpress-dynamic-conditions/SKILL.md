---
name: wordpress-dynamic-conditions
description: Implement reusable, secure WordPress display conditions across Single, Archive, AJAX, selected states and device affordances.
---

## Procedure
1. Determine the main queried object; do not confuse listing post context with the page context.
2. Set conditional display on server when possible, not by cosmetic CSS hiding alone.
3. For AJAX, require validated query/provider parameters, signed request context and verified origin/referer; never trust raw post IDs.
4. Preserve no-results, selection, removal and keyboard/touch behavior after listing rerender.
5. Verify conditions for Single, Archive, home, wrong CPT and forged AJAX requests.
6. Document accessibility, caching, localization and performance implications.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
