# MAD4B Age & Occupancy Registry — source-only functional reconciliation

Date: 2026-11-11. Isolated full-WordPress Git branch. Not a live deployment.

## Root issue

The prior Git candidate's MAD4B Age & Occupancy Registry had five PHP/JS paths diverging from the Tours.dev live source. The candidate had integer-only age input, no decimal normalization, no generic age collection/use-case policy, no safe tour-form token context, and no canonical booking_evidence and verify_booking_evidence methods. The existing Booking Configuration v2 caller and WooCommerce cart evidence checker already referenced those missing methods. This was a concrete cross-module contract gap.

## Source changes

Recovered the live implementation of admin policy controls, age-aware Stay normalization, Fluent Forms lifecycle and JS collector verbatim. Recovered the root Registry functionality while keeping the prior MAD4B plugin name. Existing live-exact shared policy dependency and immutable Fluent Forms CSS were already present and were not copied a second time.

The root PHP has one additional reviewed source semantic fix: native Fluent Forms v1 receipts contain the two noncommercial metadata fields context_mode and classification_status, but live booking_evidence originally rejected those otherwise valid receipts as unknown keys. The candidate accepts only the exact form-generated pairs (tour + tour_policy_verified or collect_only + pending_product_selection). All other extra fields, mismatched labels, altered pricing_authorized flags, stale/mismatched form tokens and invalid Tour/Rate relationships remain rejected. A status label is not proof of commercial eligibility. Booking evidence remains not_certified for child-age pricing, and candidate quote/booking readiness gates remain unchanged.

These six differences (five initial source files, including one reviewed semantic overlay; two preexisting dependencies retained byte-exact) are recorded with base Git SHA and live/candidate SHA-256 in age-occupancy-live-semantic-reconcile-20261111.json.

## Acceptance

- Five new Age semantic provenance tests passed.
- A stub-driven PHP integration fixture exercised **30 explicit assertions**: decimal and integer parsing, malformed and excessive ages, generic/inquiry age range and technical count limit, signed Form #6 token and wrong-form rejection, verified Tour context, invalid tour-age band, exact native receipt to booking evidence roundtrip, forged classification, unexpected discount, artificial pricing_authorized, and tampered receipt re-verification.
- Full local Python suite passed 124 tests at the reconciliation checkpoint. Root and other modified PHP sources passed PHP lint, and Fluent age collector JS passed Node syntax checking.
- No live WordPress runtime acceptance, stored configuration update, supplier price calculation, booking confirmation, signed financial execution or site deployment was performed.

## Remaining gates

The remaining Company Portfolio Manager and Site Control Plane candidate/live source differences require semantic reconciliation preserving newer local features and delegated operator policies. Original318 v82 authorized publishing, Host Issue/Revoke, Original313 independent signed receipt and actual Wayfatours/source browser/Form #6 QA remain unclosed. Global release state stays HOLD.
