# Source reconciliation: native JetEngine AJAX/SSR parity guard

Scope: Tours.dev live source exact readback and Wayfatours current owner
snapshot. No site installs or activation by this source-only change.

The Tours.dev runtime contains Shared Secondary Listing Lazy Load 1.0.12
at SHA-256 6eb39cfb618a4583eb3ab5796a714de1f54a055d9b4b4b226c51cdf4c37bb3a4.
It is preserved in the canonical source at
wp-content/mu-plugins/mad4b-secondary-listing-lazy.php.

The current Wayfatours runtime is an older 1.0.10 SHA-256
ed5905b2489de38839a51a181d288c9a6cb01cf602b5d52d93a2d14ffb329671.
The exact file is isolated in a company overlay and must not be silently
overwritten by a shared MU-folder reconciliation. The audited source
relationship is encoded in secondary-native-lazy-parity-manifest-20261116.json.

On the Source site's JetEngine secondary listing, native conditional
automatic lazy load had demonstrably failed SSR vs Ajax semantic parity;
a separate listing matched items but caused excessive CLS. Tests under
ops/secondary-native-lazy-parity-20261116 now require site, WPML locale,
company WCML market, signed native Ajax response, exact count/order/content
SHA equality, Elementor source data, generated CSS SHA, kit breakpoint,
query generation, two-band viewport coverage, and measured CLS <= 0.10.
Without full current evidence the original full SSR remains rather than a
placeholder. Existing layout-only proofs are not upgraded to new authority.

Hermetic test 20/20 under PHP 8.5 passes. The QA verdict remains HOLD and
does not authorize a performance rollout or any change to production.
This is source parity and a non-regression check, not a signed Original313
release, a new grant, or a GitHub Actions exact-HEAD attestation.
