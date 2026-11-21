# Live Season Term Meta + supplier quotation notes

This read-only workflow uses the existing WordPress `season` taxonomy and its
native metadata for the general Summer/Winter calendar. It **does not**
introduce duplicate seasons or overwrite existing term metadata.

For a quotation ledger whose source header identifies Winter 2026/27,
the private importer records an anchor year of 2026; the PHP reader then
queries the live site independently for:

- `season_enabled`
- `season_start_month` / `season_start_day`
- `season_end_month` / `season_end_day`
- `season_cross_year` / `season_priority`

This returns Summer 2026-05-01 to 2026-09-30 and Winter 2026-10-01 to
2027-04-30 on the two currently enrolled sites. Term IDs are resolved by slug,
not copied across sites. These are **base policy windows**, not supplier
contract approval.

Peak is **not** a single global season term. Actual Peak windows, short
mid-season overrides, year-end compulsory gala dinners, optional Christmas
meals, city taxes, child discounts, per-person prices and other supplements
are often included in the quotation's notes. They must remain
supplier-specific and higher precedence than the general season; ambiguous or
contradictory dates must fail closed.

When the original quotation lists a property, notes may be spread over
multiple rows. Preserve every nonempty note column E/F/J/K/O against its
original source cell and season. A quote's numeric value **must not** be
promoted without checking neighboring note fragments. Individual notes are
displayed as source text, not interpreted as a verified net price or a booking
rule.

The private database option
`mad4b_supplier_quote_sheet_reference_v1` retains quoted source data
and adds `source_note_cells` on matched records plus
`sheet_period_anchor_year`. It is non-autoloaded and admin-only. Source
spreadsheets, private supplier amounts and company-specific WordPress IDs
are not included in this Git commit.

Test with
`wp --user=<authorized-admin-id> eval-file wp-content/plugins/mad4b-pricing-registry/tests/supplier-season-note-regression.php`
on each site. Expect 18 checks on Tours.dev and 19 on Wayfatours
(the latter checks the Pyramids Park optional versus mandatory dinner notes).

**Next commercial conversion:** interpret each supplier note into dated
override/supplement records with source cell, inclusivity of the end date,
per-person/room-night basis, meal plan and child policy; require explicit
reconciliation of contradictions before the approved cost receipt feeds the
Rate Generator. Never convert an unverified quote into Checkout authority.
