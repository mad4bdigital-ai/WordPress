---
name: elementor-dynamic-content
description: Inspect and safely reconcile Elementor templates, dynamic tags, widget data, provider compatibility, and dynamic-content rendering when the user asks why content is missing, incorrect, duplicated, or structurally out of sync.
---

Use MAD4B Elementor read tools first. Use governed write tools only when the user explicitly asks to apply a change and the exact provider/runtime authorization gates are ready.

1. Confirm Elementor provider status and exact certification before trusting provider-specific assumptions or attempting a mutation.
2. Identify the exact source and target post, template, archive, or document. Run `elementor/compare-documents` when reconciling translated/template variants, then read both current document SHA-256 values immediately before planning any write.
3. Inspect the document structure, target parent/index, element IDs, widget settings, and dynamic tags. When JetEngine or another provider supplies the value, inspect that provider's read contract too.
4. Trace each value from source field or relation to dynamic tag to widget output. Distinguish missing data, wrong context, unsupported tag, provider drift, template conditions, and rendering/cache issues.
5. Prefer Dynamic Tags and provider-native dynamic content over shortcodes when both are supported and the user wants a reusable visual-builder setup.
6. For structural parity, prefer the bounded reversible primitives: `elementor/clone-subtree`, `elementor/move-element`, `elementor/delete-element`, `elementor/set-dynamic-tag`, and `elementor/set-etg-dynamic-tag`. Never request or expose raw `_elementor_data` mutation.
7. Clone only from an exact inspected source element. Preserve source IDs, require zero target ID collisions, and bind the write to exact source and target document SHA-256 values.
8. Move or delete only one exact element ID at a time. Never move an element into itself or one of its descendants. Use `__root__` only when the intended target is the document root.
9. Use `elementor/set-dynamic-tag` to copy an exact binding from an inspected source. When no canonical source binding exists, use `elementor/set-etg-dynamic-tag`; it serializes only allowlisted ETG tags through Elementor's own Dynamic Tags manager. Supported canonical tags are `etg-filter-title`, `etg-filter-intro`, `etg-filter-image`, `etg-filter-gallery`, and `etg-filter-result-summary`. Never synthesize arbitrary Elementor tag payloads.
10. Require read-after-write validation and retain the reversible mutation envelope. If the document SHA, element cardinality, provider certification, collision guard, or rollback bound changes, fail closed and re-read before retrying.
11. After every mutation, re-run document validation, dynamic-tag inspection, and the relevant semantic/browser acceptance evidence before treating parity as complete.
12. Return the affected source/target IDs, before/after SHA-256, exact operation, validation result, rollback mutation ID when available, and any remaining external blocker.
13. For WPML or other translated template sets, resolve the canonical translation set through the translation bridge first; do not trust manually copied post IDs. Compare every translated document to the canonical source and preserve each language's exact document identity.
14. For ETG result-count migration, require a valid `etg-filter-result-summary` binding and readback before deleting a legacy `jet-query-count` widget. Never remove both count sources in one unverified step.
15. When the canonical ETG source contains a gallery surface, require an `etg-filter-gallery` binding on the translated target and validate its mode/limit after write. Treat a missing Gallery binding as incomplete parity, not an optional cosmetic difference.
