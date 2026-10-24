---
name: wordpress-performance-regression
description: Measure and improve WordPress page/server performance following interface, query and extension changes.
---

## Procedure
1. Establish a repeatable pre-change baseline: TTFB, server elapsed, DB queries, peak memory, frontend network/JS, and representative samples.
2. Trace expensive JetEngine queries, Elementor nested rendering, duplicate Dynamic Tags and plugin hook costs.
3. Apply reversible targeted optimizations without removing required functionality or masking warnings.
4. Re-run the same load scenario at least three times; compare distributions and budgets with source evidence.
5. Confirm that SSR/AJAX identity and UI behavior remain correct; avoid caching cross-user or price-sensitive booking state.
6. Report pass/fail against the exact site budget and all remaining unsupported metrics.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
