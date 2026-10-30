# JetEngine + JetSuite Provider Capability Audit BEFORE Add-on Expansion
**Audit date:** 2026-10-30 · **Environment:** tours.dev.mad4b.com, read-only inspection · **Target PR:** #631  
**Decision:** NO expansion to provider-agnostic discovery, query federation, form sharing, or AI-controlled activation until provider capabilities, ownership, selection contracts and runtime semantics are certified.

## Observed installed provider versions (Staging)
- JetEngine **3.8.15.2**, JetSmartFilters **3.8.5.1**, JetWooBuilder **2.3.3**, JetSearch **3.6.3.1**, JetTabs **2.3.3.2**, JetElements **2.9.2.2**, JetBlocks **1.5.2.1**, JetBlog **2.4.10.1**.
- Elementor **4.3.5**, Elementor Pro **4.3.2**, WooCommerce **11.2.1**.
- Fluent Forms / Pro **6.2.16**: independent forms provider, **10** published form definitions, not a JetEngine Query type.
- JetFormBuilder **not active**; 10 historical `jet-form-builder` posts exist but must not be assumed usable. JetEngine legacy booking forms module enabled with 5 stored `jet-engine-booking` posts. JetWooBuilder is a WooCommerce presentation/template plugin, **not a universal forms engine**.
- JetEngine active modules: `gallery-grid`, `gallery-slider`, `booking-forms`, `custom-content-types`, `profile-builder`, `dynamic-visibility`, `listing-injections`, `data-stores`, `calendar`, `rest-api-listings`, `qr-code`, `maps-listings` plus auxiliary modules. JetEngine REST API Listings module is enabled.

## Real registry inventory — source-backed, no secret/PII extraction
Read `wp_jet_post_types` (JetEngine canonical registry, **not** the similarly-named WordPress post type) using `status` and fields `id, slug, labels, args, meta_fields`. Consult actual `post_type` registration separately.

| Registry | Count | Notes |
| --- | ---: | --- |
| JetEngine Query Builder queries | **203** | 94 Posts, 67 Terms, 38 Repeater, 3 Users, 1 Merged; **0 REST API Query**, 0 SQL, 0 WC query stored |
| JetSmartFilters objects | **185** | 123 checkboxes, 49 selects, 6 ranges, 3 date-range, 2 searches, 2 color/image |
| Elementor library records | **470** | Templates/layouts, source and display conditions must be checked separately |
| JetEngine post type `jet-engine` records | **332** | Configuration/listing records; do not assert all are published usable Listings |
| JetEngine Options Pages definitions | **11** | Includes `look-and-feel` (54 fields), `contact-and-help-info` (16), `calculate-booking-price` (2), service/global offers/company operations |
| JetEngine Relations definitions | **44** | Read-only structure discovery possible |
| JetEngine Glossaries definitions | **20** | Reusable choices need company ownership policy |
| Fluent Forms definitions | **10** | Independent provider API/permissions; submissions and notification secrets excluded |
| JetWooBuilder `jet-woo-builder` posts | **2** | WooCommerce template provider, not standalone general Form provider |

Relevant existing query IDs: `193` `featured_home_tours_query` (Posts), `254` children of Nile cruise tour types (Terms), `255` Nile cruise tour posts (Posts), `269` Nile cruise tour rates category (Terms), `308` cruise runs (Repeater), `104` cruise type tours (Posts), `22` featured tours (Posts). Existing native Query/Filter display should not be replaced by a global shortcut. The installed homepage filter uses a native Posts query, not remote REST.

## Native capabilities vs what MAD4B must add

| Surface / requested selection | JetEngine/JetSuite native capability | Missing cross-company capability / required guard |
| --- | --- | --- |
| Specific tours, posts, pages, CPTs | Posts Query supports include/exclude, taxonomy and meta conditions, dates and post statuses; Listings consume queried items | Source-company-qualified IDs; source grants; catalog projection; remote ID mapping; selective allowlist; conflict/dedupe |
| Specific categories, parent/child | Terms Query, taxonomy metadata, relation/query filters | Verified WPML source→canonical term mappings; no cross-site raw term IDs; inherited hierarchy |
| Languages | WPML integration and language-aware WordPress terms/posts | Source/consumer locale agreement, translation group certification, no automatic locale fallback |
| Queries | Native Posts/Terms/Users/Comments/Repeater/Current WP/SQL/REST/CCT/WC/Relations/Merged/etc, availability depends on modules | A versioned `QueryPlan` for source/target, operators, pagination, sort, caches, filter bindings, ownership, billing/PII guards. No unsupported arbitrary join |
| REST API Listings & REST API Query | Enabled; endpoints, response-path, query args, Listing Grid; Crocoblock documents JetSmartFilters Query macro for REST Query | Signed server-side Control Plane endpoint grants, provider-bound query IDs and secure headers, normalized paging, no secret stored in Elementor |
| Templates & Listings | Existing Elementor Listing Grid and JetEngine layouts, provider conditions | Select exact template/Listing IDs per company and locale; rebind only approved slots; no side-effectful duplicate |
| Meta fields, repeaters, options pages | JetEngine meta field registration and controlled REST exposure, dynamic content and options pages | Schema discovery only by default; selected fields projected via allowlist; no shared values, brand/media/credentials stay tenant-local |
| JetSmartFilters | Existing tax/meta/query filter providers, Query ID and native REST query integrations with proper macros | Filter→Query/Listing graph, remote category mapping, pagination/count correctness, preserve original filters |
| JetWooBuilder/Woo | Product query, product/category widgets, shop/cart/checkout/account templates | Woo product identity and sales authority independent; no historical prices injected into add-to-cart/checkout |
| JetEngine legacy forms / JetFormBuilder | JetEngine booking forms module enabled; JetFormBuilder is currently NOT active | Separate form-provider capability registration; explicit schema and actions review; no submission/customer data sharing by default |
| Fluent Forms / Pro | Own form definitions, hooks, Pro webhooks, embed and integrations | Explicit Fluent provider adapter for schema/visibility/actions, field-level PII/notification security; not a JetEngine-native Query |
| Future providers | WordPress and JetEngine expose registries/actions for extensibility | Capability handshake + read-only typed discovery, per-provider adapter and signed approval, not assume every installed plugin supports a common schema |
| Inheritance / AI | JetEngine has dynamic visibility, relations, macros and optional AI for SQL Query | Tenant-scoped governance inheritance, drift/evidence, explainable mapping suggestions only. AI has no automatic permission to mutate config, show PII or enable pricing |

## Critical REST Query and merge constraints

1. In the installed JetEngine `includes/modules/rest-api-listings/inc/query-builder/query.php`, `get_current_items_page()` returns **1**, `get_items_per_page()` returns **0**, and `get_items_pages_count()` returns **1**. Do not claim reliable native server pagination/facets over a large remote catalog without custom gateway/protocol testing.
2. The module first fetches items, and response/filter hooks act on that request. Its item count defaults to the length of the returned item array. Cap payload size, use signed server-side filtering and independent total-count semantics. Verify cache partitioning by company, language, policy version, filtered arguments and identity.
3. JetEngine Merged Query is limited to matching supported types (Posts/Terms/Users/Comments per Crocoblock documentation); **not** generic union of REST Query with native Posts, Terms, Woo/CCT or Fluent records. Treat heterogeneous federation as an explicitly different service.
4. Native JetSmartFilters → REST Query has a supported documented path via **JetSmartFilters Query** macro; reusing a Posts Query filter variable with a REST endpoint without rebinding is not equivalent.
5. JetEngine can register REST endpoints for selected queries and expose selected meta fields/options; this should NEVER mean all tenant-private fields/pages are automatically public. Query definition and execution grants must be separate. All routes require company auth and scope.
6. SQL/AI Query can generate SQL for a site's database, **not** infer arbitrary cross-provider join semantics or owner consent. No free-form LLM SQL on source company databases.

Documentation:  
- https://crocoblock.com/knowledge-base/features/query-builder-overview/  
- https://crocoblock.com/knowledge-base/jetengine/jetengine-query-builder-rest-api-query-type/  
- https://crocoblock.com/knowledge-base/jetengine/filtering-listing-based-on-rest-api-query-type/  
- https://crocoblock.com/knowledge-base/jetengine/query-builder-merged-query-overview/  
- https://crocoblock.com/knowledge-base/features/rest-api-overview/  
- https://crocoblock.com/knowledge-base/jetwoobuilder/jetwoobuilder-overview/  
- https://fluentforms.com/docs/how-to-integrate-webhook-with-fluent-forms/

## Proposed dynamic discovery + query contract (DESIGN ONLY)

`ProviderCapabilityDescriptor` is a versioned, server-side read-only document:
```
provider_id, plugin_slug, detected_version, enabled_modules,
content_types[], taxonomies[], language_providers[],
query_types[], query_instances[], filter_types[], filter_instances[],
listing_templates[], template_slots[], meta_field_definitions[],
options_page_definitions[], form_definitions[], relation_definitions[],
supported_filters/operators[], pagination_capabilities,
provider_auth_types[], read/write/execute_capabilities, schema_version,
sensitivity_labels, tenant_scope, evidence_digest, last_scanned_at
```
Discovery via scoped native registries: `get_post_types()`, `get_taxonomies()`, JetEngine `wp_jet_post_types` definitions (`status=query/page/relation/glossary`), JetSmartFilters post definitions, Elementor template types/widgets, and explicitly integrated plugin registries (Fluent, Woo, JetFormBuilder when active). NEVER auto-read secret option values, all form submissions, unknown media stores, plugin private settings, external credentials or database rows just because they exist.

`SelectionManifest`: source_company, exact source_site_uuid, consumer_site_uuid, requested provider/type, **include/exclude record IDs**, allowed locales and translation groups, allowed canonical category IDs and children, field projections (including meta/repeaters), selected Options Page fields, exact Listing/template IDs and slot, exact Query ID/type, filter IDs/query variables, sorting, pagination/count strategy, effective cache TTL, read-only data policy, and source owner approval.

`QueryPlan`: execution engine = one of native Posts, Terms, Repeater, REST API, Woo, CCT, Relations, Fluent-adapter or explicitly separate federation gateway; include `provenance`, `source_version`, `filters`, `locale`, `filter_to_query_binding`, `listing_target`, `row_limit`, `total_count_contract`, `identity_key=(company,provider,source_object_id,locale,version)`, `deny_fields`, `evidence_digest`. A plan cannot silently switch engine/provider.

**Inheritance policy (most restrictive wins)**: immutable platform denials > source owner's release/privacy/commerce policy > consumer tenant rules > provider capability > content type > locale/taxonomy > selected query/listing/template slot > operator refinements. Children can narrow publication and fields, not override a parental deny. Shared layout/schema can be reused; **saved Options Page values, logos, account bindings and customer data are never inherited across tenants**. Conflicts, missing mappings, unsupported filters or drift return `review_required`/deny, not an empty-listing success.

**Assisted intelligence**: recommend candidate mappings of source/target schema, records/terms/templates/filters using name, field type, WPML group, usage graph, validated examples and prior approved decisions. Emit evidence and uncertainty, a dry-run impact graph and a reversible change proposal. The model is not an authority source, does not invent IDs or activate write/sale/submission permissions, and never learns from secrets/PII by default. Approval and signed server evidence are prerequisites for any future write.

## Required acceptance gates BEFORE implementing general Add-on expansion
- Discover installed capabilities **without** mutating WordPress and distinguish inactive historical JetFormBuilder records from an active forms provider.
- Prove include/exclude of exact source tours + locales + taxonomies, without copying DB or cross-tenant values.
- Discover and verify all selected Query IDs, Query types, Listing/template slots and JetSmartFilters variables; preserve old consumers.
- Test REST 400/401/403/404, wrong owner, wrong language/term group, conflicting source ID, unexpected schema, PII-bearing form fields, stale source, pagination, zero results, cache invalidation and provider removal.
- Prove no JetWoo checkout or archived rate activation, and no Fluent Form submission/event export without separate permission.
- Run on an isolated candidate and Staging, obtain exact-head GitHub CI/owner authorization; Production unchanged.

**Decision:** The current `mad4b-jetengine-hub-addon` covers only source-to-canonical WPML mapping for the Tourism Nile test fixture, JetEngine REST query-args hook, allowlisted response projection and Control Plane gating. It is *not* a generalized dynamic provider explorer, selection orchestrator, inherited policy engine or AI workflow. Those are future milestones, not completed behavior.