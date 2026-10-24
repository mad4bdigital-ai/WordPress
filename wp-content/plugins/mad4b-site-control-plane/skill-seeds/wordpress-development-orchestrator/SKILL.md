---
name: wordpress-development-orchestrator
description: Coordinate discovery, impact, implementation, validation and governed release using MAD4B WordPress skills.
---

## Dispatch contract
1. Resolve the exact user request, target site and environment; start read-only and load the relevant provider skills.
2. Dispatch `wordpress-ui-discovery`, `wordpress-component-registry`, and `wordpress-dependency-impact` to discover actual sources and blast radius.
3. Obtain a bounded implementation plan through `wordpress-change-safety`; authority gates, exact digests and approvals remain external and mandatory.
4. Dispatch `elementor-design-system`, `wordpress-dynamic-conditions`, `wordpress-filters-workflow`, `wordpress-schema-migration`, or `wordpress-component-development` according to the actual change.
5. Execute only permitted, reversible mutations; immediately read back every persisted structure and check the source-of-truth registry.
6. Dispatch `wordpress-visual-regression`, `wordpress-performance-regression`, `woocommerce-booking-acceptance`, and `wordpress-interface-release-qa` for applicable scenarios.
7. Preserve positive and negative test evidence, including failures, blocked tests and environment limits.
8. Complete a governed review and explicit release handoff. Never automatically deploy a Lab change to Production or bypass purchase/payment protections.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
