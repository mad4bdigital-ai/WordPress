# Seasonal pricing inheritance: detected gaps and disposition (2026-11-21)

All counts below are from read-only WP-CLI audits of the two enrolled sites. They
describe **active** Draft + Published Tour Rates, not all trashed historical records.

| Gap | Tours.dev | Wayfatours | Decision |
|---|---:|---:|---|
| Active Tour Rates | 219 | 46 | Inspect source-of-date integrity |
| Complete explicit date ranges | 217 | 45 | Preserved over Season |
| Explicit ISO-formatted ranges | 2 | 0 | Read-only ISO support added |
| No dates and no uniquely assigned Season | 2 | 1 | Fail closed; do not infer from Tour tags |
| Complete range crossing assigned Season | 4 | 4 | QA Drafts only, left unchanged and flagged |
| Published Property Rates with verified NET cost | 0 | 0 | Not an assignment-calendar problem |
| Draft Property Rates | 29 | 28 | Await signed/confirmed cost and Units |
| Package stages | 28 | 28 | Seasonal stage filter connected |
| Price-ready package/tier reviews | 0/24 | 0/24 | Remain blocked for supplier evidence |
| Explicit Property seasonal exclusions | 0 | 0 | Inherit is default; never invent unavailability |

The 4 anomalous QA Drafts are Rate IDs **67078, 67082, 67086, 67090**,
labelled Summer but with an explicit May 1–October 31, 2026 period.
The Summer Term Meta ends September 30, 2026. These are internal QA records,
not production catalogue rates, so they were not silently rewritten.
The full audit of each record is in the server checkpoint.

## Fixed

1. Native Package Stage Guard filters both detailed room candidates and
   **plan-only** OR alternatives against the stage, level, hotel, Season rule,
   and stage travel dates. Unrelated viable alternatives are not blocked.
2. Native Rate eligibility prefers a complete explicit custom range and
   otherwise inherits exactly one enabled \`season\` Term Meta window, inclusive.
   Partial custom ranges and ambiguous/missing Seasons fail closed.
3. Price presentation, audience pricing, optional booking add-ons and Rate
   Planner source reuse now call the same effective Rate date logic **in the
   live shared runtime**. The 35 sampled published Tours.dev Rate statuses
   were identical before/after these changes.
4. Authorized admin UI and REST endpoint manage explicit assignment overrides
   with revision guards; no default unavailability and no inventory writes.
5. Native Package Readiness shows how many alternate hotels were excluded
   by the selected season and date window.

## Remaining distinct work, not to be solved by fabricated data

- Specific supplier Peak, gala dinners, blackout dates and surcharge calendars
  come from original quote notes and need **explicit date normalization** before
  affecting commercial rates. Peak is not a global Season taxonomy term.
- \`Property Rate\` supplier cost validity is separately checked by \`valid_from\`
  and \`valid_to\` in the native Stage Guard. It must not be assumed valid
  merely because a hotel is eligible for Winter.
- Unit-level room capacity and inventory are date-dependent and are not
  represented by an \`eligible\` Property assignment.
- Cruise sailings, sleeper-train cabin stock and transport scheduling require
  component-specific calendars, not hotel Season flags.
- Direct date formatting remains in secondary source modules such as
  JetEngine Rate titles and some presentation previews. Those should consume
  the shared effective-window resolver only after their source contracts and
  release integration have been reconciled; do not hard-reset those modules.
- A completely date-less Rate in a recurring Season can apply in later
  calendar years. Supplier NET contracts still need a bound validity term or
  explicit reapproval before checkout; **published Rate alone is not supplier
  availability confirmation**.
- The live commerce core source is newer than the main package in Git. Its
  PHP file patches are pinned to before-image SHAs to avoid reverting newer
  locale policy, booking runtime modes or the separated optional-tour relation.
  Canonical package promotion remains a controlled integration checkpoint.

No generated Rate, Sale Price, WooCommerce order, payment, transport inventory,
supplier availability or public checkout was approved by these fixes.

