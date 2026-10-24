---
name: wordpress-schema-migration
description: Plan and verify reversible CPT, taxonomy, metadata and JetEngine relation migrations without duplicating obsolete models.
---

## Procedure
1. Inventory existing CPTs, taxonomies, JetEngine relation definitions, WooCommerce products and existing dynamic tags.
2. Treat approved live template bindings as current source of truth; identify legacy records and whether retention is necessary.
3. Design field/relation mappings, translation parity, indexing, roll-forward and rollback.
4. Run a dry run on isolated clone with counts, referential integrity and per-object receipts.
5. Never remove content or legacy fields until verified zero active consumers and approved deletion scope.
6. Validate forms, queries, listing templates and WooCommerce behavior after migration.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
