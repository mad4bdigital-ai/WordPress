# MAD4B: internal hotel cost scenario and supplier evidence review

Date: 2026-11-21

## Implemented on both enrolled WordPress sites

This extends the existing Dynamic Pricing Controls -> Package & Rates
interface. It does not create a new pricing engine or CPT.

1. The package-stage scenario takes an existing package (8 allowlisted),
   package tier, hotel stage, eligible Property, departure date and
   double/triple occupancy. Native Stage Guard validates destination,
   tier, source chronology and seasonal eligibility.
2. A saved Property-wide supplier reference Draft is matched against the
   ORIGINAL supplier quotation ledger by Property ID, source cell, numeric
   amount, occupancy, board and dated Season Term Meta. Stale amounts,
   unknown dates, changed source or multiple competing rates fail closed.
3. Original sheet records only 'per person', not a verified per-night basis.
   An unchecked scenario returns the source number but NO total. An explicit
   optional checkbox displays pure arithmetic
   (source amount x adults x nights) as an INTERNAL HYPOTHESIS ONLY.
   Neither mode authorizes a customer quote, signed NET cost, invoice,
   Tour Rate generation or WooCommerce checkout.
4. Seasonal, Peak and Gala source note references appear alongside
   candidate scenarios and in a grouped supplier evidence review queue.
   No missing period year, mandatory supplement or children policy is
   guessed. The queue groups the original source Rates per Property,
   with clickable native editor URLs and missing contract, nightly basis,
   unit availability and Cost Receipt evidence.
5. WPML guard was corrected to review original source taxonomy term IDs
   under the configured default language, restoring the prior admin
   language in a finally block. Previously a translated admin UI could
   incorrectly see a package as non-package and return ready=true
   with 0 stages. The new guard returns the same genuine blocked stages
   under all enrolled languages.
6. Existing company differences and source classifications are preserved.
   Tours.dev currently has two Gold/Diamond Aswan tier-stage candidate
   gaps; Wayfatours has nine tier-stage gaps across Luxor, Sharm and Aswan.
   No candidate was assigned, no taxonomy term edited, no unit published.

## Commercial constraints

- Source quotation records: 47 Draft Stop Sale on Tours.dev,
  56 Draft Stop Sale on Wayfatours. No previously saved source
  Cost Receipt was approved or published by this implementation.
- There is no authoritative supplier proof for the per-person-per-night
  basis across the hotel source quotations. Existing original amounts
  are not allowed to become verified NET costs by an assumption.
- Property-wide cost evidence may support a future quote-only pricing
  policy after approval, but native booking still requires valid
  available Unit, occupancy, supplier contract and dated commitment.
- Actual Peak, meals, cruise sailings, sleeper trains and mixed
  package services require supplier-specific source approvals.
- Commercial activation also requires exact-head owner attestation and
  a reconciled approved release. The standalone commerce source
  archive has no GitHub remote configured.

## QA

- Source-only calculation and permission regression:
  package-stay-scenario-regression.php (25 checks/company).
- Source-language / Stage Guard regression:
  package-native-multilingual-parity-regression.php
  (6 locales Tours.dev, 2 Wayfatours).
- Supplier evidence queue regression:
  supplier-evidence-queue-regression.php (14/company).
- Candidate diagnostics regression now compares the live guarded
  set with UI rows rather than a stale absolute count.
- Previous seasonal, transport, supplier-sheet, Property Rate and
  booking-readiness regression suites remain required.

No supplier rate amount, copied note bodies, hidden sheet file ID or
actual customer information is embedded in this source note.
Native source reconciliation:
  /opt/mad4b-package-checkpoints/2026-11-21-quote-scenario/
Guard worktree commit: 4370e4ab3913572178f806f316d2e96829fb4ef4

