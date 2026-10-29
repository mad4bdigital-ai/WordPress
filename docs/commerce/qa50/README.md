# QA50 — Source certification, financial simulation & external-charge safety

**Site:** ETG WooCommerce Lab on isolated Hostinger VPS, 2026-10-28. Draft change stacked on QA49 PR #554. No Production mutation, real supplier reservation, real payment charge or policy activation.

## Newly closed: Property → Unit → Rate ownership gap

Previously the CPT Property Rate `67074` pointed to Property `67063` and Unit `67070`, but the Unit's post meta had no reverse owner ID. The installed JetEngine relation registry is the source of truth.

`PropertyUnitSourceCertification` dynamically discovers the one active JetEngine relation with parent `posts::properties` and child `posts::units` (currently relation ID `223`). It requires the Rate's `prop_related_unit_id` to reference a published Unit and exactly one matching row in the JetEngine native relationship table. Missing, duplicate, reparented, unpublished or ambiguous ownership is rejected. No relation ID or site domain is hardcoded into the shared implementation.

`NativeOrderItemIdentityResolver` incorporates the certified Unit, relation ID and relation-row ID into its existing SHA-256 source binding and persists independent Unit/relationship evidence on each Property Rate Order Item. Source changes are caught at order-level verification and Woo pre-payment gating. **Proof of ownership is not proof of room inventory or sale availability**; that still needs a separately certified supplier/property availability provider.

## Financial pre-charge fail-closed gate

`CommercialExternalChargeGate` is a new central **read-only** certification boundary for Orders explicitly marked with `_commerce_composition_execution_gate=required` (unrelated Woo Orders are not modified). It checks native Order Item identities, active tenant Company policy, the immutable Final Quote digest and independent manager/traveler approval status. Since no verified external payment execution adapter is installed, it **always denies** live external-charge authorization even if mock receipts look valid. It additionally makes Woo `needs_payment()` false and blocks `payment_complete()` for those managed Orders. Payment connectors must invoke `authorizeBeforeExternalCharge()` **before** calling remote provider APIs. The Woo state hook alone cannot undo money captured outside WooCommerce.

Company Commerce Policy remains Draft. No gateway credential, merchant charge, supplier booking, stock mutation, real manager approval or traveler acceptance was created.

## Lab tests

A one-off, CLI-only test in `/opt/commerce-components/evidence/etg-commerce-certification-simulation-qa50-20261028/test-qa50.php` runs native Woo HPOS and JetEngine mutations inside SQL `START TRANSACTION` and unconditional `ROLLBACK`, with external cache and outbound network disabled. Scenarios:

- Real Property 67063 / Unit 67070 / Property Rate 67074 ownership: live JetEngine relation, dynamic relation discovery, native Woo Item/Order identity and persisted Unit owner proof.
- Delete, duplicate and reparent native JetEngine relation; change Rate-selected Unit; forge persisted Item Unit metadata; verify all conflicts fail closed before payment; restore source within the uncommitted transaction.
- Synthetic HMAC-bound cost, tax, availability, final quote, manager approval, traveler acceptance receipts; exact synthetic economics; reject expired/tampered/cross-quote receipts.
- In-memory-only mock payment authorize, supplier hold/confirm, capture, cancel, refund and idempotent retries, without invoking a real merchant API.
- Mock receipts do not grant commercial release: managed Woo pre-charge, Woo needs_payment and payment_complete all denied. The order is not paid.
- Roll back the temporary Woo Order, Item and native JetEngine relation changes; independent MariaDB read confirms site baseline.

**QA50: 47/47 PASS.** On the same installed Lab, historical QA49 **38/38**, QA48 **60/60**, QA46 **36/36**, QA45 **44/44**, QA44 **27/27**, QA43 **37/37**, total **289/289 checks PASS**, with independent DB baseline unchanged and exact 73-file runtime parity.

## Boundaries, next certification

- Unit ownership resolved, **not** live supplier availability, room inventory, allotment, taxes or true supplier pricing.
- Six HMAC receipts in QA50 are **local test fixtures**, not a substitute for source-specific signed authentic supplier, finance, user or payment receipts.
- Real gateway SDK/webhook and supplier reservation adapter remain absent; sandbox integrations and provider-specific reconciliation/refund tests remain required.
- Production rollout and switching Company policy to Active require a separate authorized release with review of current plugin consumers, policy capabilities and staging-to-production drift.

QA50 is a **safety-certified simulation**, not a Production-ready commercial release. Keep the PR Draft. Roll back only the changes to `composition.php`, `NativeOrderItemIdentityResolver.php`, plus the two new QA50 files, from the QA50 evidence backups.
