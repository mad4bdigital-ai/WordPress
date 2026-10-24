---
name: wordpress-visual-regression
description: Conduct governed visual and interaction comparisons for responsive WordPress templates and AJAX states.
---

## Procedure
1. Capture controlled baseline and candidate renders for the exact page, viewport, language and data selection.
2. Exercise native pointer hover, keyboard focus, touch, accordion expand, select/remove, AJAX filter reset and empty state.
3. Compare screenshot regions, DOM structure, typography tokens, overflow and visibility states; distinguish animation timing from persistent failure.
4. Wait for actual data requests and attached select options, not visual visibility of HTML option elements.
5. Diagnose script and network errors. Use a safely scoped acceptance fixture only if necessary and remove it reliably.
6. Record evidence and do not claim test PASS if the browser setup itself failed.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
