---
name: wordpress-component-registry
description: Maintain reusable WordPress component contracts and inventory every template or plugin consumer.
---

## Procedure
1. Assign neutral stable IDs, event contracts, shortcode names, REST contracts and semantic versions to reusable components.
2. Discover consumers via source scan plus persisted Elementor/JetEngine metadata and translated variants.
3. Track owner, purpose, input/output schema, data source, supported devices and provider runtime requirements.
4. Prohibit duplicate implementations of the same state/quote/view behavior and prefer small independent modules.
5. Provide compatibility aliases and bounded migration for existing persisted contracts; never rename in place without readback.
6. Report usage inventory, contract validation and rollback before deprecation.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
