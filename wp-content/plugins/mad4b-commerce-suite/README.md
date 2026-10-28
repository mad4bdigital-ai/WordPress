# MAD4B Commerce Suite — source-only enrollment shell

**Status: development candidate; no activation or tenant deployment authorized.**

This WordPress plugin is an operator-facing entrypoint for a multi-company product. It is **not** a bundled replacement for WooCommerce, JetEngine, Elementor, Fluent Forms, Site Control Plane, Pricing Source Registry or Age & Occupancy Registry. Each owning engine remains authoritative.

## Current surface

- WooCommerce → Commerce Suite when WooCommerce is active, or Settings → Commerce Suite when absent: inspect observed runtime indicators and prerequisites.
- Save **desired enrollment** per site using administrator-only manage_options capability, WordPress nonce and allowlisted module IDs.
- Authenticated read-only endpoint: GET /wp-json/mad4b-commerce/v1/status (requires manage_options).
- No runtime loading, activation, pricing resolution, orders, migrations, cross-tenant reads or automatic updates.
- A runtime detection does not certify a provider, payment/booking flow or release.

## Source ownership inventory

| Module | Source owner | Current treatment |
| --- | --- | --- |
| Dates, rate resolution, composite quote, package rules, booking cart, financial policies | packages/mad4b-shared-product-core/mu-plugins/shared-product-core | Source snapshot, lab-only loader |
| Pricing provider discovery | wp-content/plugins/mad4b-pricing-registry | Independent plugin, read-only registry |
| Age and occupancy | wp-content/plugins/mad4b-age-occupancy-registry | Independent policy owner |
| Release, site identities, approval and rollback | wp-content/plugins/mad4b-site-control-plane | Governed Control Plane, not absorbed |
| WooCommerce, JetEngine, Elementor, Fluent Forms | Vendor plugins | Versioned dependencies, never bundled |
| ETG-specific tour filter routing and display mode | package mu-plugins/local-*.php and shared-extra-display-mode.php | Lab consumers, must become tenant-neutral |
| Tour program source contract | Original recovered from Local Lab with SHA-256 provenance | Static loader source gap closed; tenant runtime certification still required |

The package manifest freezes 134 local-source files. Its loader includes a literal require for adapters/tour-program-source-contract-v1.php; the original file was recovered and tracked with its exact SHA-256. The production loader must not run until its dependencies, exact build and per-tenant compatibility have been certified. An audit cannot substitute for live environment parity or a versioned release.

## Run checks

php -l wp-content/plugins/mad4b-commerce-suite/mad4b-commerce-suite.php
php -l wp-content/plugins/mad4b-commerce-suite/includes/module-registry.php
php wp-content/plugins/mad4b-commerce-suite/tests/offline-contract.php
python3 packages/mad4b-shared-product-core/tests/assembly-gate.py
python3 packages/mad4b-shared-product-core/tests/assembly-gate.py --release   # must fail until the missing source is restored

## Next certification gates

1. Retrieve and checksum the local-only program source contract without changing the frozen provenance snapshot unexpectedly.
2. Establish single source ownership for all relevant CPT products and hospitality/transport booking providers; include any extra local-only packages.
3. Define a dependency-aware, per-company activation manifest with signed exact SHA and no generic WordPress activate calls.
4. Confirm WP/PHP/WooCommerce/JetEngine/Elementor/WPML versions and test editor save/readback, repeater queries, pricing preview, checkout and refunds on staging tenant fixtures.
5. Only release through the existing MAD4B Control Plane approvals and rollback/readback.

**Data boundary:** each company retains independent WordPress DB, orders, customer data, rates, forms, taxonomy mappings, templates and legal/payment settings.

## WooCommerce-absent staging behavior

A new company site without WooCommerce can access the diagnostics screen under WordPress **Settings**, but must not activate bookings or assume a checkout capability. The screen relocates under WooCommerce only after its class has been loaded. A desired-enrollment option cannot authorize activation; only the external governed release flow can do so. `tests/admin-fallback.php` asserts both menu paths and non-admin denial.
