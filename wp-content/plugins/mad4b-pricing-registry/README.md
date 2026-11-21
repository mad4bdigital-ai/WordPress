# MAD4B Pricing Source Registry

Read-only, additive provider discovery for Tours, Accommodation, Transportation, Market Adjustments, and Strategy Presets.

This plugin does not authorize quotes, change existing source arbitration, create WooCommerce orders, or mutate prices. Providers remain authoritative in their owning plugins. `mad4b/pricing_registry/definitions` permits separately registered providers. The REST GET `/wp-json/mad4b-pricing/v1/sources` requires `manage_woocommerce`.

Before enabling price resolution via registry, validate provider contracts, original Page Options priorities, fallback behavior, source evidence, parity across preview/cart/checkout, and safe failure behavior. Missing providers are reported as unavailable, not silently replaced.

## Source completeness checkpoint — 20 November 2026

The read-only Source Registry is the discovery/admission entrypoint.
Its separately owned transportation modules retain their own WordPress permissions,
original AJAX/REST handlers and native content owners. Importing them into this
source tree does not approve costs, publish Rates or activate Checkout.

The pricing-workspace-unified adapter mounts the SAME original Accommodation
and Transportation panels in JetEngine navigation without cloning form inputs.
For the production-source reconciliation and site-specific source variants,
see ops/pricing-unified-checkpoints/PRICING_SOURCE_RECONCILIATION_20261120.md.
