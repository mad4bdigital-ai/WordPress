# Tour 60623 — published weekly departure pattern evidence (23 Nov 2026)

**Tour ID and Name:** 60623 — Egypt Budget Tour: Cairo to Luxor Nile Cruise Discovery – 8 Days
**Type:** tours-and-activities / Package
**Sites:** Tours.dev (source: EN/ES/FR/DE/IT/AR), Wayfatours (EN/ES).

## Decision implemented on both live sites
- Tour departure weekdays: **Monday (WP weekday 1, WordPress term ID 230)** and **Wednesday (WP weekday 3, term ID 232)**.
- Tour Day 2: Cairo/Giza → Aswan overnight Abela sleeper train.
- Tour Day 3: Aswan cruise embarkation **Wednesday** for Monday Tour start, **Friday** for Wednesday Tour start.
- Tour Days 3–5: 3 overnight nights aboard Nile cruise Aswan → Luxor.
- Tour Day 6: Disembark Luxor and overnight sleeper train Luxor → Cairo.
- No Tuesday, Thursday, Friday, Saturday or Sunday **Tour starts** were marked.
- Operating dates are a **source-backed request pattern**, not verified inventory, allotments or confirmation from the user's supplier.

## External evidence used
- Sonesta Star Goddess own ship schedule: three-night Aswan–Luxor voyage sails on Wednesday:
  https://www.sonesta.com/nile-river-cruises/sonesta-star-goddess-nile-cruise-ship
- Sonesta St George 1 own ship schedule: three-night Aswan–Luxor voyage sails on Friday:
  https://www.sonesta.com/nile-river-cruises/sonesta-st-george-1-nile-cruise-ship
- JAZ Gloria own listing: three-night Aswan–Luxor on Friday, includes a Standard Cabin type:
  https://www.jazcruises.com/cruise-ships/58-jaz-gloria
- Independent published Abela 2026 sleeper timetable, trains 86 and 87 daily: train 86 from Giza 20:15 → Aswan 09:25 next day; train 87 from Luxor 20:35 → Giza 06:05 next day:
  https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm
- 2027 actual train timetables and day-specific seat availability must be checked with Abela directly; a timetable is not a booked sleeper berth. Friday Aswan cruise boarding can start around midday, leaving a relatively narrow margin after the scheduled train arrival; rail delays are possible.

## WordPress changes
- Used native schedule taxonomy schudle-days-of-weak (note existing spelling). Validated published terms: 230 Monday and 232 Wednesday. No new terms and no other Tour classes were changed.
- Attached those days to original Tour and all existing WPML translations. WPML propagated localized term references.
- Source translation IDs: EN 60623 / ES 60661 / FR 60637 / DE 60649 / IT 60677 / AR 64945.
- Wayfatours translation IDs: EN 60623 / ES 60661.
- Each Tour translation has postmeta _mad4b_operating_weekdays_external_evidence_v1 with public source URLs, evidence timestamp, no inventory and no supplier approval claims.
- Fixed existing source/Wayfa weekday parser in shared-product-core/scheduling/policy-settings.php: when WPML maps localized weekdays back to source English term IDs, read the canonical English slug in the source language, then restore the editor's locale in try/finally. Previously Spanish term lunes and Italian lunedi would not parse despite valid relationships.
- Changes to this PHP file are stored as a **portable source-backed 23-line patch** rather than copying potentially stale full-source files into Git.

## Live acceptance
- Source schedule decision eligible with weekdays [1,3] for **6/6** locales; Wayfatours **2/2**.
- Signed schedule bridge, direct-source mode: dates **2027-01-11 Mon, 2027-01-13 Wed, 2027-01-18 Mon, 2027-01-20 Wed** returned schedule eligible. **2027-01-15 Fri** returned weekday_not_operated / booking_schedule_not_eligible.
- Wayfatours direct verified quote reference for 2 adults in Double occupancy: USD 2580.00 on 2027-01-11 and 2027-01-13; 2027-01-15 remains blocked. These are price references, not final bookings or inventory. Source Tours.dev still disallows its payment router; no production payment policy was changed.
- On both sites the signed bridge reports inventory_checked=false; no actual cabin/rail allotment or operator contract has been attested.
- User's original quote_only mode remains unchanged. No submission, WooCommerce order, payment request or Stripe charge was generated in these tests.
- HTTP/TLS frontend unaffected. Any public date picker showing Monday/Wednesday is for **request**, not guaranteed availability.

## Outstanding supplier operations and cautions
1. Select an actual 3-night Aswan→Luxor cruise ship matching the requested budget/Gold/Silver tier; an operator's general Wednesday/Friday pattern alone does not assign a Wayfatours cabin or rate.
2. Confirm 2027 exact sailing, actual boarding cutoff, cabin category and availability; JAZ Standard or another verified ship may differ by date.
3. Confirm sleeper rail numbers, station, accommodation class, 2027 prices and berths for **Day 2 / Day 6**; preserve buffer for delays.
4. Hotel nights, supplier costs, child/occupancy policy and package margin still require separate verification. Do not treat the signed weekday schedule result as supplier inventory confirmation.

## Safe rollback
VPS evidence and preimages: /opt/mad4b-package-checkpoints/2026-11-23-tour-60623-departure-research-v1/
If a supplier changes its timetable, first compare current live terms and any intervening operator edits. Restore the preimage schedule taxonomy relationships and just this evidence meta for Tour 60623 and its known translations; restore the single PHP preimage from backups only after reconciling later edits. Do not change unrelated Tours, pricing, permissions, CSS or published itinerary.

