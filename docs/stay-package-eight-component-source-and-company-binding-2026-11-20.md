# Eight Package Tours — component-source discovery and company policy audit

Live Wayfatours execution: 20 November 2026. Source-admission only; no saleable Rate or supplier booking created.

## Coverage and source candidates

Eight English and eight Spanish Package Tours received exactly 16 private source-admission receipts, reviewing 28 stage groups / 56 localized lodging stages. These are attached to the previously approved Tour Source Plans and hash-bound to their content. Cairo hotel stage alternatives remain 5 Silver, 6 Gold, 7 Diamond, from the existing 18-hotel cohort; missing price remains pending_quote.

Live native catalog returns published English Properties with no Published linked Units:
- Aswan Hotel: 8; Luxor Hotel: 11.
- Sharm Hotel: 43; Sharm Resort: 0. Hotel taxonomy cannot automatically substitute for Resort.
- Nile Cruise in Aswan scope: 221, Luxor scope: 36; room/cabin Units linked: 0, direction/program/sailings unverified. These counts are classification matches, not cruise offers or unique supplier inventory.
- Sleeper service source reference for tour 60623: native transportations ID 54552, Sleeping Train No.86, Cairo→Aswan via Luxor station 54257; return ID 54553, No.87, Aswan→Cairo via Luxor. Native train type and ordered station route verified. Both have trans_use_in_tour_rate_generation=0, unpriced, no confirmed cabin supplier or capacity. Only route/source references saved, not live bookable components. Spanish transport translations absent; no localized service IDs were fabricated.

All stages outside Cairo explicitly store an empty qualified-component list, a raw catalog review and required supplier/room/product gates. No confirmed hotel room, cruise ship/cabin, train cabin, resort unit, scheduled departure or quoted price is asserted.

## Company Policy readback — not restored without authority

Native CommerceTenantBindingAudit reports Site Profile revision 3 and healthy site authority, but company_definition_revision=0, policy_business_revision=0 and classification=stale_or_incomplete_site_policy. Options commerce_management_company_definitions_v1 and commerce_company_policy_registry_v1 are absent. policy_resolution_available=false, automatic_rebinding_forbidden=true. The existing mad4b-company-policy-library stores legacy business-policy texts and is not the site-bound Commerce Registry.

A legitimate site-bound company definition and approved business policy must be issued through the governed management flow, binding site UUID, profile digest, revision and environment and covering the required pricing, tax, supplier, margin, availability, payment and order-timing decisions. No registry was fabricated or copied, and no commerce authority was granted.

## Evidence and safety

Source code: scripts/mad4b-package-eight-component-source-admission-cli.php; supports plan, one-time apply, verify.
Prewrite private backup: /opt/mad4b-company-provisioning/reports/stay-cohort-remediation-20261120/package8-component-admission-before.json.
All 16 newly added stage-admission receipts verified; existing Tour source-plan, itinerary, Includes and Policies hashes unchanged. 32/32 legacy hand-authored Draft Tour Rates have exact identical postmeta/status/modified timestamps before and after. Published Tour Rates=0, Property Rates=0, Transport Rates=0, catalog_activated=false, checkout disabled. No supplier products, rates, stock or orders were changed.

To form a real new Draft Tour Rate, certify regional room Units, cruise programs and cabins, sleeper cabin supplier and availability, and tenant-bound company policy. No source-only reference is a valid payment amount or authority to book.
