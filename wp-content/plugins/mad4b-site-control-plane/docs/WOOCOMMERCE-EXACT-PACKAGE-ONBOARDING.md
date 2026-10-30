# WooCommerce 11.2.1 exact-package candidate — VPS Woo LAB

**Status: source-verified candidate; requires independent GitHub review and governed Staging authorization. No install, replacement, activation, or Production mutation is authorized by this document.**

The original upstream archive was downloaded on 2026-10-28 from `https://downloads.wordpress.org/plugin/woocommerce.11.2.1.zip` to a restricted QA directory outside the WordPress webroot on the VPS. The pinned archive is **18,060,641 bytes**, SHA-256 `5317434cb381b5fdc7c1dd6ee258324f08c390976aaf8d1199f2a0e9a3ab8586`, 6,409 ZIP entries, 57,503,744 uncompressed bytes. No path escape or symlink entry was observed. All **14** verified critical file SHA-256 values match the independently installed WooCommerce 11.2.1 files in the isolated `etg-woo-lab-php` runtime.

The evidence is recorded in `config/evidence/woocommerce-11.2.1-lab-package.json`. Its operator-only original is retained in `/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/qa/woocommerce-package-cert-20261028/`.

## Supported activation path
The current Control Plane's `MAD4B_SCP_Package_Route_Resolver` recognizes only providers in `config/certified-providers.json`; this change adds the WooCommerce entry with exact archive and critical file hashes. The selected source is **`certified_local_archive`** — not `wordpress_org`, which appears in the input schema but is not implemented as a package materialization source. The local archive is resolved only from the trusted server path under `get_temp_dir()/mad4b-certified-plugin-packages/woocommerce.zip` (or an explicit host-owned trusted path filter outside web roots). A caller cannot provide an arbitrary URL/path.

The Woo LAB uses `wp_get_environment_type() === 'local'`, and the package pipeline strictly requires a deployment-bound `staging` site profile for an install. Thus the Lab can test **provider discovery, archive integrity, plan denial and runtime parity**; it must not be assigned a fake Staging identity just to run installer mutations. Its installed WooCommerce already matches target 11.2.1.

### Remaining before target Staging
1. Independently review the exact GitHub candidate artifact and critical-file checksums. No CI test by itself is an authorization.
2. Publish/install the updated MAD4B Control Plane via its **separate governed selected-head/self-update route**, not by replacing the control plane through the third-party package installer.
3. Provision the *same exact certified archive* through an approved outside-webroot Staging artifact supply route; confirm SHA-256 and size at destination. Do not copy from one tenant's plugin directory.
4. Re-run `mad4b/plugin-package-plan` on enrolled Staging with `provider_id=woocommerce`, `source=certified_local_archive`, `activate_after_install=false`.
5. Require owner-approved exact plan digest, backup/rollback and readback before `mad4b/plugin-package-apply`. Keep checkout/product-write certification separate; no Production rollout.

Source registration does not grant write capability or make an installed plugin automatically safe for checkout.
