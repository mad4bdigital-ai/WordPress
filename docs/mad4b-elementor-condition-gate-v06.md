# MAD4B Elementor Display Conditions Tree Gate v0.6.0

Source of truth for the company-scoped safe display-conditions optimization.

- Elementor Pro 4.x: general elementor/frontend/before_render callback.
- Elementor Pro 3.x: four typed before_render hooks: section, column, widget, container.
- Ambiguous hybrid/incomplete hook topology: preserve all native callbacks unchanged.
- Replace only Elementor Pro's callback in its original WordPress WP_Hook slot. JetEngine, WPML and other callbacks retain order and behavior.
- Only raw Elementor nodes without conditional or dynamic settings are skipped. Unknown elements call the original Pro handler.
- Local company manifest is checked against home_url, environment, optional tenant code/origin/environment constants, and WPML language allowlist.
- Public GET only. Login, WooCommerce/cart/checkout/account, currency, market, geolocation, auth, query, non-locale cookie, AJAX, REST, cron and WP-CLI preserve native behavior.
- Active mode applies only to audited front pages. All other public routes remain native except explicit canary.
- WordPress option mad4b_elementor_condition_gate_v1 = 0 disables the optimization.
- Request header X-MAD4B-Condition-Gate: native-v1 forces native behavior per request.
- Source code includes no credentials. Policy JSON is generated per company.

## Company Management provisioning

Run enroll-company.py with the exact company WordPress root, HTTPS origin, company code, environment and verified WPML languages. The command defaults to read-only plan. Add --apply only when company onboarding has verified the profile. Fresh installs start as CANARY, never auto-enable on Production.

Example command (change to actual enrolled company):

    python3 enroll-company.py --site-root /opt/EXACT_COMPANY/site-copy --origin https://company.example --company example-company --environment staging --languages en,ar

The enroll action refuses hash conflicts, foreign-company policy, origin mismatch, symlinks and invalid IDs. It does not change database, Elementor Pro, Elementor templates, credentials or permissions. Company Management should invoke this after WPML setup and before performance QA. Promote to ACTIVE only after page-specific audit passes, with front-page-only scope.

## Live validated state on 2026-11-07

- Tours.dev Staging: EN/AR/FR/DE/ES/IT home audits mismatch=0, v0.6.0 deployed.
- Wayfatours Production: EN/ES home audits mismatch=0, typed Pro hooks, v0.6.0 deployed.
- These results show safe elimination of redundant Pro checks, not a statistically proven reduction in end-to-end cold TTFB.
- Do not overwrite unknown production installations without exact company identity and code reconciliation.

Tests: test-hook-topologies.php (general, typed, hybrid); php -l; per-company WPML/cookie/session tests and origin render readbacks. VPS checkpoint: /opt/mad4b-company-transport/elementor-condition-gate-v06-20261107/
