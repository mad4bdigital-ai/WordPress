# MAD4B 60623: Cruise and Abela 2027 operational candidates (2026-11-23)

Site scope: Tours.dev (EN ES FR DE IT AR) and Wayfatours (EN ES).
Tour: 60623 — Egypt Budget Tour: Cairo to Luxor Nile Cruise Discovery – 8 Days; type tours-and-activities. Tour package levels: Silver 2353 and Gold 2354. Original 3-night Aswan-Luxor Cruise Program 2348.

## Provisional date-aware assignment — do not sell as booked inventory

| Cairo Tour start | Aswan cruise embark | Tier intent | Vessel property ID/name | Cabin unit ID/name | Evidence |
| --- | --- | --- | --- | --- | --- |
| Monday | Wednesday | Silver | 78032 — M/S Blue Shadow I | 78050 — Standard Cabin | Operator confirms category; Wednesday pattern from specialist, exact 2027 dates unverified |
| Monday | Wednesday | Gold | 35927 — MS Concerto II Nile Cruise | 71765 — Double Cabin | Official Extension Group cabin category; agency 2026/27 Wednesday 3-night pattern |
| Wednesday | Friday | Silver | JAZ Gloria — **no local property ID** | Standard Cabin — **no local unit ID** | Official JAZ 3-night Friday and cabin category; catalog/contract missing |
| Wednesday | Friday | Gold | 35972 — M/S Sonesta St. George Nile Cruise | 71761 — Deluxe Cabin | Sonesta first-party 2027 Friday 3-night dated sailings; premium option subject to package price/margin |

All existing local Units listed above are Draft with unit_available=false. No source supplier contract, inventory, actual cabin assignment, total retail quote or Silver/Gold supplier tier classification was approved. The Gold St George candidate may be an upgrade not financially compatible with a budget tour. The native Tour cruise property remains 0 because the valid operator candidates differ by weekday; overriding it globally would be misleading. The separate stage matrix is for Operations only.

Published first-party 2027 sample sailing evidence: Sonesta Star Goddess Wednesday 2027-01-13 to Saturday 2027-01-16, a compatible dated calendar reference for Monday Tour start; Sonesta St George Friday 2027-01-15 to Monday 2027-01-18, candidate for Wednesday Tour start. Official web published sailing dates are not sellable inventory.

Public sources:
- https://staging.sonesta.com/sonesta-star-goddess-nile-cruise-ship/sail-dates
- https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/sail-dates
- https://www.sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship
- https://extensiongroup.com.eg/concerto-ii
- https://www.goldengatestravel.com/nile-cruises/concerto-ii-nile-cruise
- https://princess-cruise.net/blue-shadow-nile-cruise/blue-shadow-i/
- https://discoverytoursegypt.com/nile-cruises/blue-shadow-i-nile-cruise/
- https://www.jazcruises.com/cruise-ships/58-jaz-gloria

## 2027 rail operations, NOT real-time seat availability

54552 — Sleeping Train No.86 (Cairo – Aswan), transportation. 2026 timetable daily benchmark: Cairo Upper Egypt (Bashteel) dep 19:45; Giza dep 20:15; Aswan arrives 09:25 next day. Tour Day 2 outbound.

54553 — Sleeping Train No.87 (Aswan – Cairo), transportation. 2026 timetable daily benchmark: dep Aswan 17:15 (Train origin, NOT guest boarding); Luxor dep 20:35 (Tour Day 6 traveler boarding), Giza arrives 06:05 next day, Cairo Upper Egypt (Bashteel) 06:25.

Station IDs differ legitimately by site: source Tours.dev Bashteel 68930 (stations); Wayfatours Bashteel 68922 (stations). Existing train post relationships and station IDs preserved. Existing trans_available_status=1 is a service listing, not date-specific bed inventory. Both train posts remain trans_use_in_tour_rate_generation=0.

Sources: https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm ; https://egypttrains.com/trains/86?lang=en ; https://egypttrains.com/trains/87?lang=en ; Abela official booking https://abelatrains.com/

2027 exact Abela timetable and two-berth allocation could not be independently confirmed from the publicly accessible operator booking system during this session. Passenger nationality, category, actual travel date, adult/child count and fare must be checked for a real booking; use the 2026 times as a provisional planning reference only.

Tour start **Mon Jan 11 2027**: Train 86 Tue Jan 12; Aswan & cruise Wed Jan 13; disembark Luxor and Train 87 Sat Jan 16; arrive Cairo Sun Jan 17.
Tour start **Wed Jan 13 2027**: Train 86 Thu Jan 14; Aswan & cruise Fri Jan 15; disembark Luxor and Train 87 Mon Jan 18; arrive Cairo Tue Jan 19.

## Live writes and isolation

A guarded source-first enrollment writes two private Operations review meta receipts to each existing Tour 60623 WPML translation, plus a private train review receipt to Posts 54552 and 54553:
- Tour: _mad4b_60623_cruise_rail_candidates_2027_v1
- Tour and train: _mad4b_60623_train_2027_ops_reference_v1

Saved and readback verified: Tours.dev 14 records (6 translations times 2 plus 2 trains); Wayfatours 6 records (2 translations times 2 plus 2 trains). No Woo Order, Rate, supplier cost or payment created. Native Tour cruise selection, native transport assignments, program schedule weekdays, old supplier rates and existing Cabin Unit published/availability flags remain intact.

New MU plugin wp-content/mu-plugins/mad4b-tour60623-operations-board-v1.php provides a read-only WordPress Tour 60623 Operations meta box. It displays ID + Name + Type for ship and cabin candidates, weekday/tier relationship, train details and pending actions. Render checked on both sites.

Regression verified 6/6 source and 2/2 Wayfa locales. Source retains four legacy Tour Rates created 2026-05-05 (IDs 60850, 60840, 60860, 60870); Wayfa has zero Tour Rates for 60623. Nothing was created or modified in those native Rate collections.
On Wayfa, the original Form 6 Direct price snapshot remains USD 2580 for 2 adults double on 2027-01-11 and 2027-01-13; Tour start 2027-01-15 is rejected. No payment initiated.

## Remaining external authorizations and operational closure

1. Get operator confirmed named ship and cabin allotment, booking reference or written date-specific hold, including deck, meal basis and cancellation policy. Cabin category existence or a public sailing calendar is not inventory.
2. Obtain date-specific original currency supplier net costs for each selected Silver/Gold ship and 3 nights, plus occupancy/child policy; validate against overall 8-day package selling price and approved margin.
3. Check exact 2027 Train 86 + Train 87 timetable, accommodation and two berths each leg with Abela, with confirmed supplier quotation/reference.
4. Confirm sleeper 86 Aswan 09:25 arrival gives enough buffer before ship boarding cutoff; ship Luxor disembark gives time before train 87 Luxor 20:35 departure.
5. If Silver Friday JAZ Gloria is selected, create a separate verified native Property + Unit first; do not make up IDs.
6. Only after approvals should original commerce and rate admission gates be allowed to create a sellable package/deposit invoice.

VPS evidence: /opt/mad4b-package-checkpoints/2026-11-23-tour60623-supplier-proposal-v1. Rollback is removing only these two new Tour metadata keys, one Train metadata key and this new MU board, after checking for later edits. Other site content and pricing unaffected.

