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
