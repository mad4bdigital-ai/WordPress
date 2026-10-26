# MAD4B Market Profiles v1 — site-scoped contract

## Ownership and boundaries

`MAD4B_SCP_Market_Profiles` owns the canonical option `mad4b_scp_market_profiles_v1`, a site-bound record contract `mad4b.market-profiles.v1`, explicit revisioned drafts and site-admin approvals. It is independent of WooCommerce. It does not authenticate visitor location, determine final pricing, certify Brand Authority, or confer a remote agent's write authority.

- `approved($id)`, `approved_list()`, `by_country($country)`, `status()` are read-only.
- `save_draft($id,$label,$country,$expected_revision)` always changes the record to `draft` and increases the revision.
- `approve($id,$expected_revision)` approves only the exact existing draft at that revision after verifying the enrolled Site Profile and uniqueness of approved countries.
- `import_legacy_drafts($expected_count)` requires an empty destination and explicit local admin execution. Legacy `verified` records become **draft**, never approved.
- `mad4b/market-profiles-read` is the read-only WordPress Ability, registered with `MAD4B_SCP_Policy::can_read` and never exposed as a public endpoint.
- Market discovery reads WPML installed/active languages and **excludes all `hidden_languages`** from suggestions and runtime approval eligibility. WPML entry `active` is the current-page language marker, not a global enabled flag. Hiding a language keeps its stored records intact but makes them ineligible for country-language targeting until visible again.
- Candidate markets are derived from a bundled CLDR territory-language reference. This is advisory language-use evidence, not proof that a site operates in every suggested country, and **never confers approval**. A market is identified by both country and UI language (`US+es` differs from `ES+es` and `US+en`).
- All present write methods are intentionally restricted to an enrolled **local** environment with `manage_options`. Governed Staging/Production mutation would require a separately certified authorization and audit path; do not loosen this condition ad hoc.

## Seasonal integration

The ETG WooCommerce Lab consumer (`107-mad4b-seasonal-market-registry.php`) uses the MAD4B core registry, not the retired `shared_offer_market_registry_v1`. Market Profile selection is validated against the core approved record, its site UUID and exact revision. Known WooCommerce billing/shipping country is only a contextual match signal, **not proof of current physical visitor location**.

Seasons Taxonomy remains the season authority, Shared Product Core remains the financial authority, and MAD4B Context Authority is separate from market approval. `site_approved` must not be treated as a Brand Authority or financial approval.

## Recorded ETG Lab verification (2026-10-24)

- Source branch based on `origin/master` at `4fe4ba9b44cc9b17121a9b0e05e8f1f4e63c6f0f`.
- Live local Lab `draft -> approve -> revise -> draft` succeeded; stale revisions and unrelated countries were rejected.
- WPML has `fr` installed in `active_languages` but blocked in `hidden_languages`. `fr` generated zero suggestions. An isolated test also hid `es`: it instantly prevented Spanish suggestions, suppressed an otherwise approved `US+es` record from the read Ability and seasonal resolver, and did not alter any stored approvals.
- The operator can approve `US+es` without approving `ES+es` or `US+en`; a second explicit approval can create `US+en`.
- Read-only Market Profiles Ability registered and returned the approved record during the isolated acceptance test.
- One simulated legacy approved record imported only as draft, then test data was deleted.
- No markets were preapproved; tests removed all temporary records.
- `offers_enabled=false`, `seasonal_templates_enabled=false`, no Staging or Production deployment.

## Release gates

Before promotion into a certified MAD4B package: run repository CI, govern the new Ability's MCP catalog visibility and limits, certify production-grade append-only approval auditing/step-up permissions (the current site-local bounded option log is not append-only), run multisite and concurrency tests, and reconfirm package provenance/runtime manifest. Confirm production or Staging write authority explicitly and do not infer approval from Lab tests.

## WPML runtime and personalized cache acceptance

- The WordPress default locale is **not** a fallback for a missing, disabled or hidden WPML current page language. When WPML is active, the consumer must resolve a visible page language explicitly; otherwise the seasonal match is empty. This prevents a hidden Spanish page from accidentally inheriting an English market offer.
- The ETG seasonal integration's `market_profile_id` and runtime `use_case` selectors create audience-dependent output. When seasonal templates are enabled and such a rule is configured, the site-level `DONOTCACHEPAGE` guard must be active to avoid sharing a rendered personalized Top Bar between customers. This is a local WP page-cache defense, **not** a certification of external CDN or edge cache behavior.
- Domestic rules with an approved Market Profile reference do not need a second manually entered country; a matching Site-bound profile supplies market eligibility. Destination, visitor location, and traveler market remain separate concepts.
- Local tests on 2026-10-24: hidden-language and unhide regression suite 14/14 PASS (`tests/market-profiles-language-policy-runtime.php`), market-conditioned page cache guard PASS, homepage HTTP 200 with unchanged search markup. Active/Upcoming/Fallback visual acceptance in an actual WPML browser session remains separate and not yet certified.
