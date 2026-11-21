# Consolidated Package source: tested code, true live costs, and staged metadata repair

Date: 21 November 2026, Africa/Cairo. This source review uses exact PR #1012
source (merged PR #1001 and the PR #987 source head 69dfb652). No production
Tour Rate, payment, invoice, published price, booking or supplier quote was
created during this review.

## 1. Byte-exact live code reconciliation

Among the 144 changed PR #987 source paths, 44 belong to runtime
WordPress directories. Thirty-two match both Tours.dev and Wayfatours
exactly. Eleven original differences were byte-identical between both
live sites but differed from Git; the missing twelfth is a README.

The updated source now includes the live read-only Package source discovery,
draft-vs-published Unit inspection, and native Admin links to original
JetEngine Unit drafts, plus the existing stage-specific train-source warning.
The source additionally resolves the Ramses Station by its canonical site-local
post slug/title and Bashtil station by site-local slug/title **plus** Giza-city
term. No raw station post ID is assumed across companies.

The public train references are **editorial warnings**, not operator-confirmed
sailings, ticket inventory, Abela cabin fares or signed supplier Cost Receipts.
No actual train 86/87 booking or issue of a Rate is inferred. The original
strict source-native Package Stage Rate Guard and the stronger source
Supplier Admission evidence contract were **retained**, not overwritten by a
weaker live module during source reconciliation.

## 2. Actual native Staging Package relation correction

Tours.dev has eight canonical Package plans, 28 native accommodation stages,
80 saved source candidate Property rows and 35 distinct originally linked
Unit drafts. All 35 had their original source receipt Property ID and native
JetEngine Relation 223 already correct, but all 80 saved candidate rows held
source_relation_id = 0. A guarded, transaction-bound Staging metadata repair
normalized precisely **64 candidate rows** containing verified Units to
source_relation_id = 223, across **eight Post Meta records**.

The remaining 16 source Property rows are all the same Gold-tier Hilton Grand
Nile Tower Property 36479, with **no native Unit relation**; these 16 rows
remain 0. All 35 Unit posts remain **Draft** and cannot become priced or
bookable through this structural fix.

A root-only recovery bundle stores each of the original and target serialized
postmeta values, exact SHA256, each meta_id and original source plan revision.
It resides only on the VPS under:

    /opt/mad4b-company-provisioning/reports/source-unification-20261119/live-commercial-reconciliation-20261121/actual-runtime/

The repair was atomic and optimistic (InnoDB Post Meta source lock, exact
original SHA256 comparisons, all-or-nothing eight-row write and readback).
Original Rate count was **228 before and after**, and the live Pricing Lab
read-only verification returned **99/99 PASS**, zero Unit-to-Property relation
mismatches, zero approved supplier costs, zero availability flags, and no
commercial generator activation. No Wayfatours metadata was changed.

## 3. Real commercial readiness (independent of CI)

A native original Package Rate Guard read-only preflight on both companies
inspected eight canonical Package Tours × Silver/Gold/Diamond, a total of
24 per company, using the example period 2027-02-01 through 2027-02-28.
**0/24 passed commercial readiness on either site**, without creating Rates.
Tour Rates remained 228→228 on Tours.dev and 108→108 on Wayfatours.

Across each company's 24 tier reviews, source blockers include:

- 57 stage instances lacking selected Property, bookable Unit, and an approved
  stage supplier Cost Receipt;
- 27 itinerary overnight review signals;
- 21 cruise stage inspections missing selected vessel/cabin/sailing, approved
  cost and a dedicated native Rate-stage provider;
- 9 stage-tier inspections with no destination-qualified hotel candidate,
  covering Aswan, Luxor and Sharm;
- 6 sleeper train stage-tier inspections lacking a dedicated native stage
  provider, confirmed cabin inventory/tariff and operator departure point.

Do not turn Unit Start Price or old retail Tour Rate into supplier cost. A Self
supplier fallback **without its own validated cost receipt remains unpriced**.
Source admission is complete enough to fail closed, but **commercial approval
and Quote/Deposit/Checkout acceptance remain blocked** until real sources,
dates, available stock, occupancy and certified providers exist.

## 4. Source release safety

- Reversible Staging-only Post Meta repair; no WP source plugin deployment
  occurred solely to perform this metadata fix.
- PHP and JS source edits are a Draft review candidate; no blanket replacement
  of the live Wayfatours production source.
- Existing source Supplier Admission has additional native transport-linked Rate
  evidence guards compared with the older active two-company copy; it is held
  for a separate full behavior test before selective Production replacement.
- Feature 007/PR942 source conflicts and exact owner release approval remain
  independently scoped. Git CI is not a commercial cost acceptance receipt.
