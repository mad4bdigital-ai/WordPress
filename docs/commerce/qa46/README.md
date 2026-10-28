# QA46 — Transaction-isolated commercial Quote operation and impact test

**Target:** ETG WooCommerce Local Lab on VPS, database `etg_woocommerce_lab`, October 28, 2026. Base: Draft PR #551 (QA45) → #550 (QA44) → #546 (QA43). No Production modification or real payment/booking. This is a **synthetic source test**: it is not supplier certification or company policy approval.

## Execution isolation

The existing PHP/Nginx Woo Lab containers were already **stopped normally by another operation** before QA46 began. We did **not** restart or change their state. We used a new, short-lived `wordpress:php8.5-fpm` CLI container on the **internal-only** `etg-woo-lab-private` Docker network; the existing WordPress filesystem was mounted read-only, with its optional external object-cache drop-in replaced in the one-off container by an in-process no-op PHP stub. No public HTTP listener or outbound internet egress, no host production files changed.

The test script gates strictly on the Lab database name and `WP_ENVIRONMENT_TYPE=local` + `SHARED_PRODUCT_LAB_ONLY`. It begins an SQL transaction, records baseline counts, injects **in-memory** (WordPress pre-option filters) an active company policy and clears the Lab-only inferred-mode pending flag **only in the test PHP process**, then installs six temporary mock source adapters that produce signed HMAC fixture receipts for supplier cost, tax, simulated availability hold, final quote, manager approval and traveler acceptance.

Those receipts **do not represent a real hold, charge, company authorization or traveler communication**. Neither the shared policy nor the original product/Rates are modified in the database. The script does not install/activate plugins or enable Woo checkout.

All actual Quote and approval persistence is executed through the **native QA45 PHP methods and REST endpoints within the uncommitted transaction**, then `ROLLBACK` is executed and the in-process cache is flushed. A shutdown fallback rolls back if the script exits abnormally. A separate one-off process independently re-reads Lab state after test completion.

Credential handling: a temporary owner-only environment file was constructed locally from the existing Lab container configuration, **never copied into GitHub or test evidence JSON**. The private environment file must be deleted when finished.

## Observed business operations

- Native final Quote issuance created one immutable Quote record, attached to selected Rate 60820 / Tour 60629.
- Test economics in integer cents: cost USD 600.00; tax USD 60.00; margin USD 240.00; fee USD 20.00; discount USD 20.00; **final Quote USD 900.00**.
- Issuing the same Quote again returned `idempotent_replay` without a duplicate record.
- A nonexistent manager was rejected. A signature-verified mock manager record and a separate mock traveler acceptance record were created for the **same Quote digest**.
- Acceptance changed the approval status, **not** Cart/Order/Payment/Supplier authorization.
- REST read/issue/approval/status behaved as intended. An anonymous issuance and a client-supplied price were denied. Tampering with a stored Quote total was detected by canonical hash verification.
- Checkout, supplier booking, inventory holds, actual Woo orders and email were not invoked.

## DB effect — measured in the same SQL transaction

| Entity | Before | Uncommitted during | After ROLLBACK |
|---|---:|---:|---:|
| QA45 Quote options | 0 | 1 | 0 |
| QA45 approval options | 0 | 2 | 0 |
| WooCommerce Orders | 1 | 1 | 1 |
| Commerce Requests | 9 | 9 | 9 |
| FluentBoards Tasks | 6 | 6 | 6 |
| Inventory Holds | 0 | 0 | 0 |
| Tour Bookings | 0 | 0 | 0 |

**QA46: 36/36 PASS**, followed by read-only independent check `policy_status=draft`, `financial_blockers=[inferred_mode_policy_pending_approval]`, no persisted QA45 Quote or approval options. Native QA45 regression 44/44, QA44 27/27, QA43 37/37: **144/144 total passing checks**.

## Corrected production-candidate source behavior

`CommercialFinalQuoteLifecycle::evaluate` previously demanded `shared_product_financial_readiness_v2.checkout_ready===true`, while this native function deliberately **always returns false** (checkout not certified). This made Final Quote issuance impossible even if independently verified proof existed. QA46 separates the approved native price-mode diagnostic (`status=diagnostics_passed_checkout_still_uncertified` and zero blockers) from checkout certification, while preserving **all independent** active company policy, exact tenant binding, valid signed provider cost/tax/availability/final Quote evidence, and site write authority gates. Checkout/Order/Payment/Supplier remain false.

## Remaining pre-Production obligations

Real supplier-backed HMAC/public-key verification and audit provenance; genuine hold and expiry, approved tax decision, source financial mode approval, company policy activation through governed authority, real traveler verified channel, and independent execution certification. No auto-merge, no Production deployment. Do not present QA46's mock approvals as consent from a real user.
