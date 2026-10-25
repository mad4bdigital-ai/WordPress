# MAD4B Pricing Source Registry

Read-only, additive provider discovery for Tours, Accommodation, Transportation, Market Adjustments, and Strategy Presets.

This plugin does not authorize quotes, change existing source arbitration, create WooCommerce orders, or mutate prices. Providers remain authoritative in their owning plugins. `mad4b/pricing_registry/definitions` permits separately registered providers. The REST GET `/wp-json/mad4b-pricing/v1/sources` requires `manage_woocommerce`.

Before enabling price resolution via registry, validate provider contracts, original Page Options priorities, fallback behavior, source evidence, parity across preview/cart/checkout, and safe failure behavior. Missing providers are reported as unavailable, not silently replaced.
