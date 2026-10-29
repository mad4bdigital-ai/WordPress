# Direct Woo Lab repair — non-sandbox environment gates (10 MU adapters)

Date: **2026-10-29**. Target: ETG Woo Lab Docker `etg-woo-lab-php` / `etg-woo-lab-nginx`; source directory `wp-content/mu-plugins`. No Production or ETG Staging mutation.

## Approved functional change

Remove `local` / `SHARED_PRODUCT_LAB_ONLY` preconditions from **ordinary site capabilities**. Do not use the WordPress environment label as a permission to show a product date, render a destination, produce a currency selector, or apply a11y/JetEngine styling. Keep actual feature/business-policy checks, available classes/files, capability restrictions and supplier/payment validation in place.

The following **ten** installed Woo Lab MU adapter files were modified directly, with their original bytes separately preserved:

1. `000-shared-presentation-media-v1.php`: read-only media script; retains file-readability, frontend and feed checks.
2. `company-accessibility-contract.php`: general WPML/ARIA and accessibility presentation.
3. `first-view-image-priority.php`: first-view image hints; retains request path and actual asset-readable checks.
4. `pricing-header-switcher.php`: shortcode; retains WooCommerce Multicurrency and FX snapshot prerequisites.
5. `registry-destination-activity.php`: source discovery; retains term activation, service-channel eligibility and publication checks.
6. `registry-destination-facets.php`: JetSmartFilters options; depends on the now-portable destination activity contract.
7. `registry-destination-presentation-ui.php`: read-only Elementor/JetEngine destination presentation; retains source class prerequisites.
8. `shared-listing-empty-ajax-guard.php`: JetSmartFilters empty-listing normalization; retains AJAX/provider/source checks.
9. `shared-product-core-rest.php`: native product rate dates API; retains service class check and existing validation.
10. `rate-dates-ui.php`: product dates UI; now checks adapter JavaScript file readability and actual product-page scope instead of a `local` label.

All ten files passed PHP lint in the live Woo Lab PHP 8.5 container. Runtime smoke: `GET /wp-json/` returned HTTP 200; native `shared-product/v1/rate-dates` was registered and responded with a valid `unsupported_product` 404 for an unconfigured sample product, not missing-route 404; destination taxonomy, source discovery hooks, currency shortcode and AJAX compatibility hooks loaded correctly. Existing Woo Lab sandbox `pre_wp_mail` and `pre_http_request` deny guards are unchanged.

## Traceability

- Patch tracked here: `docs/commerce/inquiry-phase/woo-lab-mu-feature-environment-portability.patch`.
- VPS rollback originals: `/opt/commerce-components/evidence/etg-normal-feature-gates-20261029/before/`.
- VPS patch and original/new SHA-256 inventory: `/opt/commerce-components/evidence/etg-normal-feature-gates-20261029/reviewed-mu-portability.patch` and `manifest.json`.
- These exact MU files are **not otherwise tracked** at `wp-content/mu-plugins/` in this repository; this PR intentionally adds a reviewable patch rather than silently pretending these files were already canonical GitHub sources. Integrate them with their owning module/packager before a downstream release.

## Remaining inventory and high-risk exclusions

The read-only audit found 219 Woo Lab MU PHP files with environment/sandbox identifiers. The prior filename/header heuristic counted 75 as lab/QA/sandbox candidates and 144 as requiring functional review; this is not proof that all 144 should change. Ten reviewed adapters have now been corrected. **Do not automatically strip remaining predicates.**

Explicitly **unchanged**:
- `000-sandbox-guard.php` (outbound mail/HTTP suppression).
- `availability-api.php` (a namespace explicitly labelled `etg-lab/v1`).
- `shared-product-territories.php` (lab-only declarative assignments).
- `pricing-acceptance-guards.php` (checkout/payment gates; needs separate currency and financial certification).
- `company-container-patterns.php` (header explicitly says feature not signed off).
- `destination-compatibility-runtime.php` (legacy permalink rewrites/retirement and template interception require cross-language compatibility acceptance).
- `shared-product-partner-admin.php` (admin writes and publication validation).

**MCP User Switching is not being represented as enabled by this patch.** Current `MasterUserSwitch.php` is a local draft and has no production-grade authenticated browser handoff or validated MCP scope; it remains unregistered. Do not create unrestricted impersonation from an MCP service account. WordPress User Switching still owns the signed browser session switch and return-to-user flow.

## Rollback

For each file, copy its exact counterpart from the VPS `before/` directory back to the installed Woo Lab MU directory, reload PHP workers if necessary, and repeat route/UI smoke checks. No data migration or customer emails are part of this change.

## Final status

Direct Woo Lab modification: **10/10 reviewed PHP files lint and essential route checks passed**. GitHub PR remains **Draft** pending source-package ownership alignment and additional behavior tests. Other normal features remain for per-file review; no blanket removal was attempted.
