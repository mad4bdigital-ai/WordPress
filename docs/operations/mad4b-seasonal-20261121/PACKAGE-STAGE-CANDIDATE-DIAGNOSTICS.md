# Package Stage Catalog Candidate Diagnostics (2026-11-21)

The native MAD4B Package Component Readiness report has a new read-only
diagnostic when a Hotel/Resort Stage has no qualifying Property AND the
reason is stage_destination_has_no_candidate_property.

It uses existing destination terms, native Property Type taxonomy,
actual published Property records, the native stage/tier eligibility
guard, and existing private quotation reference identities. It never
creates a Property, chooses a Tier, edits a taxonomy or selects a Unit.
Candidates are explicitly marked assigned=false and qualified=false.

Missing candidates were not nine distinct stages: there are THREE
stages repeated across Silver, Gold and Diamond:
- Tour 60627, hotel-luxor-02: 11 published native hotel candidates
  found in the catalogue; four have existing quotation identities but
  no package-level term.
- Tour 60629, resort-sharm-03: 43 published native hotel candidates
  found by destination/type; they lack the requested tier assignment.
- Tour 60437, hotel-aswan-02: five review candidates. Four lack the
  explicit Aswan-city location proof; a fifth lacks package-level
  classification. No city term is inferred from governorate or name.

These counts are read-only catalogue discovery, not availability, a
supplier contract, a booking-ready hotel, a recommended Tier or a
selected package option. Quoted source means a quotation reference
exists, NOT a valid NET Cost Receipt.

Display in Dynamic Pricing Controls -> Package & Rates -> Package
Readiness under each Stage's blockers. The read-only report is
restricted to the authorized WordPress admin.

Never auto-save a package-level assignment based on hotel star rating,
distance, supplier name or quoted amount. Tier/category requires a
documented company selection. Once the native Property tier and city
classification are established, the existing stage assignment workflow
can accept the Property, followed by Unit occupancy and dated Cost
Receipt. Season override rules should continue to inherit by default.

Read-only regression for exactly 9 affected tier-stage reviews,
unprivileged rejection and non-assignment invariants:
tests/package-stage-candidate-diagnostics-regression.php
18/18 passed on each enrolled WordPress site.

