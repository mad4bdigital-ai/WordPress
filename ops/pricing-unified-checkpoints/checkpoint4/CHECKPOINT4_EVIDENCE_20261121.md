# Checkpoint 4 — supplier costs, sailing cabins and Abela sleepers
21 November 2026. Source-backed technical checkpoint; **not commercial approval**.

## Source of prices
Read-only source: Update Qoutation Sheet, Google Sheets `1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c`, tabs `Hotels`, `Cruises`, `Costal` and `Sheet2`. Source Excel-style groups have `Summer`, `Winter 26/27`, `Peak` across single/double/triple and notes. Some cells are empty, dashes, "Per person" versus unspecified, or include currency overrides; don't infer equal occupancy/rate basis, daily inventory or exact tariff validity dates from season headings.

The source candidate manifest in `source-quotation-candidates-UNAPPROVED.json` includes 18 active Cairo Package-tier Properties, of which 10 have high-confidence source-name matches, 5 need identity review and 3 have no matching Hotel tab source row. Its 40 cruise quote rows are *candidates*, not matched native vessel/cabin/sailing programs. The original notes are retained, including optional supplements and mandatory gala items, but neither is silently added to base cost.

Price columns are **supplier quotation source**, not signed price authority. No bulk assignment to native Unit prices, no commercial supplier receipt creation, no FX derivation and no copy of other companies' source IDs.

## Train #86/#87 — native source correction
External timetable crosschecks:
- https://www.seat61.com/cairo-to-luxor-and-aswan-by-sleeper-train.htm
- https://egypttrains.com/trains/86?lang=en
- https://egypttrains.com/trains/87?lang=en
Official supplier booking front end: https://abelatrains.com/

Train 86: Bashtil/Upper Egypt 19:45 → Giza 20:15 → Asyut 01:05 → Luxor 06:10 → Aswan 09:25. Train 87: Aswan 17:15 → Luxor 20:35 → Asyut 01:15 → Giza 06:05 → Bashtil/Upper Egypt 06:25, next calendar day. Running days and inventory are not signed price or date-specific availability certificates.

Staging has canonical Bashtil station ID **68930** (plus a WPML language sibling 68931 with the same station code). Wayfatours has the same station identified by code `UPPER-EGYPT-BASHTEEL` at ID **68922**; source ID 68930 on Wayfatours is an unrelated attachment and MUST NEVER be copied. No new station record was needed.

On Wayfatours changed only native transportations 54552 and 54553: source/destination station to existing Bashtil, arrival times 09:25 / 06:25, Abela operator and native stops from 29 stale placeholders to 5 source-verified stops per train. Departure 19:45 / 17:15 retained; no shared train_line CPT, other trains or commercial prices changed. Stored historical preimage in VPS restricted report directory. Live source still disables their `trans_use_in_tour_rate_generation` flag.

## New receipt gate
`MAD4B_Package_Cost_Receipt_Gate_V1` is **read-only**. It rejects:
- missing/nonapproved receipts, wrong stage/tour/type/basis, absent supplier evidence or source hash
- Self cost estimated rather than source-documented actual
- unsupported currency, currency mismatch or absent certified FX authority
- service date not within a signed tariff window, including all nights
- adult/child/infant occupancy mismatch, wrong property/native Unit or cruise vessel/cabin/program/sailing date
- wrong sleeper train number or boarding station
- absent/stale/expired inventory proof
- missing independent scoped signed authority. Hook `mad4b/package-cost-receipt/verify-authority` defaults to **false**, and is intended for a real signed trust provider; never set true by default.

Read-only `MAD4B_Package_Supplier_Admission_V1::inspect()` now derives stage service dates using the original itinerary `source_day_start`, rejects unqualified receipt data and returns receipt blockers. Existing commercial `rate_generation_ready=false`, `checkout_ready=false` behavior stays unchanged. A hypothetical signed valid fixture in the WP-CLI test is **test-only**, not a real receipt.

## Live regression
Two sites: 11/11 receipt gate tests each, including unauthorized signature fail, positive signed mock, mismatched FX/date/occupancy, expired inventory, self estimate, missing source, wrong room/cabin and incorrect Ramses boarding. The live read-only checkpoint test passes on both sites.

Eight canonical Package tours, **28 stage records per company, 0 approved/source Cost Receipts**. 60623 sample request date 12 December 2026 creates 5 stage-specific service windows on 12, 13, 14–16, 17 and 18 December. **No tour rate, checkout or customer price was changed.**

## Remaining commercial dependencies (not fabricated)
1. Confirm exact hotel identity for 5 aliases and obtain missing quotations for 3 hotels, plus room type/Unit mapping, full tariff start/end dates, meal-plan inclusions and per-date occupancy/inventory.
2. Match cruise operator vessel and actual sailing program/direction/port (3/4/7-night differentiation), real cabin Unit, occupancy, availability and currency-specific cost.
3. Obtain Abela invoice/contract or supplier quote with exact travel dates, cabin class, nationality/occupancy and inventory for trains 86/87; public retail ticket prices are NOT signed supplier costs.
4. Original authorized signer must issue independent commercial approval receipts, including Self documented actual costs where applicable.
5. Revalidate FX, tax, margins and children/infant policy before enabling generation; all remain blocked here.

`Checkpoint 4` therefore has **technical admission and routing PASS**, **commercial Cost Receipts NOT YET READY**.
