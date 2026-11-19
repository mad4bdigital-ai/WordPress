# MAD4B — one core contract, independent company execution policy

## Source ownership
- The `Shared Product Core` PHP sources are canonical under `wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core/` in GitHub. Runtime WordPress may load an original copy in `wp-content/mu-plugins/shared-product-core/`; match SHA256 before applying a source-owned update.
- `MAD4B_Company_Runtime_Capability_Contract_V1::snapshot()` is **read-only** and loaded by `mad4b-commerce.php` in either Commerce or Presentation Runtime. It inventories actual registered native providers, the per-site fulfillment option, and conditional intent eligibility; it does not grant a write.
- Site setting `etg-dynamic-pricing-controls_booking_fulfillment_mode` is the existing global `inquiry_only` / `request_to_book` / `instant_booking` policy, configured through the site's Dynamic Pricing Controls. Per-tour and per-Rate overrides are resolved by the existing native Fulfillment authority. Global policy merely enables a *candidate*, not a transaction.
- Only the native booking, WooCommerce, Stripe, financial ledger and Original313 release policies can authorize their respective actions. Presentation always remains read-only **by its own loader**, even if the site has WooCommerce installed; Commerce loading alone never certifies checkout readiness.

## Drift and safe parity
Run `python3 tools/verify-company-core-parity.py --canonical <repo>/wp-content/plugins/mad4b-commerce/legacy-mu-tree/shared-product-core --site tours.dev=<site>/wp-content/mu-plugins/shared-product-core --site wayfatours=<site>/wp-content/mu-plugins/shared-product-core --strict` as a read-only verification after deploying a core change.

The initial reconciliation covers the nine divergent PHP engines/adapters and ten absent read-only component contracts (19 files), sourced from GitHub-identical canonical copies. These bytes are shared, **not** their privileges. No rate, order, deposit, booking, refund, customer record, or provider setting is modified by the reconciliation.

A passing byte-parity scanner does not certify operational correctness, upstream dependencies, rates, quote/checkout readiness, or Original313 signature. Use runtime snapshots, PHP tests, anonymous frontend checks and per-company fulfillment reviews separately. Production rollback copies and SHA receipts belong in the private host report directory.

## 2026-11-17 source preservation
The source and Wayfatours already shared newer Form #6 adapters than the preceding Git head. Their three modified files and two additional source-context/date-normalization files were imported **from identical live readbacks** into the canonical Git tree before any tenant reconciliation. This avoids downgrading WPML Rate identity, stay-stage validation or the source-date comparator when syncing the core. Reconcile only code and contracts: do not copy site-specific options, rates, pricing records, databases or booking state.
