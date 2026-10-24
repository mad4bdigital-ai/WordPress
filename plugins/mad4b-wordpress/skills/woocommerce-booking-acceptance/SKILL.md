---
name: woocommerce-booking-acceptance
description: Safely test read-only pricing, booking configurations, cart persistence, tamper rejection and purchase-blocked Lab behavior.
---

1. Confirm environment identity and purchase/payment blocks before and after each test. Never create a real order or enable checkout unless separately approved.
2. Select only eligible test rates and inspect package/hotel relations, travel quantities, dates, audience and occupancy. Query authoritative server quote.
3. Validate stable totals and native WooCommerce item data. Reject expired rates, mismatched package/property, manipulated totals and cart line quantity not equal to one. Update traveler count through booking options, not generic cart line quantity.
4. Run deterministic in-memory cart tests: calculate totals, update options, re-price, verify notice counts, serialize/restore configuration and recheck provenance. Provide WCML-required cart fields in synthetic fixtures.
5. For HTTP session acceptance use a localhost-only, temporary, narrowly gated test endpoint and cookie jar; verify save, retrieve, clear and no residue. Delete endpoint and cookies even if tests fail.
6. Distinguish successful restoration of configuration/session data from successful restoration of live cart line. WooCommerce legitimately drops nonpurchasable products when Lab purchase guard is active; never bypass the guard to manufacture PASS.
7. Report tested limits, exact failures, no checkout claims and retained isolation. Keep draft templates draft.
