# Form #6 canonical date and signed stay intent (2026-11-17)

- Reuses the native Fluent Forms input_date.settings.date_format as the first, authoritative display format; the canonical invariant is YYYY-MM-DD.
- Accepts provably equivalent, valid date notations including ISO, textual month names and localized digits. Rejects invalid dates and ambiguous numeric day/month order unless the original field explicitly specifies the order.
- Both the full Commerce source submission filter and the Presentation-only accommodation preference hook now call one shared read-only date normalizer.
- Accommodation preference is independent of Pickup service eligibility and requires the original HMAC-sealed RequestAdvancePayments source mount, date-scoped Pricing Source resolver, and native BookingExperience stay validator.
- Never invents included hotel charges, never sets checkout_ready, and does not change WooCommerce, payment gateways or existing submissions.
- Source staging: 19/19 date normalization vectors passed pre-deploy; 5/5 original Commerce insert-date format cases passed; 8/8 signed-stay preference dry-run cases passed.
- Wayfatours: 21/21 persistent date contract checks passed; 8/8 signed-stay preference dry-run cases passed; source-context and tour page both HTTP 200.
- Read-only smoke tests; no real Fluent submission or Woo order/payment event was generated.
- Source-only historical caveat: the staging Commerce quote owner is loaded from mad4b-commerce/legacy-mu-tree, while Wayfatours does not ship that legacy quote path. Both active installation topologies were respected.
- Persistent backups: /root/mad4b-backups/form6-canonical-date-20261117-staging* and /root/mad4b-backups/form6-canonical-date-20261117-wayfa.
