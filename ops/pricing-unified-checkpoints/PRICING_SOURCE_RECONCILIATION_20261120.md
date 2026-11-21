# Pricing registry source reconciliation — 20 Nov 2026

This records source-code synchronization, not any commercial cost approval.

- Git baseline: branch feature/pricing-unified-checkpoints-20261120 stacked
  on Draft PR #965. Previously the branch tracked only nine Registry files.
- Canonical snapshot: read-only copy of the installed Wayfatours plugin in
  /var/www/html/wp-content/plugins/mad4b-pricing-registry.
- Imported: 34 missing files, including the original Composer PHP/JS/CSS,
  transport PHP/JS/CSS/HTML, transport 2026 PDF source references, CLI tools,
  and WPML config. Files are byte-for-byte from Production; the tracked
  bootstrap gains the installed module requirements and child_ages input.
- The source bootstrap retains the MAD4B name and existing source comments
  in two already-tracked PHP adapters; these differ from live only cosmetically.
  Existing rate/checkout calculations are not modified by the import.
- Site variants: 30 common assets/data/includes across Tours.dev and Wayfa;
  one differing file, transport-form6-dropoff.js. The Wayfa version has a
  guard against redundant option.textContent rewriting and MutationObserver
  recursion. This guarded production version is the selected Git candidate.
  Seven staging-only transport candidate/readiness source files are deliberately
  excluded until their active source status is reconciled.
- Validation: installed production PHP files and candidate bootstrap passed
  PHP lint; imported JavaScript passed node --check; XML parsed and all
  bootstrap module references resolve to files in the source tree.
- Nothing imported was automatically deployed or commercially approved.
  Original legacy Rates, supplier receipts and Checkout were untouched.

## Open release checks

1. Source/live loaded-module parity in a clean WordPress checkout.
2. Staging Form #6 guard reconciliation without overwriting other site changes.
3. Authenticated native JetEngine and independent Product Save E2E.
4. Cost/FX/tax/occupancy receipts and commercial release gates independently.
