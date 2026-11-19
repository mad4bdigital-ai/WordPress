# Accommodation suppliers — external first, company self second

Review-only source candidate preserving the live Wayfatours accommodation pricing layer.

## Supplier vs price-source order

- Supplier priority 1: externally contracted supplier, only after a verified quote connector exists.
- Supplier priority 2: native company supplier self, via existing mad4b_stay_layered_price_preview_v1.
- Within self: Unit Rate -> Unit Direct -> Property Rate -> Property Direct.
- This policy is read-only; no supplier feed is currently connected, and the self supplier has no external catalog/quote callbacks.
- Self is an internal supplier identity, marketing_authorized=false; public listings and supplier checkout are not enabled.
- In a tenant without exact mad4b_stay_supplier_precedence_v1 option, new supplier registration is inert. Do not configure this option on other companies automatically.
- No fabricated prices, costs, supplier availability, stock or approved rates.
- Fallback is a precedence policy, not a cross-supplier quote-orchestration engine. A future trusted integration must prove external quote eligibility and rate approval before selecting or selling a quote.

## Native admin UX

- wp-admin/admin.php?page=dynamic-pricing-controls: reads supplier precedence and status; displays published and draft Properties/Units for authorized editors.
- Draft Units/Properties are read-only for price preview; returns draft_entity_pending_review, does not invoke published-only native resolver.
- Existing JetEngine Relation 223 remains the property-unit linkage.
- Quoting and payments remain disabled for drafts.

## Live acceptance — 19 November 2026

- Site: Wayfatours Production. Source branch is for review, not a release.
- Supplier registry: only self; external priority slot unconnected.
- Option contract: mad4b.accommodation-supplier-precedence.v1.
- Units: 149 total, including 131 draft curated units; Relation 223: 148 links.
- Published Property Rates: 0; published Tour Rates: 0.
- Admin lookup for Four Seasons First Residence returns 12 linked draft Units.
- Native self price preview returns no approved price for unpriced units.
- Company-wide rollout needs independent source reconciliation, CI and approval.

## Activated company fallback — same day

- Wayfatours Production option adds self_fallback_enabled=true, preserving external_contract as rank 1, self as rank 2, operation=preview_only, pricing_activated=false and booking_authorized=false.
- The automatic admin supplier route MAD4B_Stay_Secondary_Self_Supplier_V1::resolve is called by the JetEngine dynamic Pricing Control, the native layered pricing editor/REST preview, and the commercial readiness handoff.
- When there is no verified external selling-price adapter, it selects the company self as the fallback supplier and invokes the existing native price engine for published entities. It never invents an external offer.
- Draft Unit/Property outcomes explicitly return draft_entity_pending_review and no eligible price. Invalid JetEngine relations are rejected.
- An empty self amount yields Self selected / Unpriced. An eligible existing self amount yields an indicative self price, never checkout-ready.
- External-connector registration is not evidence of an approved external price; future integration must add a certified comparison before external can be selected. Current external quote checks remain 0.
- No publishing, deposits, prices, inventory, user permissions, or third-party supplier setup was performed.
- 131/131 curated draft Units remain unavailable, linked to 17 hotels. No published Property Rates or Tour Rates.
- The live WordPress database policy is intentionally not stored in the Git source and must remain company-specific during rollout.

## Source reconciliation and CI runtime review

- Staging has independent self_fallback_enabled=true and runtime parity; Wayfa remains unchanged.
- Exact CI hermetic runner review: 17 previously unregistered PHP fixtures passed with network disabled and read-only source mount.
- Ten independent source-drift fixtures FAILED in sandbox; kept quarantined and still require source review, not authorized as standalone.
- The source Skills catalog read-only test was corrected to its current contract: enrolled catalog stays readable even without the editor feature flag.
- CI remains non-authorizing pending unrelated source baseline regressions, required checks and review.

## Readiness source parity recovery

- Live Tours.dev and Wayfatours already have the newer 020-mad4b-stay-commercial-readiness.php.
- This version primes bounded Post and Property meta and defers edit-link generation until paginated results are selected.
- It was reconciled into this candidate from the matching live sources instead of replacing either site with an older Git copy.
- Runtime status is a read-only preview; no booking or price activation is implied.

- Following integration ab3fa778, 10 source-drift scripts are exact-path registered for real execution (not considered passing or approved); negative runtime evidence remains recorded and required release scopes continue to fail on actual source drift.
