---
name: nile-intelligent-inheritance
description: "Use when resolving Nile Cruise Tour Types taxonomy and nested child classifications against vessel Property Types, vessel Stars, Amenities, Tour Rates or frontend filters, especially when a Tour lacks its own category or the values conflict."
---

# MAD4B Nile Cruise — Intelligent, Provenance-Aware Inheritance

Scope: site-local WordPress Tours, their native Tour Types taxonomy, Cruise Properties, Property Types taxonomy, Stars, Amenities and Tour Rates. This Skill is read-first and never grants write, publication or payment authority.

## Source hierarchy

1. **Tour Types are owned by the Tour.** Terms explicitly assigned to `tours-and-activities` remain the highest authority for category, Elementor widgets and archive filters. Never silently replace or append terms on the live Tour.
2. **Property Types describe the vessel.** A verified published Cruise Property, bound through `tour_cruise_property_id`, may suggest Tour Type children via exact site-local taxonomy slugs or vetted `mad4b_tour_type_slug_v1` term meta. Map `property-types:nile-cruise` to the `tour-types:nile-cruises` root only. Unmapped generic Property Types remain advisory, not guessed.
3. **Two independent Tour Type axes:** vessel family (Dahabiya, Felucca, Lake Nasser or configured `mad4b_classification_axis_v1`) and experience tier (Standard, Deluxe, Luxury, Ultra-deluxe, Ultra-luxury or configured term metadata). A Tour marked Luxury and a Property marked Dahabiya may legitimately combine. A Tour marked Felucca and a Property marked Dahabiya disagree on the same axis; preserve explicit data and flag a conflict.
4. **Ancestors are filter/navigation context**, never a substitute for a missing child. Resolve root Nile Cruises by taxonomy hierarchy, not numeric term IDs. Keep `effective` vs `explicit` vs `inherited` vs `materialized` separate. A virtual inherited category can render in a hero but will NOT satisfy an archive taxonomy SQL filter unless its Tour relation is actually saved by an authorized operation.
5. **Stars and Amenities belong to Property**, with verified Tour-specific Amenities as distinct overrides. Do not interpret the voyage's own rating as the vessel's star rating, and do not derive luxury tier from stars without source evidence. Tour Rate controls per-vessel eligibility, not taxonomy inheritance.
6. Use `MAD4B_Nile_Intelligent_Inheritance_V1::resolve($tour,$rate)` and the `wp mad4b nile-inheritance <Tour-ID> [--rate=ID]` read-only command. Use `state_sha()` to detect changing source evidence; `accepts_issue()` rejects stale or forged Content Readiness alerts.
7. Render inherited `stars`/`tour-types` via the original JetEngine term query and Listing Grid, preserving the complete Elementor HTML/CSS and visual sizing. NEVER substitute compact chips, custom badges, new CSS or hand-written Elementor-like HTML. Preserve other widgets and their JetEngine query contracts. Expose editor-only reasons for missing classification. Never add a guest-visible draft service, preview-only query, unsourced photo or payment option as a side effect.
8. In Ready to Publish, require the actual Tour Type relationship when an inherited child is suggested, so archives, WPML links, sorting, filters and UI agree. Any materialization must be a separate company-authorized CAS job with exact term lineage, preservation of current relationships, rollback and independent readback.
9. Support each company's own local term IDs and translation mapping. Never copy Site A's taxonomy IDs, Property UUID or Pricing settings into Site B. Read native JetEngine metadata and Profile Registry rather than assuming one fixed taxonomy tree.

Return: `tour_id`, `property_id`, `rate_id`, explicit Tour Type children, derived children, classification axes, effective and archive-filter terms, stars, amenities, provenance, conflicts, source SHA, materialization-needed and authorized next action. If evidence is missing, return a review issue instead of fabricating a value.
