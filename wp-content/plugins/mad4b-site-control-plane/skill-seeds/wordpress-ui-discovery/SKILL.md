---
name: wordpress-ui-discovery
description: Discover WordPress page/template/widget dependencies and render paths before changing an interface.
---

## Procedure
1. Resolve site/environment, requested page, single/archive context and active theme/Elementor kit.
2. Inventory all matching Elementor templates, nested listing templates, WPML variants, dynamic tags, widget IDs and active conditions.
3. Map queries, taxonomy sources, JetEngine relations and form/booking connections with read-only tools.
4. Distinguish published templates, drafts, unused templates, transient Lab fixtures and obsolete content.
5. Produce a dependency graph and exact recommended source of truth; never clone templates merely to inspect them.
6. Record object IDs and digests; hand the bounded target plan to the implementation skill.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
