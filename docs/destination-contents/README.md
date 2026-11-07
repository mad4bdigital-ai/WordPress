# MAD4B Destination Contents — Elementor + WPML

## Scope and source of truth

The original Elementor Table of Contents widget supplies the heading order, labels, and fragment targets. The MAD4B destination widget is a mobile progressive enhancement, not a replacement for the source data. Rendered links follow the exact source sequence by default. Optional "Group by topic" preserves the previous behavior through the native Elementor widget setting.

WPML labels for EN, AR, FR, DE, IT, and ES are resolved from the active WordPress language. Each translated Elementor archive template may contain the same native widget. Company branding is inherited through CSS variables and normal Elementor controls.

## Lifecycle

1. Locate the nearest Elementor document and its Table of Contents source.
2. Wait for the source's dynamically generated links and headings.
3. If responsive changes orphan a fragment, restore a zero-height nearby alias without overwriting the heading's ID.
4. Keep the original Elementor TOC visible until all valid source fragments resolve.
5. Render nested groups while preserving source order. Suppress only the paired old source on mobile after native readiness.
6. Reconcile after Elementor DOM mutations, responsive breakpoint changes, and widget replacement; close the drawer after navigation.

The original source TOC remains a fallback without JavaScript.

## QA

Run `node tests/destination-contents/contents.spec.cjs` with `playwright@1.64.1` and Chromium installed. Browser tests cover progressive fallback, missing/returned anchors, duplicate source rows, responsive updates, dynamic widget replacement, source-order parity, keyboard activation and RTL. GitHub Actions workflow: `.github/workflows/destination-contents-qa.yml`.

For live verification, check EN/AR/FR/DE/IT/ES source pages and EN/ES Wayfatours pages with both 412px and 1440px, including desktop-to-mobile transition **within the same browser session**. The front-end may be bundled by LiteSpeed, so confirm the effective rendered widget, not just the standalone JS URL.

## Deployment notes

The four source files live under `wp-content/mu-plugins` and `wp-content/mu-plugins/assets`. Elementor archive templates must contain the native widget adjacent to the original TOC. Production migration should be scoped to the one new widget per translated template and protected by existing meta fingerprints, backups, validation, and post-deployment browser QA. Roll back templates and files together if verification fails.

Do not confuse a WordPress uncached page navigation timeout with a failure inside the destination TOC. Keep the two observations separate.


## WPML dynamic heading stability (2026-11-07)

Elementor Pro's Sticky helper clones complete content into `.elementor-sticky__spacer`. The native Elementor Table of Contents controls should exclude this selector. The frontend JS marks spacer content inert / aria-hidden and removes duplicate IDs while preserving its geometry. Do not remove these layout spacers.

WPML automatic synchronization can restore the English `archive-title` tag prefix/suffix in copied destination templates. Instead of relying on a one-time SQL rewrite, `mad4b-destination-locale-fallback.php` localizes only matching English defaults at render time. It handles native templates 48542 (AR), 56096 (IT), 56110 (ES), the common archive template, and FR/DE fallback paths. Already translated or manually authored Elementor strings are left unchanged.

The separate patch `patches/mad4b-platform/registry-destination-presentation-ui.wpml-heading.patch` must be applied to MAD4B Platform's live registry module where that module exists. It translates two exact Elementor Dynamic Tag relationship headings in the Dynamic Tag's `render()` method. The runtime does **not** use a global `gettext` hook or recursively invoke `wpml_translate_single_string`. That earlier recursion exhausted PHP memory (1GB) and caused HTTP 500 responses.

The shared company-about fallback is active for locales with no linked WPML Elementor templates. It registers its source strings for discovery, but currently uses built-in per-language fallbacks when no editor-authored translation exists. WPML String Translation overrides are **not** wired into that fallback at runtime; avoid claiming otherwise.

CI validates dynamic title inheritance, custom translation preservation, sticky spacer isolation and nonrecursive localized company headings. Live QA must additionally validate front-end responsiveness and the precise destination archive category.
