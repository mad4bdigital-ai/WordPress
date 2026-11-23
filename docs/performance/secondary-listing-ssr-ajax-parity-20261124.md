# MAD4B secondary JetEngine listing parity — 24 Nov 2026

## Source of truth
Tours.dev's first-party `mad4b-secondary-listing-lazy.php` v1.0.16 is the common source. Wayfatours was running older v1.0.10+complete-pagination-guard with an incomplete safety rule: a layout-height certificate could allow a large conditional terms listing to load via AJAX even without proof the SSR and AJAX item order and content matched.

## Verified remediation
- Migrated the *complete source v1.0.16* to Wayfatours, preserving its bounded signed AJAX handling and complete pagination guard.
- Strict `mad4b_secondary_grid_lazy_ajax_parity_proven` checks require per-document/listing/query generation, exact language/market, layout/CSS source hashes, viewport-specific SSR/AJAX count, link and content equivalence, signed AJAX and CLS evidence. Missing or stale evidence => normal complete server rendering, not a partial spinner.
- No changes to stored Elementor templates, Query Builder definitions, rates, Fluent Form 6, actual booking or checkout.
- `is_user_logged_in()` guard remains **active**. Do not enable logged-in auto-lazy until authenticated browser acceptance and exact SSR/AJAX proof.

## Live readback and browser acceptance
- Old Wayfatours SHA256 `42605bf14abaaef6ade909607a41145ab2c8eadf2d7e5f5f814bfb7c25784f84`, backup `/opt/mad4b-backups/secondary-lazy-parity-20261124/wayfa/original-wayfa.php`.
- Current v1.0.16 SHA256 on both sites: `a14c09227888d9854593e16841e358ad9969a121d214a7d95e9793f01e49a547`.
- `php -l` passed on Wayfatours after the swap.
- Wayfatours tour #60623, mobile 390×844 and desktop 1366×900: Form 6 visible, `<select>` count 18, 1,249 DOM options after initialization, 18 grid nodes and 10 lazy grids, relevant nested 48366 grids retaining 5 items, 0 new uncaught JS exceptions. These figures matched before the swap in the same browser test.
- Wayfatours has four layout proof entries for listing 45938 (en/es × mobile/wide) but no market-specific SSR/AJAX parity receipts. Tours.dev has six layout entries with a `de-usd` receipt and different CSS epoch. Do not move certifications between sites.

## Origin and browser diagnosis remains open
A guarded HTTP-header + unknown-cookie tracer (no query parameters) sampled 816 SQL queries on Tours.dev and 945 on Wayfatours. Elementor single-template ~0.8–1.0s and mega menu ~0.17–0.27s in the instrumented runs. These timings are two requests, not averages or conclusive freezes. The tracer was removed. Further work: authenticated browser profiling, safe public destination template optimization, header option inflation and INP.

## Rollback
Verify original Wayfatours SHA256 above, then restore only that file; never replace source and company modules wholesale. Recheck php -l, public tour and booking. Keep PR Draft pending exact-head CI and authorized release review.
