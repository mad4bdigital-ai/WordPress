---
name: elementor-dynamic-content
description: Inspect and reason about Elementor templates, dynamic tags, widget data, provider compatibility, and dynamic-content rendering when the user asks why content is missing, incorrect, duplicated, or not selectable.
---

Use MAD4B Elementor read tools and related provider tools to diagnose the exact document before proposing a change.

1. Confirm Elementor provider status and certification before trusting provider-specific assumptions.
2. Identify the exact post, template, archive, or document involved.
3. Inspect the document structure and the relevant widget or container configuration.
4. Inspect available dynamic tags and data sources. When JetEngine or another provider supplies the value, inspect that provider's read contract as well.
5. Trace the value from source field or relation to dynamic tag to widget output. Distinguish missing data, wrong context, unsupported tag, provider drift, template conditions, and rendering/cache issues.
6. Prefer Dynamic Tags and provider-native dynamic content over shortcodes when both are supported and the user wants a reusable visual-builder setup.
7. Do not mutate Elementor documents from this read-only skill. Produce an exact proposed change and its expected effect instead.
8. Return the affected element, current binding, expected binding, evidence, recommended configuration, and any provider or certification blocker.
