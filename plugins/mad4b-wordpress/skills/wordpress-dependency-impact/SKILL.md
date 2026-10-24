---
name: wordpress-dependency-impact
description: Calculate blast radius and rollback for changes to WordPress templates, content schemas, filters, code and settings.
---

## Procedure
1. Build a dependency graph spanning page→template→nested widget→query→taxonomy/relation→extension→cart/quote.
2. Identify which languages, device layouts, AJAX requests, forms and external integrations consume the changed contract.
3. Classify direct and indirect effects, including latency, permission and security effects.
4. Require exact state snapshots/digests and a reversible change strategy for each mutation.
5. Generate a focused regression matrix and no-go blockers before implementation.
6. Run read-after-write dependency checks and report discovered regressions separately from accepted baseline issues.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
