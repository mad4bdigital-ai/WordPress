# Woo Lab normal-feature portability — continuation, 2026-10-29

**Status:** 27 directly repaired MU PHP files on isolated Woo Lab; **not** a Production roll-out. Source review PR #608 remains Draft.

## Policy

For ordinary WordPress features, `local`, `staging`, `production`, hostname and database name are *not authorization*. Preserve source eligibility, explicit feature settings, user capabilities and verified original content. Reserve environment/isolation requirements for **true sandbox test behavior** (synthetic data, outbound suppression, test workers and destructive QA).

## Second and third batches completed on Woo Lab

**Batch 2, eight files:** `096-verified-tour-render-context.php`, `099-global-offer-product-eligibility.php`, `shared-product-legacy-dmc-bridge.php`, `108-season-calendar-intake.php`, `107-mad4b-seasonal-market-registry.php`, `112-seasonal-tour-product-source.php`, `114-market-context-woocommerce-bridge.php`, `109-seasonal-topbar-content.php`.

These cover signed original tour-context checking, opt-in global-offer eligibility (not price activation), read-only legacy relation inspection, admin-managed seasonal calendar links, approved market profile selection, genuine CPT WooCommerce listing sources, and text-only announcements. Missing-provider checks were added in the shortcode before use.

**Batch 3, nine files:** `097-service-channel-policy.php`, `103-global-offer-product-badge.php`, `110-seasonal-dynamic-topbar.php`, `113-seasonal-nested-listing-sources.php`, `registry-presentation-tour-destination-counts.php`, `registry-presentation-empty-card-lists.php`, `registry-presentation-uael-preinit-overflow.php`, `portable-term-featured-media.php`, `shared-extra-display-mode.php`.

The service-channel authority still reads JetEngine's `service-activation` options and fails closed when a service or channel is not approved. Global offers remain subject to campaign eligibility and published Product CPTs; seasonal sources respect visibility, language, approved content and native price authority. Extras are only displayed under original Tour eligibility settings. The term-media resolver only maps to an existing verified upload; it does not rewrite term metadata. Season-topbar rendering checks provider functions before calling them.

**Prior batch:** 10 read/display adapters; see `woo-lab-mu-portability-deployment-2026-10-29.md`.

## Verification

- SHA-256 manifests match **27/27 modified files** and **27/27 preserved originals** on VPS.
- PHP 8.5 lint **27/27 PASS** on `etg-woo-lab-php`.
- Bootstrap functional assertions **13/13 PASS**, covering approved/unknown service authority, dynamic seasonal content fallbacks, shortcodes, front-end/JetEngine hooks, term-media handler and sandbox mail guard.
- WordPress bootstrap with signed tour context/CRM unaffected. Count readback after the test: Fluent Forms submissions 28, FluentCRM subscribers 2, Request batches 9, WooCommerce HPOS orders 1, FluentBoards tasks 6. No synthetic writes or real emails were performed in this pass.
- Woo Lab internal HTTP `/wp-json/` **200**. Rate-dates route is live but returns application `unsupported_product` for test Rate 67082 (not a missing route); no successful provider-rate calculation claimed.
- **Sandbox** `000-sandbox-guard.php` retains `pre_wp_mail` and `pre_http_request` blocks. This does not certify that production mail or provider connection works.

## Remaining gates (not blanket-removable)

A repeated read-only MU scan found **192 PHP files** still containing `wp_get_environment_type` and/or `SHARED_PRODUCT_LAB_ONLY`. A filename/header heuristic separates 3 explicit sandbox candidates, 20 QA/diagnostics/test candidates, 46 lab-named modules requiring semantics review and 123 files that are functional or mixed. This is a **triage aid**, not a safe allowlist. Nested includes contain commercial pricing/payment/booking logic that need provider-contract regression and owner approval.

Examples intentionally unchanged: `000-sandbox-guard.php`, QA user/magic-link access, data/isolation gates, `pricing-acceptance-guards.php`, `global-offer-checkout.php`, `availability-api.php` with historical `etg-lab/v1` route, mutable `shared-product-partner-admin.php`, and the legacy destination redirect/router. Do not remove one guard from a financial or auth path without verifying its independent authority.

`Commerce\Composition\InquiryQualification` is a separate regular-function correction in this PR, with no environment gate. The unregistered Master Admin MCP `MasterUserSwitch.php` draft is not installed and does not grant impersonation rights.

## Source and rollback

VPS evidence path `/opt/commerce-components/evidence/etg-normal-feature-gates-20261029/`:

- `before/`: exact original byte copies of all 27 MU PHP files.
- `manifest.json`: original and new SHA-256 per file.
- `reviewed-mu-portability.patch`: original/current unified diff for 27 files.

GitHub review artifact: `docs/commerce/inquiry-phase/woo-lab-mu-feature-environment-portability.patch`. These MU files are not yet tracked as canonical editable source in this WordPress repo; the PR adds a precise patch **rather than claiming that its merge will automatically reinstall them**. Source ownership/packaging must be reconciled before Production release.

No Production mutation. Do not merge this stacked Draft PR until CI is green and its parent PRs are certified.
