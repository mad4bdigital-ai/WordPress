# MAD4B FX resilience — Wo Vacation Staging

Source-backed fix for an Oct 31, 2026 defect: expiry of `currency-data/public-rates.json` previously caused the Elementor currency switcher to disappear. This module keeps USD selection visible when rates are unusable and disables other currency selections until conversion is safe.

## Runtime contract

- Target (certified during initial staging acceptance): `tours.dev.mad4b.com`, WordPress Staging, container `etg-commerce-staging-php`.
- WPML/WooCommerce configuration: USD base with enabled EUR/EGP/GBP.
- Existing shared pricing provider `pricing_public_fx_snapshot()` is the authoritative 48-hour fail-closed conversion gate. **Do not extend its stale-rate purchase window.**
- `pricing-fx-auto-refresh.php` validates 170-ish USD-based Frankfurter v2 quotes, checks required currency recency and >30% changes, writes snapshots atomically, and records `mad4b_fx_refresh_state` without autoload.
- Hourly WP-Cron plus six-hour host cron backstop; no page request performs an external FX call. The host cron applies only to the specifically named staging container.
- Error state never deletes or replaces the last accepted snapshot. If it passes 48 hours, only USD checkout remains available; the selector continues to render a degraded status.
- Existing pricing purchase/payment guard must remain enabled. A source package by itself **does not grant production rollout**.
- Frankfurter v2 data may be indicative rather than executable conversion rates. Rate/provider suitability requires commercial sign-off for production transactions.

## Staging validation & rollback

1. Deploy only `mu-plugins/pricing-header-switcher.php` and `mu-plugins/pricing-fx-auto-refresh.php` through the governed staging rollout (version/hash/readback), never by general blanket copying to companies.
2. Ensure `wp-content/mu-plugins/currency-data` is writable only by the WordPress service principal; keep the containing MU code directory protected.
3. Install the opt-in system cron from `ops/` only on the matching authorized staging VPS.
4. Run `php -l`, then `wp cron event run mad4b_fx_rates_refresh`, check `mad4b_fx_health()`, and inspect the live header. Simulate outage with an in-process `pre_http_request` error, verify hash preservation and degraded rendering, then restore a valid refresh.
5. Roll back source files from the pre-change bundle at `/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/fx-resilience-20261031/pre-change` and remove the dedicated cron if necessary. Do not restore stale currency data as a final operating state.

**Status:** Verified against staging on 2026-10-31; no production mutation, no multi-company rollout. Package source is a candidate for the governed deploy pipeline.
