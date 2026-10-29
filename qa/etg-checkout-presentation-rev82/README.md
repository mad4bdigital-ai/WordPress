# Checkout QA REV82: Presentation Context and fractional age repair

**Scope:** Only ETG isolated Checkout QA (`etg_ops_checkout_qa`) MU plugins and
Age Occupancy plugin. Original snapshots in `original/`; sources in `patch/`.

- Explicit `channel` in Unified Display Context: `standalone`,
  `tour_component`, `destination_discovery`. Missing channel preserves
  standalone behavior. Tour components require a real published Tour.
- Included in service-channel cache fingerprint and REST schema. No
  implicit service activation, price or booking authorization.
- Presentation age validation now delegates to native Age Fluent Forms
  normalization, with two decimal digits supported, and integer receipts
  preserved for legacy submissions. JS supports step `0.01`.
- QA acceptance: `rev82-contract.php` 12/12; existing Fluent Forms 20/20;
  existing Unified Display and REV42 Context suites passed; REV65 native
  pricing, REST and cross-product linkage suites passed.
- The preexisting Age Accommodation suite initially scored 3/17 because it invoked a `tour_only` property rate as `standalone`. The test fixture was corrected to request native `tour_component` preview, while asserting the standalone dispatcher still denies sales. The corrected suite passes 17/17 without relaxing service policy.
- Main Lab (`etg_woocommerce_lab`) must retain its own MU loader and is
  not authorized for a direct REV65 bootstrap copy.
