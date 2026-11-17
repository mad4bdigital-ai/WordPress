# Form #6 Booking Context — live acceptance (2026-11-17)

## Deployment

Deployed to both Tours.dev (source staging) and Wayfatours (production).
Original source pricing, WooCommerce orders, Stripe, tax and supplier prices were not mutated.
Five source-side files were installed on each site, with subsequent source owner and broker changes. Backups are stored outside the repository under
`/root/mad4b-backups/form6-booking-context-20261117-*`.

- A read-only date-scoped `tour-form-source-context` REST route delegates eligibility to the original pricing policy, Rate date coverage, locale, and status checks.
- Direct source availability is calculated against the current language Tour, not the fallback-language Rate owner.
- Form #6 uses Draft/Applied date distinction and refreshes source, price and accommodation after the original unified-date event.
- A changed date invalidates previous Rate-related hotel and room choices. Invalid Rate/source snapshots cannot become financial authorization.
- Pickup choices explicitly describe collection location. They do not represent package-accommodation selection.
- LiteSpeed's critical selection, projection, quote scripts bypass stale aggregated JavaScript bundles.
- Source and tenant keep their respective transport-state behavior, not a copy-pasted broker.

## Live read-only tests

| Test | Tours.dev | Wayfatours |
|---|---|---|
| 2026-12-10 | Rate #61060 eligible | Rate #60820 eligible |
| Choose linked accommodation | Property #36005 | Property #36117 |
| 2027-06-10 | Direct fallback | Direct fallback |
| Rate and stale stage removed | Passed Chromium | Passed Chromium |
| Return to 2026-12-10 | No stale hotel restored | No stale hotel restored |
| Direct preview authorization | Nonbinding only | Nonbinding only |
| Foreign Property under Rate | Rejected by stay validator | Rejected by stay validator |
| Explicitly invalid past date | 422 by contract | 422 by contract |

WPML: Wayfatours Tour #60655 uses localized Rates #60822/#60832 in the 2026-12-10 period; June 2027 Direct fallback is permitted. Day Tour #54877 resolves Direct without inheriting Rate accommodation.

The REST response is a read-only hint and always has `checkout_ready=false`, `order_created=false`; the existing financial source and Woo payment gates remain authoritative.

## Unresolved data decisions — not silently invented

All audited stay Rate plans are marked `inquiry` with no explicit evidence of included pricing (Wayfatours: 54/54; Tours.dev: 186/186).
24 Wayfatours and 104 Tours.dev multi-property `simple_or` Rates have no shared destination terms. The cross-destination cases are **candidates for manual itinerary/night-stage reconciliation**, not proven errors.
Do not automatically convert `simple_or` to `multi_stage`, assert `included`, impose surcharges, or authorize deposit collection without verified source documentation.

## Limits

Validated via source code checks, WP-CLI reads, REST read-only endpoints and browser interaction. This is NOT an end-to-end Fluent Forms production submission, payment, WooCommerce order or supplier confirmation acceptance. Those transactional operations were intentionally not executed.
