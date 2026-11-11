# Unified Date 0.3.5: preserve native post__in deny-all

Date: 2026-11-11. Git-only source reconciliation. Release remains HOLD.

## Root cause

Current Tours.dev Unified Date 0.3.5 cleaned existing post__in with absint() plus array_filter(). A prior JetEngine/JetSmartFilters Package, Season, Query 310, or JetSearch restriction that produced post__in=[0] therefore became an empty array. The date filter skipped the earlier restriction and could repopulate results, violating AND semantics.

## Candidate repair

All three read-only date lanes now use one bounded helper. Missing or empty prior post__in keeps native WordPress unrestricted semantics. Any nonempty prior post__in containing zero, a negative ID, or an invalid ID returns [0], even [0,101] and [101,0]. Nonmatching valid restrictions yield [0]; valid ID restrictions intersect with date-matching IDs. No Rate or Tour excluded by a prior native filter is reintroduced.

The reviewed candidate derives from exact live PHP 0.3.5 source. It retains the new 0.3.5 JS Draft-vs-Applied Date, Fluent reverse synchronization, rate invalidation, AJAX count readback and native datepicker quarantine. The active v0.3.5 JS keeps two earlier reviewed fixes: WPML links cannot resurrect a cleared date, and an empty Fluent date stays empty. Historical v0.3.2 JS retains both safeguards.

## Offline tests and evidence

- 45 PHP assertions: archive, JetSearch, rate query 310, deny-all sentinels, mixed/invalid prior IDs, overlaps/nonoverlaps, invalid date, no-date, currency-only AJAX and unrelated filters.
- 23 JavaScript cases on v0.3.5; 23 on v0.3.2.
- 164 Python regression tests at this checkpoint.
- Two imported Playwright browser test scripts had syntax checked; they have NOT run as real browser tests.
- Pinned manifest: 286 Commerce code files; 283 exact source copies plus three reviewed PHP/JS semantic overlays.
- A separate two-file patch under /opt/mad4b-company-provisioning/reports/unified-local-coverage-20261111/ passed git apply --check and produced exact candidate SHA256 on a TEMPORARY copy of the live source. No site file was written.

## Boundary

Not deployed to Tours.dev or Wayfatours. The independent authorized Original318/313 release path and real browser acceptance are still unproven; the unrelated full Commerce source inventory still has live divergence. No booking, payment, database, grant, or permission policy was changed.
