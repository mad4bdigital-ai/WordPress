# ETG WooCommerce Lab Content Source Repair, 2026-10-28
Local-only database: etg_woocommerce_lab.

## Data repaired
- 85 Tour Rates expired before 28 October 2026 now have stop_sale=1. Readback: 90 of 90 expired published Rates blocked. Prices, currencies, dates, statuses unchanged.
- 32 Tour origin records were re-derived from verified WPML canonical destination terms, with Google address, Place ID and coordinates for Cairo/Giza.
- Seven old Africa origin values were cleared because their selected source terms are continent-level, not a pickup point. Source term selections remain intact.
- The existing local tour-origin save hook now refuses to recreate continent-derived addresses.

## Content readiness ledger
- 1092 published sources scanned. 781 open and 13 resolved historical issues, 45 high and 736 medium.
- 60 missing origins; 13 broad origin terms; 30 WPML-unregistered tours; 51 missing location assignments.
- 584 property records without configured gallery; 19 reused identical galleries.
- 22 expired canonical Rate records need future commercial renewal; one Rate needs a valid end date; one Form submission follow-up issue.
- Identical hotel galleries and untracked WPML translations were NOT silently changed or populated with guessed data.
- Three pre-existing Fluent Boards tasks remained unchanged. No new tasks or outbound notifications.

## Rollback and safety
- Rate preflight and selected IDs: ../backups/expired-before.json
- Full origin before/after values: ../backups/origin-before.json
- Invalid continent meta before deletion: ../backups/continent-before.json
- Original local MU file: ../backups/tour-origin-sync.original.php
- Original module backups and readiness options: ../backups/
- Reproducible MU patch: tour-origin-source-granularity.patch (LOCAL ONLY).
- These exact ETG Lab repair scripts are not appropriate for tenant-wide deployment.
- Production and public tours.dev.mad4b.com staging databases were not modified.
