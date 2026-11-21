# Native Season Term Meta under WPML (2026-11-21)

**Observed live defect:** The site's WordPress multilingual term translation
returns localized `season` Terms whose meta is blank. Previously
`MAD4B_Package_Seasonal_Assignment_V1::seasons()` checked
`season_enabled` against that translated Term, so every non-English locale
lost Summer/Winter eligibility. Quote Sheet inherited date context also read
the translated Term and failed.

**Fix:** resolve the requested localized Season by native taxonomy slug, then
when its `season_enabled` field is blank, resolve the original policy Term
through the existing WPML `wpml_object_id` filter. Require a successful
**two-way** default-language/local-language mapping and an enabled policy
Term. Read calendar dates only from that authority. Keep localized names and
IDs for presentation, and record `policy_term_id` for audit. No new seasonal
calendar, Meta duplication or synthetic vendor dates.

The seasonal resolver caches policy definitions by active language within a
request and invalidates them when terms or Term Meta change.

The Supplier Sheet reference catalog calls that same centralized resolver
rather than maintaining a separate language-specific calendar path. Peak
remains supplier-specific and never inherits a global fixed window.

**Live verification:** Tours.dev EN/FR/DE/ES/IT/AR passed (6/6);
Wayfatours EN/ES passed (2/2), including Winter spanning
2026-10-01 to 2027-04-30 and the quotation base period.
Existing seasonal, supplier-sheet and package regression suites remained
green. No price, Rate, inventory or checkout change.

This was applied to the existing pricing plugin on both sites. The
shared-core native patches and release parity guard from PR #1003 remain
the source reconciliation boundary; no replacement of newer live core
with older Lab files is authorized.
