---
name: wordpress-component-development
description: Build reusable, non-branded WordPress/WooCommerce extensions and component contracts with guarded rollout.
---

1. Start with existing provider-native capabilities and an extension strategy; choose a custom component only if no supported bounded primitive fits.
2. Separate view, interaction, domain quote and persistence logic into distinct modules, each with a documented contract; never let a Lab-only preview own production selection behavior.
3. Use neutral names for all newly introduced identifiers: WordPress options, functions, classes, constants, REST paths, CSS, DOM attributes, events and files. Avoid client/site/market prefixes and hardcoded term/template/query IDs.
4. Make context, provider selectors and taxonomy labels discoverable/configurable; use active Elementor typography settings and global colors. Distinguish read-only quote endpoints from writes.
5. For selection components, emit an explicit semantic event including rate identity and select/remove intent; let independent consumers subscribe. Scope to a validated Single Tour and protect AJAX context.
6. Maintain strict input validation and server-side source-of-truth for prices, availability, relations, package-hotel eligibility, occupancy and unit quantities; never trust prices supplied by browser.
7. Keep Lab isolation gates separate from component behavior, never relax is_purchasable, payment, email or cron guards for acceptance.
8. Preserve existing external interfaces during refactoring until every persisted reference, template, shortcode and consumer has been updated and verified.
9. Require lint, static reference scan, PHPUnit/contract tests where available, SSR/AJAX/browser acceptance, readback and rollback plan before packaging.
