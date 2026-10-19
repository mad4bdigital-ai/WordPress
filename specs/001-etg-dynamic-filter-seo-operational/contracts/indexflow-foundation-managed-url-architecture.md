# IndexFlow Managed URL Architecture

Status: **source foundation and draft planning; runtime publication unavailable**.
This extension stays in PR #435. It neither changes the existing native filtering
engine nor grants its existing Profile/Slot writer permission to publish routes.

## Implemented source contracts

`NormalizedFilterState` validates real term IDs through the WordPress/WPML adapter.
Its identity includes Profile, case-preserving Provider Query ID, provider and
sorted default-language semantic term IDs. Locale and localized IDs remain in
the normalized state but do not split translation-group identity. Unknown state
fields and unsupported cardinality fail closed. The first schema supports one
term per dimension with AND semantics; it does not silently flatten OR filters.

`ManagedUrlArchitecture` implements normalize, build, resolve, lookup,
canonicalize, verify and planMigration. Its bounded document contains at most
100 Profiles and 500 route records. No document is stored by these contracts.
Draft and Approved records can be previewed; only Published records resolve.
Retired records are history, never live canonical or hreflang candidates.
One active semantic identity and locale has one record, including native versus
virtual sources. Duplicate URLs, native owners, missing translations, unknown
dimensions and ambiguous term slugs deny the document.

Native landing sources use an existing published, viewable Page/CPT permalink.
Virtual sources reference an existing translated published template Page/CPT.
They use locale archive bases plus ordered **dimension-name/real-term-slug**
segments. Including dimension names keeps omitted optional dimensions explicit.
Translated term slugs come from WordPress/WPML; settings contain no slug map.
All URLs must use the current origin. WPML language domains on different origins
are deliberately rejected until an independently reviewed origin policy exists.

`NativeFilterUrlAdapter` delegates native parsing to the existing FilterUrlParser.
An optional source-owned alias adapter must prove reversible vendor alias
conversion; there is no duplicated alias replacement engine. The planning screen
does not guess an alias adapter. Unavailable aliases fail when used. WPML term
lookup reuses the existing language-context adapter with finally restoration.

`WordPressRouteResolver` projects exact registered virtual paths to WordPress
rewrite rules and the approved native template. It adds the query variable to a
provided variable list, binds URI and route ID, and supports WordPress subdirectory
paths. It does **not** register hooks, flush rewrite rules, redirect requests or
alter the query. These projections are plans pending runtime integration.

`SeoOwnershipResolver` requires independently observed active output ownership
for canonical, robots, sitemap and hreflang. Rank Math cannot own output while
its frontend integration is disabled. A matching JSF rule conflicts with a Rank
Math claim. Unmanaged routes remain native; neither provider is globally disabled.

`verify` compares complete actual result-ID sets, HTTP 200, URL/filter/widget
state and language for Direct, AJAX, Mixed, Reload, Back and Forward. It also
requires one canonical, consistent ownership/robots/sitemap/hreflang, unknown
route HTTP 404 and a matching document revision. A fixture or observation pass
is explicitly **not** a runtime certificate or execution permission.

`planMigration` compares published identities per locale and proposed URLs, plus
Profile schema changes. Slug/base/order changes create a migration candidate.
Redirects remain unauthorized until complete result-ID equivalence and independent
approval. No 301 is installed by a plan.

## Admin access

Settings → IndexFlow URL Architecture, also linked from the ETG product navigation.
The existing ETG menu structure lives under WordPress Settings; this extension
does not create a competing ETG top-level menu. Global document version/origin,
Profile strategy/bases/dimensions and route publication/source/owner are separated
in the document. The administrator can validate a bounded draft and compare a
previous document. POST is capability- and nonce-checked, escaped and memory-only;
opening the page performs no route evaluation or persistent writes.

Minimal document shape (IDs below must be replaced with real translated objects):

```json
{
  "version": 1,
  "profiles": {
    "tours": {
      "strategy": "published_seo",
      "provider": "jet-engine",
      "query_id": "Tours_01",
      "bases": {"en": "/tours/"},
      "dimensions": [{"name": "location", "taxonomy": "location", "required": true}]
    }
  },
  "routes": [{
    "id": "cairo-en",
    "source": "virtual",
    "status": "draft",
    "object_id": 100,
    "seo_owner": "rank_math",
    "state": {
      "profile_id": "tours", "provider": "jet-engine", "query_id": "Tours_01",
      "locale": "en", "filters": {"location": [1]}
    }
  }]
}
```

## Unimplemented execution work — must not be represented as accepted

1. Persisting the route registry and publication-state transitions through a new
   independently certified MAD4B mutation scope, with exact revision, shared
   option lock, native readback, before-state journal and central mutation-undo.
   The current ETG Profile/Slot writer intentionally cannot perform this work.
2. Registering rewrite/query-var hooks after authority checks, feeding normalized
   state into actual JetSmartFilters widgets/provider initial queries, integrating
   AJAX/Mixed URL updates and browser history using the vendor runtime lifecycle.
   A rewrite plan alone does not make a virtual template filter correctly.
3. Applying scoped SEO ownership and integrating canonical, robots, sitemap and
   WPML alternates with actual provider hooks, including matched/unmatched rules.
4. Genuine disposable-WordPress/vendor integration followed by exact-build Staging
   browser acceptance and independent source-bound publication authorization.

Until these exist, the only boot integration is the **draft planning page**.
There is no route activation switch or Apply endpoint. The feature must not be
marketed as finished managed routing, and no published route is deployed here.

## Sources and verification

- WordPress add_rewrite_rule: https://developer.wordpress.org/reference/functions/add_rewrite_rule/
- WordPress query_vars: https://developer.wordpress.org/reference/hooks/query_vars/
- WPML wpml_object_id: https://wpml.org/wpml-hook/wpml_object_id/
- Bundled `jet-smart-filters.zip`: `includes/compatibility/rank-math-seo.php`
  attaches `rank_math/frontend/disable_integration` after JSF SEO init-rule.
- Bundled `includes/url-aliases.php` uses reversible replacement; it is not a
  publication registry or semantic routing engine.

Run the two `indexflow-*-url/route` PHP smoke suites from the Foundation CI matrix.
They test URL laws, multilingual semantic identity, native-parser parity,
duplicate ownership/path rejection, lifecycle exclusions, migration planning,
result-ID parity, widget/history state and missing SEO ownership evidence.
