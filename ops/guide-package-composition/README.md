# Package Guide Cost Composition — read-only, tenant-aware v1

Deploy scope: Tours.dev Staging first, then Wayfatours Production.
Owner request: Replace legacy retail-rate recycling with new dynamic component-cost pricing; guide base: Arabic EGP 2,000/day, English EGP 2,500/day, other verified languages EGP 3,000/day (supplier self). The base policy is stored separately in mad4b_guide_daily_base_cost_v1.

This phase implements the source data and fail-closed readiness gates; it does NOT invent guide service days, supplier contracts, hotel/transport costs, profit margins, or retail rate amounts.

## Delivered

1. A company-pricing-authorized admin meta box on canonical EN tours-and-activities called MAD4B · Guide Service Cost Days. It lists the authoritative structured itinerary_item stages, one status per day: unknown, full_day, half_day, none, optional. Saved only on authorized explicit editor action with nonce, capability, and itinerary SHA.
2. For cruise tours, reuse programs taxonomy program_itinerary instead of silently using post itinerary; if multiple programs, a specific program must be selected. Canonical program term editor has an equivalent day editor. Unsupported string itinerary stays unresolved.
3. Coverage stored in _mad4b_guide_day_coverage_v1 on canonical Tour or Program, with status map, revision, reviewer, date, and source_hash. Changed itinerary invalidates review rather than silently moving statuses between days. Blank/unknown stages are NOT zero-priced.
4. Half-day factor is explicit editable value, not silently assumed (must be >0 and <=1 when provided). Optional guide days are excluded from base until selected separately.
5. Cost resolver MAD4B_Guide_Service_Composition_V1::guide(tour,term_slug_or_id,program,guides) checks assigned language taxonomy (WPML canonical term IDs), per guide, per guided-day. Rejects unsupported language, incomplete day data and changed source.
6. Explicit no-guide and optional-only coverage produces zero mandatory Guide cost without requiring a language. Internal Package Cost Breakdown ::breakdown(...): Guide component, EGP cost, optional delta vs explicit baseline language, source fingerprint, Rate inclusion state. Existing Rate inclusion defaults unknown; a new Tour Rate editor meta box saves an attributable review for included, excluded or partial coverage, with baseline language, evidence, itinerary hash and Rate price hash. The review expires after edits. Included costs use only the cost difference from the saved baseline, excluded costs expose full cost, partial/unknown costs cannot be re-added. NEVER adds to Tour Rate, public selling quote or checkout. Hotel/transport/extras supplier-cost lines remain unverified; total null.
7. New generation preflight ::generationPlan(...) has separate source_mode=component_cost_only, legacy_rate_price_copied=false, native_rate_generator_invoked=false; blocks on uncertified supplier costs/margin. Existing native Rate Generator receives read-only coverage inspector via mad4b/rate_generator/plan. Existing amounts, fingerprints, stop-sale and draft mode unchanged.
8. Fluent Forms #6 submissions keep guide_language, gain _mad4b_guide_preference_v1 only when server-verified Tour intent exists, without exposing supplier costs to public users or changing price.

## Private admin REST

Requires valid WordPress REST authentication, company pricing permission and Tour edit permission. Cache-Control: private, no-store.
- GET /wp-json/mad4b-guide/v1/package-cost?tour_id=...&language=english&program_id=...&guide_count=1
- Add baseline_language=french for the internal guide cost delta.
- GET /wp-json/mad4b-guide/v1/generation-preflight?tour_id=...&language=english

Missing reviewer record gives guide_coverage_not_reviewed. Both routes deny anonymous requests.

## Acceptance tests

Via WP CLI in an enrolled site's WordPress container:
- wp eval-file /tmp/mad4b-guide-composition-verify.php --allow-root
- wp eval-file /tmp/mad4b-guide-form6-preference-test.php --allow-root
- wp eval-file /tmp/mad4b-guide-generation-preflight-test.php --allow-root
- wp eval-file /tmp/mad4b-guide-rate-inclusion-test.php --allow-root
- PHP syntax validation.

Synthetic coverage in tests is intercepted by WordPress metadata filters in memory; no real Tour or Program service-day records are altered. Tests cover full/half/optional/none/unknown, WPML language eligibility, cruise programs, cost delta, itinerary fingerprint mismatch, guest denial, Rate inclusion/double-counting and Rate generation fail-closed guards.

## Explicitly NOT completed

- No human-reviewed guided-day statuses exist for live Tour/Program records. Descriptive itinerary text does not certify actual guide days.
- No certified accommodation, transportation or extras supplier-COST quote composition into a complete Package cost (native accommodation shadow currently describes selling quote, not supplier cost).
- No approved markup/margin, currency conversion and occupancy cost allocation into final retail price.
- No new Rate draft was written; previous Drafts untouched. Old retail amount is not used by the new component-cost path.
- Form #6 client price remains the existing retail quote. Guide preference is recorded for nonfinancial quote/internal cost comparison only, not a misleading customer price change.
- No Guide kind was injected into legacy mad4b.commercial-component.shadow.v1 selling quote. Cost and retail price components are separate.

Next: review real guided-day statuses; certify supplier costs from hotel/transport/extra providers; approve selling policy and inclusion/double-charge evidence; bridge signed component cost snapshots into draft-only native Rate Generator with financial QA. Never treat cleaning-era Draft Rates as supplier cost authority.

## Day-count safety
Multi-row itineraries use explicit day labels (Day/Jour/Día/Tag/Giorno N or Arabic day ordinal) on every day. Hourly or stop sequences may be aggregated into ONE guide day only when the original Tour title explicitly identifies 1-day/full-day/half-day or a bounded 1–19-hour duration. Otherwise return guide_itinerary_day_units_unverified. Seven stops in one day are NOT seven guide days. The source hash includes the text descriptions and, for aggregated stages, the duration-proving title; changes invalidate approvals. Single-row itineraries count as one day.
