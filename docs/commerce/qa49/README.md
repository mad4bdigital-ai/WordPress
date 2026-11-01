# QA49 — Native Order Item Identity Resolver

Implemented on ETG Woo Lab on 2026-10-28. Draft dependency: PR #553 (QA48). No Production changes.

## Source and behavior

New `includes/NativeOrderItemIdentityResolver.php` replaces the active QA48 Tour-only hook, while leaving the old class available for compatibility. It discovers enabled product CPTs from the installed CPT Woo Integration settings. Exact source/parent contracts:

| CPT | Required published parent |
|---|---|
| tours-and-activities | none |
| tour-rates | related_tour_id → tours-and-activities |
| property-rates | prop_related_property_id → properties |
| transport-rates | source_post_id → transportations |

The resolver saves original CPT product ID and source/parent fingerprint in the native Woo Order Item. It reads back through `WC_Order_Factory` and rejects conflicting IDs, invalid relations and altered proof. `addProductLine()` returns a freshly loaded Order and Item, avoiding Woo's stale in-memory item after `add_product()`. `assertOrder()` gates Woo payment initiation and pre-payment-complete on valid item identity, but does not authorize any financial action.

Types enabled later without reviewed server-side contracts fail closed. Identity fingerprint is not a signed commercial Quote. Secondary Property–Unit ownership cannot be certified from this CPT's current source fields and remains a distinct provider/booking gate.

## VPS Lab evidence

QA49 38/38; QA48 60/60; QA46 36/36; QA45 44/44; QA44 27/27; QA43 37/37: **242/242 PASS**. All tests were run in internal-only Docker containers with SQL ROLLBACK. Five native QA49 orders and QA48's five orders plus refund returned to baseline. Independent MariaDB readback: existing Woo Orders 1, Order Items 1, Quotes 0, approval records 0. Canonical and installed runtime files 71/71 identical.

VPS evidence: `/opt/commerce-components/evidence/etg-native-order-identity-qa49-20261028/`. To roll back, restore `composition.before.php` to the active plugin and remove only `NativeOrderItemIdentityResolver.php`.

No real gateway/supplier, Production, automatic merge, checkout or supplier booking action. **Keep Draft**.