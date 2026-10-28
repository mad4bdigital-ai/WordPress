# Commerce Suite — Rate Unit & Elementor Pro Compatibility

**Date:** 2026-10-28  
**Scope:** ETG WooCommerce Local Lab and isolated VPS Staging. No Production changes.

## Rate unit source and correction

- JetEngine `tour-rates.unit_type` glossary permits `per_person`, `per_cabin`, `per_room`, `table`.
- Local Lab read-only scan: 200 Rates, 10 missing `unit_type`; all 10 identified as QA fixtures. **No unmarked commercial Rates were patched.**
- Nine `cart-lab-20261026:*` Rates (IDs 67076, 67078, 67080, 67082, 67084, 67086, 67088, 67090, 67092) belong to Tour 66996. Its authoritative `tour_price_unit` is `per_person`, and the same value is accepted by the Rate glossary.
- Recovery snapshot created before mutation: `/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/qa/commerce-rate-unit-snapshot-20261028.json`, SHA-256 `621886d784f3e5ceab8a8fea19cdfa276b889a41a2979d5c73bcd319e7513741`.
- Explicitly authorized `tests/qa-rate-unit-reconcile.php` applied these nine values only on the **local** environment, verifying an exact candidate-list SHA-256 `5996417758271d49a75cfa0dad7d7eb6e5fd9091cf526c09f257c22d63106c42`, parent relationship, glossary, original empty field, and transactional readback.
- 9/9 Rate unit resolver readbacks returned valid `per_person`. No base, sale, tax, supplier cost, or customer prices were modified.
- Rate 66534, whose QA source marker is `persistent-local-v1:group_qa_rate`, has no authoritative Rate or parent unit. **Left unmodified; still requires content/source remediation.**
- Sample read-only booking previews now return prices for existing QA Rates 67076, 67080, 67084, 67088, respectively USD 2700, 3080, 3460, 3840 for the inspected traveler/date fixture. **These are QA-only supplier-unapproved values, not a sellable release or price authority.**

### Fix the producer, not only existing rows

The original VPS-only `qa/interactive-cart-lab-20261026/create-fixture.php` created QA Rates without `unit_type`. An exact, version-controlled correction is now in `packages/mad4b-shared-product-core/qa/interactive-cart-lab-20261026/create-fixture.php`:
- Derives unit from the Tour source and validates it against JetEngine's Rate glossary.
- Saves an explicit Rate `unit_type` for standard, partial, and Extra QA fixtures. Extra QA Tour unit source is explicitly initialized.
- Kept the original local script as `create-fixture.php.before-unit-source-20261028` beside the source; patched source SHA-256 `16e46115c415048a91895786242e47eda7795363eb3590bbd1760607aeb37144`.
- The generator was **not executed again**, preventing unrelated fixture/relationship/visibility mutations.

This correction is deliberately QA-specific. It does not add automatic Tour-to-Rate inheritance for commercial suppliers.

## Elementor / Elementor Pro read-only compatibility matrix

Contract: `tests/elementor-pro-readonly-compat.php`. All three fixtures use Elementor Core `4.3.5` and existing published Elementor templates 66565 and 66491.

| VPS runtime | Elementor Pro | Data read | Server render | PHP warnings |
|---|---|---|---|---|
| `etg-ops-checkout-php` | 4.2.3 | 2/2 passed | 2/2 passed | none |
| `etg-woo-lab-php` | 4.3.2 | 2/2 passed | 2/2 passed | none |
| `etg-commerce-staging-php` | 4.3.2 | 2/2 passed | 2/2 passed | none |

The probe loads published Elementor documents, reads element arrays, and renders their front-end content. It performs no editor-save, write, or customer transaction. The isolated Commerce Staging has matching Pro 4.3.2 already; **this does not mean the connected `staging.egypttourgates.com` was updated**.

The connected ETG Staging site still reports Elementor Pro `4.2.3`. The governed read-only update preflight resolved candidate 4.3.2 but returned `mad4b_remote_plugin_update_download_failed`: no verified licensed package bytes or SHA. This is an **update-source blocker**, not evidence that 4.2.3 fails the two tested templates. Do not copy a Pro package between tenants or bypass licensing and the Plugin Lifecycle Control Plane.

**Still required for external Staging release:** a verified licensed update artifact *if exact Pro version parity is mandatory*, authenticated Elementor Editor open/save/readback with Dynamic Tags and nested Repeater templates, and approved rollback drill. This evidence does **not** certify the interactive editor or WooCommerce checkout.

## Boundaries

All changes are confined to the Local Lab QA fixtures/QA generator and version-controlled source. No Production deployment, customer orders, WooCommerce checkout, licensed Elementor upgrade, or unapproved commercial Rate repair occurred.
