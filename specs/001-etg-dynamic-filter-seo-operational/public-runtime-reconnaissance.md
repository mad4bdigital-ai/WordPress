# Public Runtime Reconnaissance — Non-Authorizing Evidence

Date: 2026-09-04
Scope: public `www.allroyalegypt.com` surfaces only. This is **not** staging acceptance and MUST NOT authorize a profile.

Observed drift requiring staging proof:

1. Public filter URL `/?tour-styles_jet=archaeology-tours` uses taxonomy slug `tour-styles_jet` (plural `styles`), while the migrated legacy profile currently contains `tour-style_jet`. The bridge MUST keep the unknown/unprofiled state neutral or fail-closed; discovery cannot silently replace the configured slug.
2. Public filter URL `/?tour-types_jet=day-tours` demonstrates query-string filter state. The current bridge grammar is the bounded `/jsf/.../tax/...` grammar; query-string taxonomy filtering remains outside bridge authority unless a later explicit URL-state contract adds it.
3. A public destination URL has exposed a JetSmartFilters route shaped as `jet-engine:archive_category` with `location_jet`. This MUST be treated as a distinct route identity from the migrated `jet-engine:tours_query_archive` profile until runtime inventory proves its Query Builder object and Post Type set.
4. Public navigation currently exposes English plus French, German, Spanish and Italian. Language inventory MUST be runtime-derived; no language should become governed merely because it appeared in a crawl.
5. Tour detail pages expose additional dimensions including Tour Category, Available Guide Language, Tour Location, Tour Type and Tour Style. These dimensions remain discovery evidence only until WordPress taxonomy relations and explicit profile rules are proven.
6. Mixed-content/provider surfaces are possible. A Query Builder query returning an allowed Post Type plus any foreign Post Type remains a hard Post Type authority denial.

Operational consequence: use `etg.dfsb.runtime-inventory.v2` on the intended staging runtime to convert these observations into bounded evidence for T120–T123.


Current public re-check on 2026-09-04:
- `/tours-and-activities/` renders the active tour listing surface and reports 29 results publicly. This proves the Page surface exists, not that it equals the native CPT archive.
- `/?language_guide_jet=english` renders a public Guide Language taxonomy/filter state. This reinforces that query-string discovery exists outside the current bounded `/jsf/.../tax/...` authority grammar.

These observations remain crawler/public evidence only and cannot satisfy T120.
