---
name: wordpress-interface-workflow
description: Repeatable Elementor interface updates across cards, accordion, dynamic global styles, single-page visibility, and responsive interactions.
---

## Scope and intake
1. Confirm target environment, published vs draft templates, canonical Elementor document IDs, and the precise change requested. Default to the original approved template, not duplicate templates.
2. Inspect Elementor element trees, nested template references, existing Dynamic Tags, JetEngine listing context, and WPML translations before writing. Inventory widget IDs and avoid inventing new brand-specific identifiers.
3. Use globally reusable labels and identifiers; never embed a client, site, market, or brand name in new constants, CSS selectors, shortcodes, routes, data attributes, or component names. Preserve external legacy contracts until all consumers are migrated.
4. Inherit Elementor Active Kit typography and global color tokens. Distinguish button typography from text typography. Use rem and fluid clamp units; verify computed font, size, and breakpoints in a real browser.
5. Implement the two listing modes independently: card-level hover/focus and expanded accordion content. For touch devices, ensure actions remain accessible without hover. Respect reduced-motion settings.
6. Place actions in the actual nested content template for accordion; never inject duplicate buttons dynamically when native Elementor templates already own the view.
7. For visibility, assert the main queried document is Single Tour, not merely a nested tour-rate post. For AJAX render, validate signed query context and page referer; reject unrelated pages and forged requests.
8. Keep selector/removal state accurate: Select only for eligible unselected item, Remove only for the selected item, update after filter changes, and clear invalid selections.
9. Use bounded provider operations, exact document identity and precondition checks, readback, and rollback evidence. Never edit Production based solely on Lab acceptance.
10. Accept on desktop and touch mobile: open accordion, hover/focus, click select/remove, zero-results, no overflow, no duplicate actions, and keyboard operation. Preserve the template draft state until release approval.
