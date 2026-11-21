# Package-stage quotation season scope (21 Nov 2026)

Existing Package Component Readiness previously retrieved only Winter quote
references, regardless of the selected travel window. That could display
Winter supplier prices as reference candidates for Summer dates.

The review now obtains general Summer 2026 and Winter 2026/27 windows from
the existing live Season Term Meta through the supplier-reference module,
including WPML policy-term inheritance. It determines each accommodation
stage's FIRST and LAST overnight across the entire selected departure-date
range: departure window + source day offset + number of nights - 1.
It offers a matching quotation reference only when this entire range fits
within the original quoted season AND quoted year.

For a stage crossing two seasons, beyond the quoted year, or lacking valid
night geometry, quote reference counts are zero and the status explains
that the period is mixed or out of scope. No false zero-cost item is
substituted.

Summer does not display Winter references. Legacy output keys
sheet_winter_quote_values and sheet_winter_quote_properties remain for
compatibility, but are populated only when the chosen stage quote period
really is Winter 2026/27. New keys sheet_quote_period, sheet_quote_scope,
sheet_quote_values and sheet_quote_properties drive the current UI.

The output explicitly remains reference-only and never asserts vendor
contract applicability, Peak exceptions, room occupancy, cabin, inventory,
availability, invoice or checkout. Summer/Winter general period match is
NOT supplier price validity.

Live checks:
- June 2026: Summer reference only.
- December 2026: Winter 2026/27 reference only.
- Crossing September/October: no quote where a stage overlaps seasons.
- June 2027: no quote from the 2026 Summer source.
- 28/28 date-scope regression assertions passed on each of Tours.dev and
  Wayfatours.

No Tour Rate / Property Rate / WooCommerce mutation occurred.

