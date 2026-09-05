# ETG Dynamic Filter SEO Bridge

Operational Alpha `0.4.0-alpha.7` is a governed Surface Profile Engine for JetSmartFilters + JetEngine filtered archives with WPML, Elementor and Rank Math. The default ETG Tours profile is preserved, but the runtime can now govern additional WordPress Post Types and taxonomies through configuration rather than PHP changes. Vendor source is never edited.

## Safety model
The global bridge defaults **OFF**. New profiles also default **disabled**. Discovery and generated blueprints are non-authorizing. A profile becomes operational only after explicit archive, route, taxonomy-shape, combination/content/result authorities are configured and the global/profile switches are enabled.

Controlled growth is:

```text
discover → build disabled blueprint → configure exact authorities → simulate → readiness → runtime evidence → explicit enable
```

It is never:

```text
discover → auto-enable → auto-index
```

## Admin operations UI (`0.4.0-alpha.7`)

The WordPress admin surface is split into focused tabs instead of one long page:

- Overview
- Configuration
- Discovery
- Runtime Inventory
- Reconciliation
- URL Inspector
- Scenario Lab

The Global bridge state, readiness, configuration revision and profile count remain visible across tabs. Every configuration selector and operational action button has an inline `?` explainer describing its role, authority boundary and possible Production effect. Discovery, Runtime Inventory, Reconciliation and URL inspection are visibly marked read-only; Scenario Lab is visibly marked synthetic.

Alpha7 also aligns the migrated Tours default taxonomy slug with the Production-observed `tour-styles_jet`. It does not auto-add any other discovered Production taxonomy and does not infer Query Builder/Post Type authority from discovery alone.

## Surface Profiles
`profiles_json` is the authoritative bounded registry. A profile may define:

- `post_types[]`
- `require_post_type_binding`
- `post_type_authority`: `query_builder|main_query|either|both`
- exact `archive_paths[]`
- exact `routes[]` of `{provider, query_id}`
- `taxonomy_rules{}` with roles, priorities, thresholds, field maps and optional term-meta constraints
- `allowed_taxonomy_sets[]`
- exact language/profile-aware `indexable_combinations[]`
- per-depth minimum result thresholds
- profile-specific content readiness and canonical mode
- `travel|generic` composition mode

New non-legacy profiles require exact archive paths and exact route pairs. Independent provider/query arrays are retained only as legacy configuration input and do not synthesize route, taxonomy-set or exact-combination authority. Structural authority must be explicit in the matched profile.

## Pre-Staging exact authority hardening (`0.4.0-alpha.5`)

Alpha5 closes the remaining repository-level authority ambiguities found after Runtime Inventory v2:

- global/default inheritance cannot generate Cartesian `provider × query_id` route authority;
- profile output from extension filters is bounded fail-visibly, and an overflowed registry cannot authorize a route;
- registry validation is order-independent, so normalization failures are visible on the first readiness/resolution path;
- Query Builder inventory ordering uses structural identity as the final deterministic tie-breaker;
- the legacy `index_single_tour_type` flag may migrate the taxonomy rule policy only; it cannot create an `allowed_taxonomy_sets` grant;
- exact taxonomy-set authority remains explicit profile configuration;
- Runtime Inventory and reconciliation remain read-only and non-authorizing.

These rules are repository/CI hardening only. They do not count as runtime acceptance evidence.

## Runtime evidence availability hardening (`0.4.0-alpha.6`)

Alpha6 prevents an unavailable evidence source from being mistaken for a trustworthy empty runtime:

- normal eligible evidence remains `etg.dfsb.runtime-inventory.v2` and must declare `evidence_complete=true`;
- Post Type, taxonomy, WPML language, Query Builder and translated-archive sources expose `available/source` evidence;
- any mandatory unavailable/invalid source produces `etg.dfsb.runtime-inventory-unavailable.v1`, `evidence_complete=false`, and explicit `availability_errors`;
- a valid empty Query Builder array remains distinguishable from an unavailable Query Builder source;
- an unavailable/empty WPML active-language source is blocking;
- language-specific archive paths are emitted only from a valid `wpml_permalink` authority; the current/native path is never duplicated as fake translated evidence;
- reconciliation rejects unavailable snapshots as `invalid_inventory`, with no disabled candidates or drift/removal conclusions from that partial evidence.

An unavailable snapshot is useful diagnostic evidence, but it never grants authority.

## Post Type authority
For a profile with `require_post_type_binding=true`, the safe default is `post_type_authority=query_builder`.

The bridge resolves the exact JetEngine Query Builder object selected by the configured custom query ID, verifies it is a `posts` query, calls its public `get_query_args()`, and reads the final `post_type` authority. The following fail closed:

- query missing or not a Posts Query;
- `post_type=any` / unbounded post type;
- no observable Post Type;
- any observed Post Type outside the profile allowlist;
- authority disagreement when `both` is explicitly selected.

This avoids using the main Elementor page query (`page`) as if it were the Listing Post Type (`property`, `product`, etc.).

## Taxonomy policy for any configured taxonomy
A taxonomy is only parser knowledge until it belongs to the matched profile. Each taxonomy rule can set:

```json
{
  "role": "city",
  "priority": 10,
  "gallery_priority": 20,
  "index_single": true,
  "min_results": 5,
  "required_meta_key": "market_status",
  "required_meta_values": ["active"],
  "meta_constraint_scope": "always",
  "field_map": {
    "seo_title": ["property_seo_title"],
    "meta_description": ["property_meta_description"],
    "short_description": ["listing_intro"],
    "image": ["hero_image"],
    "gallery": ["gallery_primary", "gallery_secondary"]
  }
}
```

Configured gallery fields are aggregated, normalized to attachment IDs and deduplicated. A profile-specific field map is prepended to the safe built-in fallback field map, so a new taxonomy can use its own existing meta/ACF keys without a PHP patch.

## Structural and exact combination authority
Knowing every taxonomy is still not enough. The normalized taxonomy set must be explicitly allowed, for example:

```text
property_city
property_city+property_type
property_city+property_type+property_feature
```

Where exact combination approval is required, signatures are profile- and language-bound:

```text
properties|en|property_city=cairo|property_type=apartment
catalog|en|brand=sony|product_cat=cameras
```

No wildcard auto-approval exists in this Alpha.

## Result-count authority
Indexing still requires an authoritative filtered result count by default. The built-in adapter mirrors the verified JetEngine Query Builder + JetSmartFilters lifecycle: custom query ID resolution, current filtered request props applied to a cloned query, then `get_items_total_count()`.

If filtered request state is unavailable, the bridge returns unavailable rather than substituting an unfiltered count. Legacy numeric `etg_filter_seo_result_count` remains non-authoritative unless explicitly trusted.

## Content readiness
Content readiness uses a deduplicated corpus of term descriptions/short descriptions, so the same text is not counted twice through both generated intro and term data. The content-readiness hook may veto an otherwise-ready result, but cannot promote a base content failure into ready.

## Multilingual archive authority
Archive path normalization is Unicode-safe. Exact profile paths may be explicitly translated, for example `/ar/كتب/`, and base paths may be wrapped by a known active WPML language prefix without allowing arbitrary suffix collisions. `/foo/properties/` does not impersonate `/properties/`.

## Discovery and disabled blueprints
Settings → ETG Filter SEO → Discovery exposes read-only public Post Type/taxonomy discovery. It can build a **disabled, non-authorizing Profile Blueprint** for a discovered Post Type and attached taxonomies. The blueprint intentionally leaves archive paths, routes, taxonomy sets and exact combinations empty so it cannot become indexable by generation alone.

## Synthetic Scenario Lab
The Scenario Lab exercises the real IndexingPolicy with bounded synthetic inputs for objections such as:

- wrong query from another profile;
- foreign taxonomy bleed;
- Post Type mismatch/unobserved state;
- taxonomy-meta state changes;
- zero results;
- translation fallback;
- unknown functional query state;
- profile disable/kill-switch behavior.

Every output is marked `synthetic=true`. Simulation is never runtime acceptance evidence.

## Presentation identity guard
Rank Math metadata and Elementor-facing shortcodes only mutate/render after structural identity is valid: scope/profile, provider/query observation, required Post Type binding, term resolution and WPML translation identity. A wrong Listing identity therefore cannot receive metadata from another profile even though robots would have failed closed.

## Generic presentation
`generic` composition orders arbitrary roles by configured taxonomy priority. Gallery priority is independently configurable. Breadcrumb output uses the same profile order; it no longer assumes `location/tour_type/style`. `travel` mode preserves current ETG composition behavior.

## Operational bounds
Alpha bounds are deliberate and fail visibly rather than silently expanding authority: up to 50 profiles, 20 exact routes per profile, 50 taxonomy rules per profile and 5,000 exact combinations per profile. Invalid profile JSON preserves the previous valid authority snapshot. Post-filter profile overflow is also blocking and cannot be used for route resolution.

## Shortcodes
```text
[etg_filter_h1]
[etg_filter_intro]
[etg_filter_sections]
[etg_filter_gallery]
[etg_filter_gallery mode="priority" limit="8" size="large"]
[etg_filter_keyword]
[etg_filter_breadcrumb_context]
```

## Verified repository bundle
- JetSmartFilters 3.8.3.1
- JetEngine 3.8.11.2
- Rank Math SEO 1.0.275
- Rank Math SEO PRO 3.0.118
- WPML Multilingual CMS 4.9.6
- WPML SEO 2.2.5
- WPML String Translation 3.5.3

The dedicated CI re-checks exact vendor capability surfaces, including Query Builder custom-ID mapping, filtered count APIs and Posts Query `get_query_type()/get_query_args()` surfaces.

## Operational boundary
Persistent cross-request cache, automatic profile generation, traffic-driven combination approval, sitemap generation, clean URL migration and Production activation remain outside this Alpha. See `specs/001-etg-dynamic-filter-seo-operational/` for the contracts, objection matrix and acceptance gates.

## Runtime Inventory Export (`0.4.0-alpha.6`)

Settings → ETG Filter SEO → Runtime Inventory can generate or download a bounded runtime inventory JSON snapshot. A valid snapshot uses `etg.dfsb.runtime-inventory.v2`, is read-only and explicitly `authorizing=false`, and inventories public Post Types, taxonomy attachment relations, WPML active-language paths, and structural JetEngine Query Builder identities without exporting raw query arguments or enabling profiles.

An eligible snapshot must also have `evidence_complete=true`, `availability_errors=[]`, and every `inventory.availability.*.available=true`. If any mandatory source is unavailable, the exporter returns `etg.dfsb.runtime-inventory-unavailable.v1`; that partial snapshot must be investigated and recaptured rather than interpreted as zero runtime objects.

### Inventory completeness and identity safety

Runtime Inventory v2 declares whether each bounded section is complete. A section with `truncated=true` is blocking reconciliation evidence and cannot be used to assert that a configured Post Type, taxonomy, language or Query Builder route is missing. Duplicate effective Query Builder identities are reported as collisions and are excluded from exact route resolution. Disabled candidate generation is suppressed whenever inventory is truncated, Query Builder inventory is unavailable, identity collisions exist, or the exporter cannot establish complete mandatory source availability.

## Inventory Reconciliation & Controlled Growth (`0.4.0-alpha.6`)

Settings → ETG Filter SEO → Reconciliation can compare the current bounded runtime inventory against configured Profiles and download `etg.dfsb.inventory-reconciliation.v2` JSON. Reconciliation is read-only and never enables or rewrites Profiles. Newly discovered Post Types can appear as disabled candidates only from a valid complete runtime contract; exact archive paths, routes, allowed taxonomy sets and combinations remain empty until an operator explicitly configures them.

WP-CLI equivalents:

```bash
wp etg-dfsb inventory > runtime-inventory.json
wp etg-dfsb reconcile --previous=runtime-inventory.previous.json > reconciliation.json
```

A `blocked_drift` or `invalid_inventory` result is evidence requiring review; it does not perform automatic rollback or mutation.
