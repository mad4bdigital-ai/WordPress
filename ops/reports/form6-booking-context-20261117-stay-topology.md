# Booking Context — stay topology reconciliation (2026-11-17)

Read-only findings from published Rate plans. No prices, supplier confirmations, or accommodation plans were altered.

| Site | Published Tour Rates | Rates with simple_or stay | Multi-property simple_or | Different / no shared destination taxonomy | Explicit included | Inquiry |
| --- | ---: | ---: | ---: | ---: | ---: | ---: |
| Wayfatours | 76 | 54 | 26 | 24 | 0 | 54 |
| Tours.dev | 196 | 186 | 120 | 104 | 0 | 186 |

**Classification rule:** hotels in different destinations are only *candidates* for multi-stage conversion. Destination terms cannot establish nights or simultaneous groups. No conversion without verified itinerary, stay nights and inventory.

**Example:** Wayfatours Rate 61152 for Tour 60657 lists Hilton Golf 6 Oct (Giza) and Sonesta ST Gorge Luxor (Luxor) under simple_or. This may represent two locations rather than substitutes. Preserve existing prices until supplier/itinerary evidence confirms topology.

**Policy:** included must come from verified Rate/supplier evidence. Absence of inclusion stays inquiry; no surcharge and no payment authority arise from relationship alone.

**Live acceptance:** the date-context endpoint returns source_hint_only_quote_and_payment_revalidated_by_server. Tests assert Direct fallback on out-of-window dates, selected Rate scope, Draft/Applied invalidation, and non-financial hotel preference retention.

**Pending data remediation:** 24 + 104 candidates require itinerary/overnight-stage reconciliation; do not automatically label them erroneous.
