# MAD4B Commerce — owned runtime source

The active **0.1.1** plugin source was enrolled from ETG Staging into this branch. The 89 initial files were verified **89/89 exact Git blob matches** against the running Staging installation. The historical `legacy-mu-tree` mirror was deliberately excluded because the shared core is owned by `packages/mad4b-shared-product-core` and must remain independent and frozen.

## Source ownership
- `mad4b-commerce.php` loads the existing Fluent Form 6 projection once.
- `modules/commerce/tour-rates-dynamic-function.php` remains the JetEngine Rate-label function owner.
- `modules/compat/tour-rate-native-display-tags.php` remains the Elementor Rate-card owner.
- Four PHP includes previously loaded from `mu-plugins/assets` are now tracked in `modules/compat/assets/`; the centered-popover JavaScript is tracked in `assets/`.
- The source change reroutes only the four include paths and the popover URL; **it does not change calculations or hooks**. The original MU assets continue to exist on Staging for rollback until a controlled deploy.

## Deployment prerequisites
This source tree expects WordPress, JetEngine, WooCommerce, the source-authorized shared-product-core runtime mounted as `WPMU_PLUGIN_DIR/shared-product-core`, and `MAD4B_COMMERCE_RUNTIME_ENABLED` where applicable. It must be deployed as the **existing plugin**, never activated as an extra copy. Preserve the original loading order and do not auto-enable financial writes.

## Verification
Run `php tests/source-owner-parity.php`, PHP lint for all plugin PHP files, and `node --check assets/tour-rate-centered-popover.js`. Then on Staging, verify `get_included_files()` includes exactly one instance of the owner modules and their four assets, and test JetEngine/Elementor/Form 6/WPML before promoting.

Source-only commit. **No Production deployment or branch merge is authorized by this snapshot.**
