# Company Experience Unity — source and Wayfatours runtime contract

The customer-facing flow is Source/Company Activation -> Country Operating Scope -> WPML active language -> Elementor Home -> Header/Navigation -> Archive facets and price -> Published Tour -> Pricing Workspace and Company Reconciliation. The rules for which content can be sold or rendered are separate from the design widgets. A company can have broader Destination Activation catalog data without being authorized to operate outside Service Activation's operating_country_ids.

Auditor: audit_experience.py reads native WPML, Service Activation, country scope, Look & Feel, native destination menu, JetEngine/JetSmartFilters facet contract and price markers in the public HTML. It checks Home, archive and one published Tour **per currently active language** for both independently enrolled sites, binds each URL to that site's origin, checks the Combined section against the corresponding filter facet, and fails closed on any published geographically held Tour.

Incident fixed: translated country term ancestry was previously derived using the locale-sensitive get_term() and could incorrectly turn Egypt 665 into Spanish Egypt 669. Canonical country ancestry now uses native unfiltered term_taxonomy parents; the WPML canonical EN Tour's reviewed itinerary geography is inherited by translations. Different explicit translated itinerary countries are held for review. Combined Tours are recognized from canonical tour-type identity across languages. Published Tour counts and menu sale terms must now be read back in every active WPML language as part of company-country-scope-runtime-contract-v1.py, so the old P1 blind spot cannot silently pass.

Read-only acceptance on 2026-11-17:
- Tours.dev (source): EN, ES, DE, FR, AR, IT — 18 frontend URLs.
- Wayfatours (tenant): EN and ES — 6 frontend URLs.
- Each locale: Home HTML and live Elementor section decisions, archive price marker and package facet choice, direct published Tour HTTP 200, header approved country against operating country policy, zero published Tours in a geographic HOLD.
- Service Activation disabled and Egypt Destination Activation disabled were each simulated through request-local option filters on both sites without a persistent option update; visibility and geography reacted correctly. Adding Jordan to Wayfatours operating scope was also simulated; the corresponding Tour stayed Draft.
- Wayfatours: 5 cross-border Draft Tours remain HTTP 404 to guests, with 16 related published Rate records retained.
- Wayfatours Spanish FastCGI: 102 site- and locale-scoped stale HTML cache keys were invalidated; 273 unrelated cached files were preserved. Correct MISS then HIT was verified for the Spanish Home, archive, and a published Tour.

Contract owners:
- Service Activation: channels and company operating countries.
- Destination Activation: catalog availability, not company authorization.
- Shared Product Core: published Tour eligibility and searchable facets.
- WPML: locally active languages, translated term IDs/URLs and layouts.
- MAD4B Platform/Wayfatours MU: Homepage widget visibility, no duplicate site-specific Elementor code.
- Pricing Workspace: price/rate strategy + geographic publication holds, not a checkout approval.
- Company Reconciliation: compare live source/target facts, retaining intentional company overrides.

Known boundaries: A Nile Cruises taxonomy can legitimately appear in the archive facet even while the curated Nile Home section has zero tours in its three separate card terms. Experimental LAB filter 66104 has stale fallback metadata but does not drive the tested archive. Three Wayfatours-only presentation adapters require company-specific preservation, not source overwrite. Multi-program positive testing was unavailable in the semantic report. Full interactive JetSmartFilters AJAX, keyboard/mobile layout and booking/payment completion were **not** asserted by this HTTP+WP-CLI read-only audit. Source Git changes are not Original313-signed release or a fleet-wide tenant deployment.
