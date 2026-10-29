# Policy correction: environment labels are not feature permissions

**Date:** 2026-10-29. **Scope:** Woo Lab runtime source and reusable Commerce Composition; no Production mutation.

## Required policy

For ordinary WordPress behavior (request qualification, forms, CRM, email, User Switching/MCP, frontend views, public APIs), **do not require** `wp_get_environment_type() === 'local'`, `WP_ENVIRONMENT_TYPE`, `SHARED_PRODUCT_LAB_ONLY`, a particular database name, hostname or deployment label. Those are deployment properties, not user or provider authorization.

Authorize functions using WordPress roles and scoped capabilities, existing provider contracts, signed source evidence, feature settings and actual prerequisites. Keep normal function activation portable across local, staging and production. Explicit option flags still govern rollout, but they must not be a proxy for environment identity. The policy is portable and does not change other websites' data.

**Sandbox exception:** Sandbox-only behaviors (outbound suppression, synthetic fixtures, isolated test execution, QA-only routers and temporary debugging) may and should refuse to run outside a certified sandbox. The *sandbox boundary*, not the environment label alone, must be verified to prevent test actions or test data reaching customers.

## Applied change on Woo Lab

`Commerce\Composition\InquiryQualification::enabled()` previously required `wp_get_environment_type()==='local'` and `SHARED_PRODUCT_LAB_ONLY===true`. Those two gates were removed. The existing site-scoped `commerce_inquiry_qualification_enabled` flag now enables qualification only on exact persisted `true`, `1` or `'1'`, and preserves the normal source and operator authorization checks.

The tested state: explicit `'0'` => disabled, `'1'` => enabled, and unapproved `'yes'` => disabled. PHP 8.5 lint passed and 79/79 deployed composition files match the GitHub source manifest. The unregistered `MasterUserSwitch.php` draft is **not included** in the runtime manifest, plugin bootstrap, or live feature claims; it must be completed and certified independently before enabling a new switching ability.

## Wider MU-plugin audit (read-only, no mass edits)

Scanning Woo Lab's isolated `wp-content/mu-plugins` identified **219 PHP files** containing `wp_get_environment_type` or `SHARED_PRODUCT_LAB_ONLY`.

Filename/header heuristic only (not a security certification):
- **50** sandbox or local/QA-named files;
- **25** additional files with sandbox/local-lab indicators in the header;
- **144** files requiring functional classification.

These counts do **not** mean all 219 are inappropriate: some are genuinely sandbox-only, while some are ordinary site capabilities mistakenly gated to `local`. Examples of ordinary-looking candidates to review are `shared-product-core-rest.php`, `registry-service-public-access.php`, `company-accessibility-contract.php`, and `availability-api.php`. Each may have downstream dependencies and public API behavior; **do not blanket-delete the guards**.

Next step for those modules: inventory the source owner and consumers; distinguish a true sandbox-only side effect from normal product functionality; replace environment checks with actual provider/capability/config prerequisites; test each migrated feature on Woo Lab with route and form regression tests; then review GitHub and certify deployment. No direct Production changes.

## Specific MCP User Switching guidance

The WordPress User Switching plugin owns browser session switching and return-to-original-user cookies. MCP may return a bounded, read-only browser handoff for an authorized Master Admin, with explicit target scope, but **must not** impersonate a WordPress user or export auth cookies/nonces. Do not use a `local` or `site_url` check as an access-control surrogate. A real MCP delegation/acting-as mechanism, if required, needs its own authenticated scoped authority and audit rather than generic switching the control-plane service account.
