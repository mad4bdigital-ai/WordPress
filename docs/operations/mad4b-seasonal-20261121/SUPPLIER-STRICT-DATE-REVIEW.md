# Supplier quotation: strict calendar-date evidence for human review

Date: 2026-11-21. This extends the existing read-only quotation
reference in MAD4B Pricing Registry; it creates NO second importer,
season taxonomy, Property Rate, contract authority or checkout override.

The original supplier note remains unchanged, with its source cell, period
and Property identity. The displayed additional date evidence is parsed
ONLY from full date tokens containing an explicit four-digit year.

Supported conservative formats: DD/MM/YYYY, DD.MM.YYYY, DD-MM-YYYY,
DD Month YYYY, Month DD YYYY, and DD.Mon.YYYY. Token order is preserved.
A chronological pair becomes a candidate range, not a validated rate
period. A single token is an event/partial date and has no window.
Incomplete year formats, malformed tokens, reversed ranges, and a range
over 120 days are flagged for supplier/manual review.

Important: A note with a complete Dec/Jan period and a second Mar date
without its year MUST retain a missing-year warning. Dates from other
cells are available as linked evidence, never filled automatically.
End-date inclusive status remains unconfirmed unless later reconciled
against the actual supplier document.

All per-note proposals include:
- kind, original date tokens, possible windows, review blockers
- requires_review=true, auto_apply_allowed=false
- commercially_approved=false
No cost receipt, capacity, availability, price or child meal is inferred.

Live matched catalogue: Tours.dev 18 syntactically extracted window
candidates; Wayfatours 21. Counts are neither confirmed contracts nor
count of valid Peak overrides. Year/reversal anomalies among cruise
supplier notes remain visible and are not corrected by guessing.

Regression is in
wp-content/plugins/mad4b-pricing-registry/tests/supplier-explicit-date-review-regression.php.
Reference-only test validates source note cells, detected examples,
anomalous dates, lack of checkout permission and unauthenticated denial.

