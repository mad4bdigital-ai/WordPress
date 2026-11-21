# Native Accommodation Unit/Property Rate source precedence — 2026-11-21

## Live-first scope

Applied to Tours.dev and Wayfatours using EXISTING modules:
- 006-mad4b-accommodation-layered-pricing.php — source selection
- 008-mad4b-stay-pricing-controls-panel.php — admin readback
- mad4b-stay-pricing-admin/panel.html + ui.js — existing UI
- package-stage-rate-guard-v1.php — cost source proof
- package-component-readiness.php — source trace
- transport-distance-time.js — package-stage source diagnostics

No new CPT, price policy store, pricing engine, invented Unit, availability
assignment, Rate publication or WooCommerce side effects.

## Source hierarchy — independent cost and sell lanes

Self-provider indicative SELL order:
1. eligible published Unit Rate with explicit stay dates, board and Unit
2. eligible published Property Rate with scope=property and Unit ID=0
3. Unit Direct — indicative only
4. Property Direct — indicative only

Supplier COST reference hierarchy:
1. eligible published Unit Rate of cost kind
2. eligible published Property Rate of cost kind with explicit Property scope

Direct price amounts are NOT eligible to certify NET vendor costs.
The existing source-policy rate_weight/direct_weight (100/50, next_valid)
is a separate mechanism, not Unit versus Property competition.

The read-only preview shows the cost source separately from the selling
price. Neither is a certified supplier invoice or checkout authorization.
Overlapping Rate candidates cause fail-closed errors.

## Package Stage Guard scope

A saved approved supplier_cost_receipt can reference:
- Unit Rate: selected and linked Unit/Property identity; or
- Property-wide Rate: explicit scope=property, zero related Unit ID,
  zero receipt Unit ID, and the selected Property.

The guard retains eligible property, selected Unit, relation 223,
capacity/availability, cost kind, amount, currency, dated validity, board,
Stop Sale and QA restrictions. Property-wide cost remains explicitly
blocked with stage_property_cost_native_unit_allocation_unverified until
the original Accommodation Booking Core can allocate a real stay to a
certified Unit. Therefore property fallback is presently an approved
cost EVIDENCE path, not commercial Rate generation or checkout.

## Existing weights and margin policy

Weight inheritance: Global -> Property Type -> Destination -> Property -> Unit
with Single/Double/Triple and Standard/Deluxe/Suite.
Effective policy is displayed read-only with per-field source provenance.

Explicit Rate amounts are NOT multiplied by room/occupancy weights again
(MAD4B original weight policy rejects origin=explicit_rate).
Meal supplements and night-based profit margin policies remain separate.
Current both-company global weights = 0.00% for all six fields;
default occupancy reference Double. No guessed percentages were set.

## Baseline

Tours.dev Property Rates: 29 Draft, 0 Published, 0 Property-scoped.
Wayfatours Property Rates: 28 Draft, 0 Published, 0 Property-scoped.
These existing Drafts are Unit-linked, unscoped QA/legacy records;
they were NOT published or adopted as contract evidence.

The source quote spreadsheet contains hotel price references but does
not certify Unit identity, occupancy, inventory or every date/board.
A future verified supplier agreement may be entered in the native
JetEngine Property Rates editor with scope=property and Unit ID=0.
Unapproved source sheet prices remain non-commercial.

## Release, governance and rollback

Full site-specific before/after SHA-256 and patch round-trip receipts:
  /opt/mad4b-package-checkpoints/2026-11-21-property-rate-fallback/

Native Package Stage Guard source is reconciled in existing LOCAL
mad4b-commerce review branch at:
  487b17037c39f3579cc950bcd4f107a676af6ee5

The commerce source archive has no remote configured. Do NOT claim
main source deployment parity or revert modern runtime from old Git.
Other live MU components are supplied as preimage-pinned source patches.
Main WordPress files and regression are tracked in Draft PR #1003;
Release Verdict exact-head owner attestation remains required.

No new Tour Rate, Property Rate, Unit price, booking, inventory hold,
receipt approval or checkout action was performed.

