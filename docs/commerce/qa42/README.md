# QA42 — First-party WooCommerce source onboarding and Commercial Execution Preflight

**Execution location:** ETG Woo Lab, 2026-10-28. Production was not changed.

This branch contains a **complete runnable PHP source snapshot** of the installed Commerce Composition plugin, including `composition.php`, all 60 PHP includes, the native queue entrypoint, README, CHANGELOG and a content-addressed source manifest. It supersedes QA38–QA41 patch-only handoffs *for code review purposes*. The snapshot was imported from the existing dirty VPS worktree without resetting or discarding other contributors' changes.

The GitHub source tree is not itself a production deployment. Source ownership, release tagging, integration tests with the tenant's WordPress/WooCommerce plugins, approved Staging deployment and rollback certification are still required.

## Commercial Execution Preflight

Admin-only:
`POST /wp-json/commerce-composition/v1/management/commercial-quote/preflight`

Input: `{"source_id":60820,"configuration":{ "schema_version":2, "tour_id":60629, "source":"rate", "rate_id":60820, "travel_date":"2026-11-10", "audience":"general", "occupancy":"double", "quantities":{"persons":2}, "booking_intent":"quote", "extras":[], "selections":[] }, "market":"", "channel":"web"}`

The response includes a canonical site-bound Policy Resolver digest, first-party pricing preview, intended Woo Cart line identity, cost/tax/stock completeness, independent manager/traveler gates and a unique preflight digest.

**Critical safety boundary:** A pricing preview is not an approved customer Quote or accepted Order. The Preflight only reads domain engines; it does not create cart items, WC Orders, payment captures, supplier bookings, customer consent records, or ledger entries. The result explicitly denies all these writes. Site Profile Origin Binding remains quarantined; even policy mutations continue to fail closed.

## Verified WP-CLI behavior

- **18/18** live Woo Lab preflight tests PASS: Tour-page / Rate-product canonical digest parity, independent Rate `60820` Woo identity, no duplicate charge, no monetary authority, invalid date and cross-service Rate denial, unauthorized REST denial, and unchanged orders/tasks/requests.
- Pricing rule catalog still reports supplier cost/margin, travelers age, availability, accommodation, tax/fee and manual approval gaps. No values are fabricated.
- A single Rate may be selected as a sale item `60820` with parent Tour context `60629`; its WC_Product `is_purchasable()` remains false. No real checkout was attempted.

## Outstanding completion gates

1. Site Profile origin/authority re-enrollment by the authorized site owner, not a direct bypass.
2. Full domain quote receipt with supplier cost, margin, taxes, inventory, availability holds and expiry; schema and provider attestation.
3. Independent manager commercial approval and first-party traveler acceptance **bound to an exact quote digest**, with expiry/revocation.
4. Woo Order Item and payment integration with idempotent, reversible source-linked states.
5. Supplier booking and operations with reliable provider confirmation/compensation; real provider capability and CRM/FluentBoards authority.
6. Staging replay and browser/Elementor/WPML test evidence. No Production rollout.

**GitHub CI:** `.github/workflows/commerce-composition-source-qa42.yml` validates source SHA256/Git blob hash manifest and PHP syntax. Live WP-CLI integration and provider tests remain separate. No auto-deploy.
