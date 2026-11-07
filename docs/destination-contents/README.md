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
