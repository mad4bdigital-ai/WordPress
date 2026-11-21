# Supplier Quotation Reference — governed evidence (2026-11-21)

This module reads a private, non-autoloaded WordPress option under `manage_options`
and exposes admin-only, read-only AJAX via Dynamic Pricing Controls / Package & Rates.

The quotation is user-supplied external data. A quoted number is **not** an
approved supplier cost receipt and cannot be used to create Tour Rates, Bookings,
supplier inventory or payments. The source data is kept outside Git and carries
its original tab, row, season label, price, currency, guest occupancy, meal plan,
notes, date ambiguity and approval status.

Five precautions are enforced:
1. A quotation's spreadsheet season is not a validated arrival/departure window.
2. Single/double/triple prices can have different cost bases; do not convert
   per-person to per-room or per-night without explicit supplier terms.
3. EUR values are preserved as EUR and never silently treated as USD.
4. Missing, empty or malformed prices are unavailable, **not zero**.
5. A website's publicly advertised rooms, amenities or tariff is not a signed
   B2B supplier agreement, confirmed capacity or net rate.

Native `MAD4B_Package_Stage_Rate_Guard_V1` remains the sole authority for
stage commercial readiness. The read-only integration can count matching
source quotations and Draft room categories per tier, but those counts
never remove stage blockers or authorize checkout.

Company-level imports must validate property identity, English WPML language,
Property type and destination **on the target site**. Never reuse source-site
numeric IDs across WordPress installations. The live operator import procedure
is intentionally not in the public plugin repository.

Official website enrichment is limited to publicly accessible room category
headings, within public robots rules and without copying media or bypassing
protected booking endpoints. Created catalog entries are Draft, unavailable,
unpriced and have individual official source receipts. Supplier and occupancy
verification remain separate.

Regression coverage:
- admin-only reference and period/tab validation
- currency/basis/date/booking fail-closed
- sheet quotation counts and native stage linkage
- non-admin rejection
- legacy Transport and Package readiness regressions

Promotion to a real commercial Rate requires separately verified contracts,
valid date boundaries, unit occupancy, meal plan, gala supplements,
supplier confirmation, taxes, currency conversion, margin, and booking E2E.

No source prices, private spreadsheet identifiers, personal traveler data or
vendor contact details should be committed to Git.

