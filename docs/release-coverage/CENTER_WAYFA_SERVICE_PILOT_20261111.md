# Center and Wayfatours Service Activation reconciliation pilot

Date: 2026-11-11. Approved scope for this iteration: central staging at tours.dev.mad4b.com and enrolled Wayfatours at wayfatours.com. No action performed on other companies' sites.

## Confirmed causes

- Central Company Service Catalog represents planning mode (tour_only, revision 0), without applying a company activation grant. The UI previously had no live public observation alongside the plan.
- Wayfatours already received public entry-point protection and Unified Booking Fulfillment V2. Its last Service Activation schema discrepancy (30/31) came from an existing source-identical presentation adapter not loading.
- Four legacy Wayfatours service modules (003, 004, 005, 090) had fixed hostname and production-only guards instead of a tenant identity boundary.

## Applied

1. Central Company Service Catalog now provides Check live public routes for explicitly selected pilot companies. It checks the exact production origin and site UUID in the enrollment registry, using bounded guest HTTPS GETs without forwarded credentials. It reports Plan vs Observed. Guest HTTP observation is not WordPress admin readback, booking or payment authorization.
2. The center pilot option mad4b_service_public_observation_pilot_v1 contains only wayfatours. Other companies are marked deferred pending reconciliation / Full Clone, and no external probe is dispatched for them.
3. The existing shared-product-core native Tour presentation adapter is loaded on Wayfatours only after matching tenant company, HTTPS origin, environment and Site Profile UUID. The neutral loader is also present on central source but remains inactive there.
4. Wayfatours now registers all 31/31 Service Activation fields. A sample published tour resolves rates with checkout_ready false. No saved service option was changed.
5. Added a neutral tenant service identity boundary. Updated the Wayfatours four service presentation, Elementor visibility and destination activation overlays to reuse it instead of comparing against a fixed tenant domain. Git tracks the overlays under wp-content/mu-plugins/tenant-overlays/wayfatours; the corresponding PHP files are installed at Wayfatours MU root.

## Live verification

- Central admin screen renders exactly one enabled observation button, for Wayfatours. Two other registered companies display pending reconciliation notices.
- Central REST Company Service Catalog integration checks passing including administrator-only readback, exact Wayfatours binding, pilot scope, plan-versus-public comparison and no plan execution.
- Guest Wayfatours Route Tour Rates HTML (English and Spanish): 404. Guest Tour Rates REST: 404. Guest Properties REST: 404. Public Tours REST: 200.
- The Wayfatours service-policy HTTP validator passed 4/4 probes.
- Real mobile Chromium at 390px: English and Spanish Tour pages returned HTTP 200, no overflow, no JS exceptions, retained rate listing elements and Fluent Form 6, and used language-correct Request a quote / Solicitar presupuesto labels.
- Service modes are unchanged: Tours standalone_and_tour, Properties tour_only, Transportation tour_only, Visas disabled. Seven sampled Elementor/JetEngine/Rank Math/JetSmartFilters hook counts are unchanged.
- Service Activation storage digest unchanged: f95a833e5f07ccd65d810d74e5ac36c32c7067f921e484011202040439efeb86.
- Stripe and Early Payment Benefits remained OFF.
- The source provenance verifier confirms seven production PHP files across Git, central and Wayfatours, rejecting drift and false release or payment claims.

## Intentionally open

- Central catalog plan remains Not Deployed, pending a future independently authorized and verifiable company lifecycle operation. Public guest site observation is not a signed 313 receipt.
- Other companies remain out of scope until reconciliation or Full Clone.
- Only four service-specific legacy boundary files were reconciled. Remaining unrelated tenant feature guards require separate parity testing.
- WordPress admin output and assets were smoke-tested; no authenticated visual browser session was used. No customer mail, booking confirmation, Stripe capture, service-option mutation or signed Original 318/313 composite release occurred.
