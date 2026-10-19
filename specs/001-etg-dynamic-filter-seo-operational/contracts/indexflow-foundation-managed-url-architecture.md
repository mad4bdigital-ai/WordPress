# IndexFlow Managed URL Architecture

Status: **Staging source candidate: governed storage and gated vendor/browser integration; not live certified**.
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
The source-owned `VendorUrlAliases` adapter delegates forward/reverse conversion
to the actual vendor object, restoring request globals and the canonical redirect
hook after reverse inspection. There is no copied alias rule engine. Unavailable
aliases fail when used. WPML term
lookup reuses the existing language-context adapter with finally restoration.

`WordPressRouteResolver` projects exact registered virtual paths to WordPress
rewrite rules and the approved native template. It adds the query variable to a
provided variable list, binds URI and route ID, and supports WordPress subdirectory
paths. The resolver itself is a pure projection. `ManagedRoutesRuntime` registers exact
WordPress rewrite/query-var hooks only after its separate runtime Provider passes
certification and Global is ON in Staging. A registered-path fallback handles old
rewrite caches without a request-time flush. Reserved-query guards remain
registered during Safe Boot/Global OFF so orphaned rules return 404; explicit
plugin deactivation invalidates only the derived WordPress rewrite cache. The native `jsf` query variable is
provided at parse_request priority 1, before the actual vendor render callback at
priority 10. FilterContextBuilder uses the same technical state through the
existing parser, including exact Profile/provider/query/taxonomy authorization.

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
previous document. The preview also derives the governed storage plan from the
actual stored before-state. POST is capability- and nonce-checked, escaped and memory-only;
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

## Execution boundaries and acceptance gates

1. Storage is implemented under the **separate** `indexflow-routes` Provider:
   `indexflow/routes-plan`, `indexflow/routes-apply`, `indexflow/routes-verify`,
   central `mad4b/mutation-undo`. Apply is Staging/admin/Global-OFF only; exact
   before-state/document/dependency/migration fingerprints bind approval. Snapshots
   and readback use bounded authoritative DB reads, not potentially stale option
   caches; mutations invalidate only the relevant WordPress option-cache keys.
   The shared option lock spans snapshot, journal, apply and readback. Undo restores
   original bytes or absence and rejects after-state drift, without demanding
   that now-removed term dependencies still validate. Provider certification,
   NHI grants, owner confirmation and live Apply→Verify→Undo remain independent.
2. Runtime needs a **different**, independently certified Provider key,
   `indexflow-route-runtime`. Storage certification cannot activate routing.
   No certificate for either key is created by this PR. Runtime also requires
   Staging, Global ON, an enabled matching ETG Profile, Rank Math available and
   JSF permalink mode, filtered canonical policy and virtual-route metadata authority.
   The initial runtime refuses active vendor URL Aliases and
   JSF-owned SEO routes. Native/Aliased URL contracts remain available for planning;
   mixed alias/publication runtime needs separate compatibility acceptance.
3. Canonical, sitemap and hreflang mapping reuses existing ETG publication output
   and **retains its indexing/content/provider/count eligibility gates**. A route
   record alone does not add a sitemap entry. On managed Rank Math requests only,
   JSF's exact competing title/description/canonical callbacks are removed and its
   Rank Math compatibility toggle is scoped to the same registered identity.
   Unmanaged requests keep native ownership. Unexpected third-party SEO hooks must
   still fail independent ownership acceptance; this is not a universal SEO fix.
4. The source-owned browser script subscribes to actual JSF initialization and
   AJAX-updated events and its reload-location hook. It uses the vendor's full
   state URL, never recomputes a filtering query. Only exact approved combinations
   map to published URLs; pagination, extra dimensions and unknown combinations
   stay native. History entries are restored after the vendor's replaceState,
   then pushed for real transitions. Back/Forward reloads through WordPress so
   provider/widget state is restored by the same engine. This first version
   deliberately uses full reload on history navigation, not a second AJAX engine.
5. Genuine disposable WordPress + full provider integration, six-language WPML
   browser acceptance, active SEO-hook observation and independently authorized
   exact-build Staging acceptance remain required. Source fixtures are not a
   runtime certificate. Cross-origin WPML language domains remain unsupported.

There is no admin POST shortcut, automatic activation, redirect migration,
Provider certificate, new site grant, deployment or Production authorization.
The remaining acceptance work must not be reported as completed by a fixture PASS.

## Sources and verification

- WordPress add_rewrite_rule: https://developer.wordpress.org/reference/functions/add_rewrite_rule/
- WordPress query_vars: https://developer.wordpress.org/reference/hooks/query_vars/
- WPML wpml_object_id: https://wpml.org/wpml-hook/wpml_object_id/
- Bundled `jet-smart-filters.zip`: `includes/compatibility/rank-math-seo.php`
  attaches `rank_math/frontend/disable_integration` after JSF SEO init-rule.
- Bundled `includes/url-aliases.php` uses reversible replacement; it is not a
  publication registry or semantic routing engine.

Run the managed URL/environment/storage/runtime PHP suites, the actual bundled
vendor handoff Python runner and the browser JS suite in Foundation CI. Run the
separate governed-adapter behavioral contract in the MAD4B ETG Adapter workflow.
They test URL laws, multilingual semantic identity, native-parser parity,
duplicate ownership/path rejection, lifecycle exclusions, migration planning,
result-ID parity, widget/history state and missing SEO ownership evidence.
