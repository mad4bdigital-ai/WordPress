# MAD4B Unified Pricing Policy Registry v1 — 20 November 2026

## Authority and why
The old inspector showed zero Pricing Lab strategies while real native Package Level and Tour Type preview rules were stored elsewhere. A selected targeting dimension, a preview-enabled rule and a commercial Checkout price authority are not the same state.

This release is one **read-only projection** of source-specific registries, NOT a second rule ledger. The native JetEngine options, WPML taxonomy terms, component supplier sources, Booking Experience assignments and Pricing Lab Draft storage remain write owners. No source-native price pipeline was replaced or activated.

## Live integration

Deployed at Tours.dev Staging and Wayfatours Production:
- MU read adapter: wp-content/mu-plugins/mad4b-unified-pricing-policy-registry-v1.php
- Inspector extension: wp-content/mu-plugins/mad4b-pricing-rules-visibility-v1.php
- Single Pricing Controls UI: wp-content/mu-plugins/assets/mad4b-pricing-rules-visibility-v1.js

Pricing Controls -> Pricing policies now shows Unified policies across all source families, and General & availability -> Configure dimensions shows the same readback plus each dimension's native rules and its Pricing Lab drafts separately. One AJAX readback, company-scoped and nonce protected; no separate saved option or policy synchronization writes.

## Twelve source families

1. mad4b_dynamic_dimension_markup_v1: Tour Type markup preview.
2. etg-dynamic-pricing-controls_shared_package_pricing_rows: Package Levels.
3. etg-dynamic-pricing-controls_shared_mode_policy_rows: Tour Mode configuration.
4. etg-dynamic-pricing-controls_shared_price_source_mode_rows: Direct versus Rate source policy.
5. etg-dynamic-pricing-controls_shared_group_tier_pricing_rows: Group Tier configuration.
6. etg-dynamic-pricing-controls_shared_promotion_rows: Promotion policies.
7. etg-dynamic-pricing-controls_shared_payment_fee_rows: Payment Fee policies.
8. mad4b_jetengine_pricing_lab_presets_v1: unapproved Draft and staged strategies.
9. booking_experience_pricing_preset_rows_v1: native assignment source.
10. mad4b_company_component_pricing_defaults_v1: accommodation, transport and extra component source policies.
11. guide-languages taxonomy Term Meta plus mad4b_guide_daily_base_cost_v1: Guide supplier cost in original currency.
12. mad4b_transport_self_pricing_controls_v1: Transport self supplier source, dynamically read.

Each normalized record includes family, logical canonical rule ID, original source, targeting dimension, value, review state and explicit not-proven commercial authority. A record with a preview state never becomes a live Checkout rule.

## Wayfatours audited results

- 8 language-specific Tour Type markup rows consolidate to 4 logical preview rules: Packages +25%, Nile Cruises +20%, Day Tours +10%, Half-Day +10%.
- 6 language-specific Package Level rows consolidate to 3 logical preview rules: Silver x1.10, Gold x1.15, Diamond x1.20. Canonical Package term enabled gates are on.
- Pricing Lab has zero saved Strategies, and native preset assignments have zero rows. Thus a previous display of zero Lab Strategies did not mean zero native preview rules.
- Native Tour Mode policy: 4 configured entries; Native price-source mode policy: one configured entry.
- Five Guide Languages inherit internal EGP fallback; company component defaults for accommodation, transport, extras are configured, not Checkout-authorized.
- Company self transport was updated during this session independently to revision 3, role self_cost, EGP, updated 09:44:31 UTC. The registry reads current values rather than persisting the older reference_only result.
- Published Tour Rates remain zero; 106 Draft and two Auto-drafts remain unchanged.

Tours.dev has no equivalent stored Tour Type markup controller or Package Level rows at the time of audit, so it does not inherit Wayfatours preview rules. Both sites retain their own policy records.

## De-duplication and fail-closed behavior

WPML copies are grouped by canonical taxonomy term, policy scope and target only. All language rows must agree on percentage and enabled flags, or the canonical record is blocked_source_or_conflict. A Term Gate that disables Package pricing yields blocked_term_gate. No original WPML Term Meta or options are removed, copied, published or guessed.

Readback states include preview_eligible, configured_native, configured_component, configured_supplier_cost, inherited_supplier_cost, approved_supplier_cost, reference_only_no_cost_authority, blocked_term_gate, blocked_source_or_conflict, draft, staged, approval_pending and disabled.

The existing native preview order is documented: original Tour base -> Tour Type markup -> Package Level preview. Mode, price-source and supplier components are separate; final checkout eligibility remains a distinct Native provider decision.

## Tests and safeguards

- ops/unified-pricing-policy-registry/tests/verify-registry.php
- ops/unified-pricing-policy-registry/tests/verify-conflict-mock.php
- node ops/pricing-rule-visibility/tests/verify-ui.js
- node --check for the actual admin JavaScript.

Tests passed for both live sites, including WPML conflict blocking, guest privacy and exact source-state readback. Source and UI are read only. No Tour Rate or booking edits, no change to published prices, no Checkout activation. Runtime tests use WP CLI and deterministic DOM; no logged-in Edge browser functional screenshot was available.

## Next migration boundary

A later, independent release can consolidate the authoring actions through a transactional, source-aware Policy Write Gateway after validating every native provider write lock, source fingerprint, approval and pricing eligibility. Do not replace price calculators or treat draft data as cost or retail price as part of this readback phase.
