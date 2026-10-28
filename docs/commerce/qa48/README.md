# QA48 — Native Tour Rate WooCommerce Order Item identity repair

**Target:** ETG WooCommerce Lab on VPS (`etg_woocommerce_lab`); 2026-10-28. **Draft, Staging/Lab only. No Production deployment.**

## Verified root cause

CPT Woo Integration 2.2.11 registers `TinySolutions\\cptwooint\\Models\\CPTOrderItemProduct` as the order-item class for WooCommerce factory-created items. Its overridden `set_product_id()` supports enabled CPT post types such as `tour-rates`. However, WooCommerce 11.2.1 `WC_Abstract_Order::add_product()` directly uses `LegacyProxy::get_instance_of(WC_Order_Item_Product::class)` instead of the factory. Woo's base `WC_Order_Item_Product::set_product_id()` rejects an ID whose post type is not `product`; the resulting persisted line therefore had `product_id=0` despite the source `wc_get_product(60820)` returning the valid Rate.

## Source fix

- New `includes/NativeCptOrderItemIdentity.php`; wired at `plugins_loaded` priority 62 in the existing Commerce Composition plugin.
- Scope restricted to **`tour-rates` only**, with validated `related_tour_id` and `tours-and-activities` parent and confirmation that the installed CPT Woo Integration supports this type.
- Hooks existing native `woocommerce_order_add_product` compatibility action, independently loads the stored item with `WC_Order_Factory::get_order_item()`, verifies the CPT item subclass and order ownership, sets exact original Rate ID, saves, then reads back and verifies identity. Rejects unexpected product collisions or unavailable adapters instead of silently overwriting a different product.
- Does **not** set prices, approve company policies, issue Quotes, enable Checkout, capture payments, alter stock or create a supplier booking.
- The initiating `WC_Order` instance can hold a stale in-memory copy of the initially constructed item. Code that changes or saves that line after `add_product()` **must rehydrate** the Order via `wc_get_order($order_id)` and the item via `WC_Order_Factory::get_order_item($item_id)` before modifying totals/meta. The QA48 operational test follows this rule; future native Woo version changes require regression.

## Installed functional tests

1. Isolated direct probe with **real Rate 60820**: CPT item subclass resolved; initially missing product ID recovered to `60820`, reloaded from persistence correctly, original Woo Order and line count unchanged after SQL rollback.
2. Full WooCommerce HPOS transaction with **five real Orders**, all containing `product_id=60820`, using synthetic independently signed Quote/approvals and local-only mock gateway/supplier:
   - `processing`: mock payment authorise → mock supplier confirm → mock capture → Woo `payment_complete`.
   - `cancelled`: supplier rejected, mock authorization voided before capture.
   - `failed`: payment authorization declined, no supplier booking.
   - `on-hold`: ambiguous supplier outcome, no capture or duplicated booking.
   - `refunded`: synthetic supplier cancellation and mock gateway refund, generating one real Woo `shop_order_refund` HPOS record.
   - These are mock financial/provider results, not external sandbox credentials or actual paid bookings.
3. **QA48 60/60 PASS** on installed Woo Lab. Historical regression suites also pass: QA46 36/36, QA45 44/44, QA44 27/27, QA43 37/37 — **204/204 total checks**.
4. Within transaction: Woo HPOS records `1 → 7` (5 new shop orders + 1 Woo refund record) and after rollback `7 → 1`. Issued Quote options `0 → 1 → 0`; approval options `0 → 2 → 0`; Commerce Requests, FluentBoards tasks, inventory holds, bookings unchanged.
5. Independent MariaDB readback: **1 existing Woo Order, 1 existing Woo Order Item, 0 new QA45 Quote options, 0 approval options**. All **70/70** installed source files byte-match canonical VPS source. The running site's PHP and Nginx containers were already stopped by an unrelated operation and were not restarted; tests ran in one-off read-only PHP containers with internal-only DB network and SQL rollback.

### Evidence / rollback

VPS evidence: `/opt/commerce-components/evidence/etg-quote-woocommerce-sandbox-qa48-20261028/`. Saved pre-change `composition.before-native-item-fix.php`. To roll back on Lab, restore that boot file and remove **only** `NativeCptOrderItemIdentity.php`, then run the original native probe/QA48 regression. No third-party plugin files modified.

### Release constraints

No external payment gateway is available in the current Lab; all payment and supplier actions in QA48 were in-process mocks. The company Commerce Policy remains Draft; financial source approval and authentic provider receipts are not certified. Woo Checkout, order commercialization and Production supplier booking remain closed. Do not merge or deploy before explicit release review.
