# MAD4B Commerce — source-only recovery for global Git reconciliation

Date: 11 Nov 2026.
Live source: Tours.dev Staging, wp-content/plugins/mad4b-commerce.
Candidate location: isolated local full-WordPress Git worktree.

## Root issue

The original full WordPress candidate did not contain the MAD4B Commerce runtime source. Its MU-visible Shared Product Core path is a symlink to mad4b-commerce/legacy-mu-tree/shared-product-core. A previous seven-component live-versus-Git audit could not detect this missing eighth component. This is a material gap for Rate Generator, shared rate actions, unified-date filters, Form #6 presentation and Commerce ownership.

## Source capture, not deployment

Imported 269 PHP/JavaScript/CSS files, exactly 238 PHP + 21 JavaScript + 10 CSS, using original paths and immutable SHA-256 + Git blob digests. 6 historical backup artifacts and the symlink legacy-mu-tree/currency-data were deliberately excluded; their exact filenames and original link target are recorded in the manifest. This source tree is therefore not an installable replica and must not be promoted without separately reconciling its data dependencies.

Documented in mad4b-commerce-live-source-snapshot-20261111.json, verified by tools/verify_live_commerce_source.py (read-only), including known exact source filenames, 269 file digests, explicit no-payments/no-activation claims, and unknown symlink rejection.

The live-date filter changed during source capture: unified-date-v1.js now uses a real WeakSet to track initialized DOM nodes because Elementor popup clones carry the dataset marker but not event listeners. Detached clone references are cleaned up. The companion unified-date-presentation-v1.php now enqueues 0.2.4 assets. Exact live source captured and tested.

## Acceptance

- 238 PHP sources passed php -l in isolated PHP 8.5 with network disabled.
- 21 JavaScript files passed node --check.
- 7 dedicated Commerce source/provenance tests passed; the report explicitly denies runtime certification or payment activation.
- Eight-component live code audit now includes MAD4B Commerce and allows only the exact reviewed non-code currency-data symlink on live WordPress; arbitrary symlinks remain blocked.
- The bounded live code audit reports zero missing PHP/JS/CSS paths and zero Commerce differences, but 42 Control Plane and 14 Portfolio Manager content differences remain. Some additional plugin/MU components remain outside this eight-component audit.

## Unresolved release gates

Commerce source capture is not a WordPress activation, a Rate Generator runtime QA pass, a WooCommerce checkout or Stripe test, an Original 305/313 host grant, or a valid Original318 code release. Do not copy these files into Wayfatours or other companies until pricing policies, persisted company overrides, supplier pricing and booking behavior have been reconciled and tested through the authorized publisher and independent readback.

**Global release state: HOLD.**
