---
name: elementor-design-system
description: Apply Elementor Active Kit design tokens and responsive styles without hardcoded fonts or brand-specific names.
---

## Procedure
1. Read active Elementor Kit system/custom typography, button typography, global colors, breakpoints and dynamic tokens.
2. Inspect computed browser typography and distinguish inherited Text from Button typography.
3. Prefer Elementor native global tokens; when a CSS bridge is necessary, resolve active Kit fields dynamically.
4. Use rem, fluid clamp, accessible 2.5rem minimum touch controls, focus-visible and reduced motion.
5. Apply differences for Cards and Accordion to the original approved templates and nested content.
6. Verify desktop/tablet/mobile font, contrast, overflow, hover/focus/touch and visual regression.

## Mandatory cross-cutting contracts
- Naming governance: never introduce site/client/brand/market prefixes into reusable code, constants, CSS, DOM data attributes, API routes, shortcodes or skill names. Scan all persisted references before renaming an existing public contract.
- Source-of-truth registry: map each value/widget/field to its controlling system (Elementor, JetEngine, JetSmartFilters, WooCommerce, or shared extension). Do not duplicate an existing authoritative field.
- Change evidence ledger: capture environment, object IDs, before/after digests, allowed authority, reversible mutation/rollback, tests, and unresolved gaps. Treat missing evidence as not verified.
- Mutations require exact scope and governed authorization. Production is independently gated; Lab tests do not confer release authority.
