# MAD4B Shared Product Core — canonical source candidate

**Version:** `0.1.0-dev.20261028` · **Lifecycle:** source-only / Draft PR · **Origin:** ETG WooCommerce Local Lab on VPS. This is the first version-controlled home for the 134 previously local-only active files. The `*.before-*` recovery files and any WordPress database, customers, orders, media, vendor plugins, runtime secrets, `.env`, and vault contents are **not** included.

## Layout

- `mu-plugins/shared-product-core/`: pricing, scheduling, admin, presentation adapters, field contracts and regression tests.
- `mu-plugins/shared-product-core-loader.php`: the current Lab loader. **It fails closed unless `wp_get_environment_type() === 'local'` and `SHARED_PRODUCT_LAB_ONLY === true`.** Copying the files to a customer alone does not enable any engine.
- `mu-plugins/local-tour-rate-filter-provider-router.php`, `local-tour-rate-jsf-identity-v1.php`, `shared-extra-display-mode.php`: three current Lab-specific presentation consumers retained for exact source provenance. These are **not tenant-neutral deployment entrypoints**.
- `tests/offline-contract-smoke.php`: executable PHP 8.3/8.5 no-WordPress contract checks.
- `tests/verify-snapshot.py` and `source-files.sha256`: byte-exact frozen-source provenance.
- `release-manifest.json`: lifecycle and readiness flags.

## Quality gates

```sh
python3 tests/verify-snapshot.py
php tests/offline-contract-smoke.php
find mu-plugins -type f -name '*.php' -print0 | xargs -0 -n1 php -l
```

The WordPress-dependent tests within `mu-plugins/shared-product-core/tests/` additionally require a **real isolated tenant database** and the actual WooCommerce/JetEngine/WPML/Elementor plugin versions. They do not run in this offline CI job:

```sh
php wp-content/mu-plugins/shared-product-core/tests/field-contract-release-gate.php
php wp-content/mu-plugins/shared-product-core/tests/primary-tour-query-context-regression-cli.php
```

## Release / activation policy

**Not production-deployable as-is.** This version captures the truth of the Local Lab; it is not a multi-company rollout artifact. Before promoting it, the release owner must: (1) separate exact ETG-specific template/query IDs and Lab-only callbacks from tenant-neutral modules; (2) replace the Lab activation constant with a governed per-company Service Activation and explicit capabilities; (3) test staging with matching PHP extensions, WordPress Core and plugin versions; (4) execute an authorized browser Elementor editor save/readback and nested Repeater render; (5) run full data-shape certification with a backup and migration plan per tenant; (6) attach an exact deployment SHA, approval receipt, rollback instructions and postdeploy readback for each tenant. Source import CI is **not** an authorization to publish to Staging or Production.

The ETG Local Lab fixes for 49 composite Dynamic Tag bindings in 25 Elementor documents are **database migrations**, not plugin source code. Their evidence, backups and rollback plans remain on the VPS, and must never be automatically replayed on another company's database. Company Rates, Orders, PII and WordPress site configuration remain isolated.

## Compatibility evidence at source import

- 134/134 active source files matched frozen snapshot Git blob SHA; no historical `.before-*` source copied.
- 126 PHP files pass PHP 8.3.36 and PHP 8.5.12 syntax checks.
- `offline-contract-smoke.php` passes 12/12 on both versions.
- ETG Local Lab (WordPress 7.1.3, PHP 8.5.12): live Field Shape Gate passes; nested Repeater real-content fix verified separately.
- Existing ETG Coolify-derived Staging/PHP instances observed running PHP 8.3.36 with a different MU tree; **runtime parity not proven** and **no deployment was made**.

## Maintainership

All subsequent code changes must flow through GitHub PR review and CI and be released as a versioned build; do not treat live VPS file changes as an implicit product update. The first promoted release should establish an immutable manifest, provenance and per-tenant rollout controls in MAD4B Control Plane.

## 2026-10-28 recovery checkpoint

The original `adapters/tour-program-source-contract-v1.php` was recovered verbatim from the ETG WooCommerce Local Lab. Its SHA-256 is `2f3b25944115d01a27db9d59ee6ba0f7e0dab965ccfb6c881cf6b2dd816874ce` (182 lines). A complete static loader assembly is **not** a signed or tenant-authorized deployment; the package remains source-only.

## Post-import booking cart bugfix

The first live WordPress test exposed a normalized-cart-to-raw-quote mismatch: `shared_product_booking_v2_cart_draft()` succeeded but `shared_product_booking_v2_cart_quote_preview()` returned `booking_v2_unexpected_keys` for a valid configuration. The cart preview now passes the original already-validated request to the server-side quote validator while retaining normalized identity in the draft. `tests/booking-cart-regression.php` proves the separation. This is a reviewed patch to the imported original and is recorded with its new SHA-256 in `release-manifest.json`. No Woo Cart, Order, Payment, or inventory writes are enabled by this source change.
