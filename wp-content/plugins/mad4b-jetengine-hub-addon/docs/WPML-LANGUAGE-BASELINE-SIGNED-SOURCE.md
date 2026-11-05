# MAD4B Content Hub — WPML Language Baseline & Source-Signed Selective Preview

**Implemented on Staging:** 2026-10-30 · **Add-on:** `mad4b-jetengine-hub-addon` **0.4.1**  
**Contracts:** `mad4b.hub.language-inheritance.v1`, `mad4b.hub.capability-selection.v1`, `mad4b.hub.signed-source-market-preview.v1`

## Owner decision

While each company sets up its full market grants, the **minimum inherited content selection scope** is the intersection of the **visible WPML languages** at the *source* and the *consumer* company site, narrowed further by the languages selected in a given QueryPlan. **Active** does not mean **visible**: `effective_languages = (source.active_languages - source.hidden_languages) ∩ (consumer.active_languages - consumer.hidden_languages)`.

No nationality, residence, selling country, destination-country, currency or commercial channel is inferred from language. A locale such as `en` is **not** shorthand for the United States, the United Kingdom, USD, a legal sales territory or a nationality. The business policy may become narrower; it never widens a platform/source restriction.

### Observed source settings

Read via verified read-only Hostinger SSH to the companies on 2026-10-30, not fabricated from the Staging template:

| Source site | WPML enabled | Hidden | Eligible against the current Staging consumer |
| --- | --- | --- | --- |
| `tourism.almallahgroup-mg.com` | EN/ES/DE/FR/AR/IT | ES/DE/FR/IT | **EN, AR** |
| `www.allroyalegypt.com` | EN/ES/DE/FR/AR/IT | AR | **EN, ES, DE, FR, IT** |
| `egypttourgates.com` | EN/ES/DE/FR/AR/IT | AR/FR/DE | **EN, ES, IT** |

Staging consumer `tours.dev.mad4b.com` has all six active and **none hidden**. Any company consumer later uses its **own** local WPML settings; the table above is *specific to Staging*, not a universal guarantee. The source observations (public locale settings only, no credentials/user data) are stored in non-autoloaded Staging option `mad4b_hub_source_wpml_observations_v1`. Source host identity is pinned to MAD4B's central company registry. Observations expire after **seven days**. Stale, missing, altered-host, disabled or hidden languages fail closed; they need a new verified read from the source.

These source observations are **capability evidence, not a signed source content-sharing/license approval**. They cannot authorize publication, WooCommerce rates, checkout, or forms/PII sharing. No source site was modified.

## Selective Manifest usage

Existing `POST /wp-json/mad4b/v1/hub-selection/validate` supports `"inherit_language_baseline":true` when `markets` is absent. It additionally requires **explicit source record IDs**, a company ID and both site UUIDs, selected `languages`, and applicable existing Query/Listing/Filter/Taxonomy bindings and policy layers. Source record IDs cannot be omitted for a language-only preview, preventing a default bulk import.

For example, within an existing approved source/destination manifest, supply:

```json
{
  "inherit_language_baseline": true,
  "selection": {
    "source_record_ids": [24900],
    "languages": ["en"],
    "canonical_taxonomy": "tour-types",
    "canonical_category_ids": [1865],
    "query_ids": [193],
    "listing_ids": [25917],
    "filter_ids": [33503],
    "fields": ["title", "summary"]
  }
}
```

This is only an illustrative **dry-run** selection; the complete manifest must still include source/destination company UUIDs, policy layers, provider and contract. `24900` is a source record observed in the signed Tourism reference catalog, but selecting it alone is not permission to publish or sell it. `markets` and `inherit_language_baseline` cannot both be supplied. The legacy unspecified mode remains `UNSCOPED_DENY`, preserving existing clients. Future market-specific rules can narrow language visibility further. A `market_limits` restriction on nationality/market/currency etc. **forces explicit market mode**; language-only mode cannot sidestep it.

`QueryPlan` contains a `LANGUAGE_BASELINE_DRY_RUN` slice with exact source company, selected locales/records and native Query/Listing/Filter IDs, no inferred currency/country, source evidence digest, and `plan_executable=false`. No native JetEngine Query or Elementor page is changed.

## Signed source preview and publication separation

New administrator-only read-only routes:

- `GET /wp-json/mad4b/v1/hub-language-baseline/status` — effective locale intersections and evidence freshness for each company; requires `manage_options`.
- `GET /wp-json/mad4b/v1/hub-market-source/status` — status of signed source read and missing company market publication grant; requires `manage_options`.
- `POST /wp-json/mad4b/v1/hub-market-source/preview` — bounded, selectively filtered **private** Tourism Nile source preview; requires `manage_options`, exact source record selection and a validated market or language-only plan. Its source transport is the previously deployed mutual P-256 signed `MAD4B_Central_Nile_Reference_V1::fetch()`. It revalidates source response signature/owner, selected locale/source terms, and uses the preexisting canonical term mappings. Source response data is **allowlisted** before return; price, sale price, checkout, private account fields and PII are absent.

The actual Tourism signed source response explicitly asserts `may_publish=false`, `may_checkout=false`. Consequently, the public JetEngine intended route `GET /wp-json/mad4b-central/v1/hub-distribution/{company}/{catalog|categories}` is **reserved but returns 403**. A successful signed *read* is **not** a signed distribution or content-release permission.

Control Plane `admit_runtime()` now differentiates:
- **Explicit market**: trusted server-side `mad4b_scp_hub_consumer_market_authority`.
- **Language-only baseline**: trusted server-side `mad4b_scp_hub_consumer_language_authority`.
- **Both**: independent trusted `mad4b_scp_hub_consumer_signed_authority`, `mad4b_scp_hub_consumer_native_binding_authority`, and **`mad4b_scp_hub_consumer_content_release_authority`**. All hooks default to false.

No market registry or native JetEngine live consumer binding is created automatically by this change. The public feed remains blocked even with legitimate WPML language inheritance until signed source content distribution/release and native provider bindings are independently approved.

## Acceptance evidence

1. Verified actual WPML settings from all three company sites using SSH and stored their minimal nonautoloaded observation snapshot on Staging only.
2. Tested source/consumer WPML intersection, source hidden-language rejection, consumer hidden-language rejection, invalid/expired observations, ancestor read-deny and preexisting explicit market limits, unknown/default market behavior.
3. Executed **real P-256 signed private Tourism preview** of selected source record `24900` (35 total source tours across two verified source pages), with mapped local canonical term `1865`, no private fields.
4. Ran original 22 negative explicit-market tests and 13 original provider/Query selection negative tests; all passed.
5. New language REST: anonymous **401**, administrator **200**. Public content route **403**. Homepage **200**, temporary standalone catalog page **404**.
6. Internal 417 Hub records unchanged; original Elementor template intact; draft catalog pages 67883/67884 unchanged; no source or production mutation.

### Follow-up and revocation requirements

A site changing its WPML enabled/hidden languages must invalidate or refresh its source observation. Seven-day expiration enforces fail-closed freshness, but the current refresh mechanism is a *verified operator SSH read*, not a background real-time signed language endpoint. Production-ready multi-site use needs a **source-signed language capability endpoint**, immediate revocation/propagation and source content-sharing license. These are **not** implemented. Also still outstanding: full source release agreement, live signed distribution GET, real native JetEngine endpoint/Query/Listing/JetSmartFilters binding and commercial approval. Never auto-promote QA market profiles to grants.

**GitHub release control:** PR #631 remains Draft; CI/exact-head owner approval required before merging and Production.