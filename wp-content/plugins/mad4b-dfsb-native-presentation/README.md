# MAD4B DFSB Native Presentation

A normal WordPress plugin supplying **read-only** public Elementor Dynamic Tags and `dfsb_context_*` shortcodes from the already-published WordPress query, without loading Dynamic Filter SEO Bridge's SEO Full Boot or using release certificates.

## Scope
- Keeps existing dynamic tag names `dfsb-context-value` and `dfsb-context-image`.
- Keeps `[dfsb_context_value field="title"]`, `[dfsb_context_image]`, `[dfsb_context_heading]` and `[dfsb_context_editorial]`.
- Reads through the current installed DFSB `PageContextResolver` and `ContextValueResolver`, which enforce public post type, published post, recognized taxonomy, and trusted local attachment checks.
- Supports Elementor existing editor preview settings under `manage_options`. Public requests cannot select arbitrary post IDs.
- Does not intercept existing native JetEngine / JetSmartFilters widgets and queries. Elementor widgets using the original DFSB context tag names can render even when SEO Full Boot is held.
- Does not publish SEO, change Rank Math, mutate posts, or set the BootGuard option. No new REST/AJAX write route.
- Requires the original DFSB plugin for its existing safe context resolvers. Without it, values fail closed to empty output.

**This is a targeted native presentation compatibility layer, not a claim that every DFSB advanced dynamic tag, SEO publishing route, or AJAX filter integration has been enabled.** Those remain governed by the original plugin.

## Smoke test
Use `wp eval-file wp-content/plugins/mad4b-dfsb-native-presentation/tests/wp-cli-smoke.php` on a Staging WordPress process. Check published post and unresolvable post, shortcode registration, the two Elementor tags and unchanged BootGuard state.

## Installation
Install as a *separate normal WordPress plugin* using WordPress's ordinary plugin installer after source review. Do not overwrite DFSB or reuse the old DFSB signed ZIP identity. Stage-only rollout and rollback: deactivate and remove **MAD4B DFSB Native Presentation** to restore the original behavior; no database migrations or persisted settings are introduced.


## Shared dynamic context (v0.2.0)
Read-only WordPress filter: `apply_filters('mad4b/shared-context/read', array())`.
Returns the same `title`, `description`, `language`, `page_kind`, `provider`, `query_id`, and `seo_publisher` decision for each request.

**Source precedence:** published WordPress/WPML page context → *only when a matching active JetSmartFilters SEO Rule and actual filters exist*, the native JetSmartFilters evaluated SEO title/description. Provider and Query ID are taken from JetSmartFilters' native rule resolver. No guessed filters, raw URL parameter storage or arbitrary JetEngine query execution.

**Elementor:** existing `dfsb-context-value` tag uses composed title/description for public view; image remains the local attachment source.

**JetEngine:** registered listing macros `%mad4b_shared_title%`, `%mad4b_shared_description%`, `%mad4b_shared_language%`.

**Shortcodes:** `[mad4b_shared_title]`, `[mad4b_shared_description]`, and the existing `[dfsb_context_value field="title"]` / `description`. On approved filtered pages, the new shared shortcodes use native JetSmartFilters SEO selectors to receive its existing AJAX label updates; no custom AJAX transport or client-side changes to canonical/robots are performed.

**SEO publisher:** Rank Math is left completely untouched for ordinary pages. On an exact JetSmartFilters SEO Rule page, JetSmartFilters' native Rank Math compatibility owns the SEO output. The adapter never registers `rank_math/frontend/*` callbacks or prints meta/canonical tags. Filtered page indexing remains off unless the operator configures native JetSmartFilters SEO Rules. This Staging site had no configured JSF SEO Rules during initial implementation.

The context API is read-only, not an authorization channel. No private bookings, pricing records, user data, draft products or Content Hub tenant secrets enter the shared projection.

## Dynamic discovery and qualification (v0.3.0)

The old three-filter pilot is a historical snapshot, **not an allowlist**. Run the live read-only discovery pipeline on Staging:

```bash
wp mad4b jsf discover
wp mad4b jsf discover --probe
wp mad4b jsf discover --probe --format=json
```

The CLI scans all published Elementor templates/pages and their actual JetSmartFilters widgets. It resolves the Elementor SELECT2 filter-id arrays, the real JSF runtime query type/taxonomy/options, exact Provider + Query ID, languages, and inferred CPT archive route. The optional `--probe` fetches only WordPress-generated URLs on the same site and matches rendered `data-content-provider`, `data-query-id`, and `data-filter-id` before promoting a group to a **manual rule review candidate**.

Each group is classified as `candidate_for_manual_rule_review`, `display_only`, `needs_filter_mapping`, `needs_validation`, or `route_blocked` with machine-readable reasons. Candidates may contain *more than three* valid filters: the report suggests up to three based on verified widget usage; an editor still reviews combinations, multilingual titles/descriptions, and actual filtered URL SEO behavior.

A `proposed_rule` is a **proposal, not an active JetSmartFilters rule**. Scan does not write WordPress data, modify Rank Math/JSF options, authorize crawling, create sitemaps or issue certificates. Native JetSmartFilters SEO Rules must remain under the existing provider and WordPress operator controls.

**Scope limits:** CPT archive query IDs with unambiguous mapping only; conditional destination/related/term listings are display-only until a real route is explicitly established. Search, price, availability, dates, hidden taxonomies and draft filters do not become indexable SEO candidates. The HTML probe runs only on explicit CLI invocation. Review the output before turning on one native SEO Rule; do not auto-index all combinations.

## Smart Intelligence SEO Rules + administrative REST (v0.4.0)

This is **explainable rule-based intelligence**, not a remote language model. It qualifies live JetSmartFilters providers and query IDs by publicly available archive status, exact rendered provider/query/filters, published taxonomy terms with real content, taxonomy semantics and detected WPML languages. It excludes volatile prices/dates, unpublished filters, ambiguous routes and non-public taxonomies.

Recommendations receive an explainable 0–100 score and `high/medium/low` confidence. Only groups with exact live query matching, HTTP 200, stable published taxonomy terms and score ≥80 can become **qualified drafts**. It prioritizes destination, type and duration filters over unrelated / commercial filters, and limits the native proposal to at most three filters. The `proposed_native_rule` uses JetSmartFilters' real contract `{url,provider,query_id,filters,title,description}` and native `{{filter_123}}` macros; it does not inject Rank Math metadata.

### WordPress-native, administrator-only REST routes

Base: `/wp-json/mad4b/v1/seo-intelligence`. Every endpoint uses `current_user_can('manage_options')`; standard WordPress REST cookie/nonce or Application Password authentication applies. No separate signing, operational provider certificate or custom security authority.

| Method | Path | Effect |
| --- | --- | --- |
| GET | `/status` | Current SEO feature and draft count |
| GET | `/discover?probe=true` | Live read-only scoring, evidence, exclusions and native rule proposals |
| GET | `/proposals` | Saved intelligent drafts |
| POST | `/proposals/generate` | Automatically generate or refresh qualified drafts under a **separate WordPress option** |
| POST | `/proposals/{40-character-id}/stage` | Reverify the current site, then append an exact native JetSmartFilters SEO Rule **only while native SEO is OFF**; prevent duplicate or stale drafts |

The `/stage` action is administrator-initiated, not an automatic publisher. It snapshots the original native JSF rules and prevents more than 50 staged rules. It **never enables `use_seo_sitemap`**. Rule activation, crawling and translation review stay separate because this Staging site currently has 0 native SEO Rules, mixed WPML languages, and unresolved hotel/transport archive routes.

### Tests

`wp eval-file tests/smart-seo-rest-wp-cli.php` checks admin vs anonymous REST permissions, live discovery, automatic drafts and absence of native SEO changes.

`wp eval-file tests/smart-seo-stage-wp-cli.php` checks the **full native disabled-rule staging lifecycle** and guaranteed restoration of original JSF options after the test.

For a first rollout, call GET `/discover?probe=true`, then POST `/proposals/generate` as a WordPress administrator. Review draft copy in EN first and translated versions separately before manually staging or activating a native SEO Rule. The plugin does not publish sitemap/canonical/robots itself.

## Per-company MU Presentation ownership (v0.4.1)

**Do not interpret all service archive HTTP 404s as broken WordPress routes.** On the staging company, `service-activation` is the owning JetEngine Option Page, read through MU Presentation `service_channel_owner()`, `service_channel_mode()` and `service_channel_allowed()`.

- `tours`: `standalone_and_tour` → public tour archive and filter SEO can be reviewed.
- `properties`: `tour_only` → component in verified tour / destination discovery, no public standalone archive and no standalone SEO indexing.
- `transportations`: `tour_only` → component in verified tour / destination discovery, no public standalone archive and no standalone SEO indexing.

The per-company service mode is NEVER overwritten by Smart SEO. To change a company's distribution channels, configure its own `service-activation` Option Page; do not create a custom rewrite/redirect that circumvents `registry-service-public-access.php`.

The qualifier reuses the current site's `product_navigation_registry()` and `shared_presentation_registry_v2()` to resolve actual service/CPT/query ownership. `companyPolicy()` scopes the decision to the current site's hostname and blog ID. A `tour_only` service is classified `company_component_only` (not `route_blocked`), and its native SEO proposal is always null. The REST `/status` response includes `company_service_channels` for admin review.

Draft staging always requalifies the company's current policy and rejects a blocked/changed source, even when an older recommendation was saved. Automatic draft refresh preserves existing `staged_disabled` states instead of resetting them.

Run `tests/company-mu-presentation-policy-wp-cli.php` to verify the current MU policy, company scope, exact tours registry slot, intentional exclusion of component-only archives and absence of settings mutations. Public SEO remains disabled until native JSF editorial, WPML and filtered-URL checks.

## Data-driven Company SEO Registry / customizable Policies (v0.5.0)

The Site's MU Presentation `service-activation` mode, `product_navigation_registry()`, and `shared_presentation_registry_v2()` are authoritative. This plugin reads their exact current-company service, CPT, provider, query, filter, and WPML language evidence. **It never creates a duplicate MU registry or changes standalone permissions.**

Admin WordPress REST (normal WP authentication and `manage_options`):
- `GET /wp-json/mad4b/v1/seo-intelligence/registry`: the live discovered source graph with effective policy and site/blog identity.
- `GET /wp-json/mad4b/v1/seo-intelligence/policies`: current revision, defaults, and per-site overrides.
- `PUT /wp-json/mad4b/v1/seo-intelligence/policies`: validate and save policy with `{"expected_revision":"SHA256_FROM_GET","policy":{"global":{...},"sources":{"jet-engine/tours_query_archive":{...}}}}`. Optimistic revision check and WordPress option update lock. Undiscovered providers cannot be configured.
- Existing `GET /discover?probe=true`, `POST /proposals/generate`, and `POST /proposals/{id}/stage` use the new effective policy.

### Global and per-source policy

Global: `max_filters` 1–3, `max_combinations` 1–1000, `min_result_count` 1–20, `require_result_evidence`, `require_localized_copy`, `require_mu_source_binding` and locked `allow_auto_indexing=false`. Source overrides: `enabled`, `allowed_filter_ids`, `excluded_filter_ids`, `weight_overrides`, `max_filters`, `max_combinations`, and localized `templates` for `en/ar/fr/de/it/es`. Only safe placeholders `{primary}`, `{secondary}`, `{collection}`, `{site}` are accepted. Native JetSmartFilters `{{filter_ID}}` macro values are computed from actual qualified filters; input templates do not gain arbitrary execution.

**Example for the discovered tours provider:**

```json
{
  "global": {
    "max_filters": 3,
    "max_combinations": 200,
    "min_result_count": 1,
    "require_result_evidence": true,
    "require_localized_copy": true,
    "require_mu_source_binding": true,
    "allow_auto_indexing": false
  },
  "sources": {
    "jet-engine/tours_query_archive": {
      "enabled": true,
      "max_filters": 2,
      "max_combinations": 150,
      "allowed_filter_ids": [16204,15032,15035],
      "excluded_filter_ids": [24515],
      "weight_overrides": {"16204": 100},
      "templates": {
        "en": {
          "title": "{primary} {collection} | {site}",
          "description": "Explore {collection} in {primary}. Compare tours and choose your trip."
        }
      }
    }
  }
}
```

Rule generation bounds the theoretical combination count from native filter option counts before choosing up to three candidate filters. A Technical Score of 100 is **not** a Content or SEO Score: `content_score=null`, `seo_score=null`, `index_ready=false`, with explicit result-coverage and localization blockers until evidence exists. A multilingual page gets individual template overrides but **never silently claims translation was reviewed**.

Native rule staging is *still disabled-only* and uses an atomic SQL compare-and-swap on the WordPress JetSmartFilters option, rejecting duplicates, conflicting concurrent edits, or previously enabled indexing. The original native rules are preserved; this fixes the v0.4.1 line-comment omission that could drop existing rules.

**No automatic crawling or indexing is authorized by these policies.** Operator activation needs separate real filtered-URL QA, index quality, WPML, result coverage, and a manual WordPress action. No optional release signatures or external CA are needed for WordPress plugin operation.

### Policy drift reconciliation (v0.5.1)

If a site-specific policy changes while a native JetSmartFilters rule is already `staged_disabled`, `POST /proposals/generate` retains the original rule, but adds `pending_revision` with the new evidence-derived selection, rule template, and exact policy revision.

`POST /wp-json/mad4b/v1/seo-intelligence/proposals/{id}/reconcile` is a separate **administrator-initiated** action. It requires (1) the old staged rule to be present and unchanged, (2) the same URL / provider / Query ID identity, (3) the same policy revision and requalified live evidence, (4) native JetSmartFilters SEO still disabled. It replaces exactly one matching native rule by a byte-exact compare-and-swap and leaves all unrelated rules untouched. Reconciliation never enables `use_seo_sitemap`, modifies Rank Math, or certifies Content and SEO quality.

Policy changes **do not silently replace existing native rules**. They create a reviewable proposed revision. Use `GET /proposals` to inspect its `pending_revision`, then reconcile only after confirming source and expected copy. Retain a site-options backup for rollback.

## Multi-facet search intelligence and full REST lifecycle (v0.6.0)

**Do not reduce a source to one SEO filter.** The provider registry may contain destination, tour type, duration, weekday, day period, season, style and other useful facets. The system discovers every native published filter registered in `shared_presentation_registry_v2()`, and evaluates explicit combinations of up to **12** different taxonomies per candidate. The `40` limit applies only to the number of combinations in a single REST preview batch, **not** the available filters or possible search combinations. Requests for other combinations remain available on demand. Site-specific allow/exclude lists are customizable and empty allowlist means any qualified registered facet.

Sample dimensions from this Staging site:
- `tour-types:day-tours` + `destinations:cairo` + `schudle-days-of-weak:wednesday` + `day-times:morning`.
- `tour-types:nile-cruises` + `destinations:aswan-gov` + `season:winter` + `schudle-days-of-weak:sunday`.

The second example currently has zero matching published tours; the first has actual matches. A valid filter combination is **not** automatically an indexable page. The `season` taxonomy is currently nonpublic on this company site: it is discoverable and searchable but requires a company policy review before SEO publication. It is never enabled through a secret override.

### Administrator REST lifecycle

All endpoints are authenticated native WordPress REST with `manage_options`:

| Method | Route beneath `/wp-json/mad4b/v1` | Behavior |
| --- | --- | --- |
| GET | `/seo-intelligence/combinations/facets?provider=jet-engine&query_id=tours_query_archive` | Live MU registry + native JSF facets and values |
| POST | `/seo-intelligence/combinations/preview` | Up to 40 full-dimensional searches; real `WP_Query` published intersection, no writes |
| GET | `/seo-intelligence/combinations` | Saved exact combinations, revisions and statuses |
| POST | `/seo-intelligence/combinations` | Create specific draft, never publish |
| GET | `/seo-intelligence/combinations/{64-char-id}` | Current draft, evidence and optimistic revision |
| PUT | `/seo-intelligence/combinations/{id}/localize` | Editorial title/description for the candidate's specific WPML language |
| POST | `/seo-intelligence/combinations/{id}/verify` | Requery published results in selected WPML language and inspect real URL, HTTP, canonical, robots |
| POST | `/seo-intelligence/combinations/{id}/approve` | Explicit administrator approval only after results, URL and editorial checks |
| POST | `/seo-intelligence/combinations/{id}/publish` | Activate **only this approved exact filtered URL** through Rank Math's existing metadata hooks; native JetSmartFilters SEO must be disabled |
| POST | `/seo-intelligence/combinations/{id}/postpublish-qa` | Verify actual HTTP title, description, canonical and robots; automatically remove the URL mapping on failure |
| POST | `/seo-intelligence/combinations/{id}/rollback` | Remove exact-URL SEO override before changing state |

Mutation bodies after creation require `expected_revision` from the preceding response. Each candidate is stored as its own WordPress option with byte-exact concurrency protection. A separate exact-URL index prevents scanning all saved combinations during every public page request.

**SEO owner:** Rank Math publishes the title, meta description and canonical for *explicitly approved exact URLs only*. No new meta tags are printed by the adapter. JetSmartFilters continues filtering the results, JetEngine/Shared Product Core own queries, and MU Presentation owns company visibility policy. The existing broad native JSF SEO Rule remains disabled; do not enable both SEO publishers simultaneously.

**WPML:** English is the source. Every language gets a distinct candidate, translated term IDs, route and a manually reviewed title/description. The verify step checks term translation and the localized page; it must not infer a translated approval from the English rule.

**Coverage:** The rule count is not generated from the Cartesian product. Real `WP_Query` intersection counts and returned published post IDs support editorial review. Empty results, private taxonomies, stale company policy, unsupported source, unresolved WPML, missing editorial review and unverified URLs prevent approval. One or more facets can be used; up to 12 are accepted, independent of a batch limit of 40.

**Operational guardrail:** Exact-URL publishing is **OFF by default**. Tests must never publish SEO on an actual site. Production is not part of this Staging rollout. No certificates or non-WordPress owner signatures are needed to run the plugin.
