# Alpha13 AJAX Runtime State Contract

## Purpose

Support JetSmartFilters `AJAX` apply mode where the provider is updated without changing the browser URL, while keeping URL/indexing authority strictly separate from transient browser runtime state.

## Runtime state

- The browser bridge MUST subscribe to JetSmartFilters `ajaxFilters/updated` after `jet-smart-filters/inited`.
- The bridge MAY read `JetSmartFilters.filterGroups[provider/queryId].currentQuery` as transient runtime evidence.
- Runtime state MUST identify provider, provider Query ID, archive path, and bounded filter query state.
- Configured taxonomy clauses MUST be normalized against the profile taxonomy allowlist.
- Multi-value taxonomy selections MAY drive presentation through `term_sets` and `terms:<role>:*` tokens.
- Unsupported/unprofiled taxonomy state remains visible as non-authorizing evidence and MUST NOT enter the safe filtered query.

## SEO boundary

AJAX-only state MUST always remain:

- `authorizing=false`
- `url_authority=false`
- `seo_mutation=false`

It MUST NOT create or update canonical URLs, robots directives, Rank Math metadata, hreflang, schema, sitemap candidates, approved combinations, browser history, or profile/indexing authority.

## Presentation

- ETG live Dynamic Tags and Dynamic Content Slots MAY refresh after AJAX provider updates.
- The public REST surface MUST be read-only, payload-bounded, profile-scoped, provider/query-scoped, and fail closed.
- When Global is OFF, live AJAX presentation MUST respect the existing `elementor_render_when_global_off` dark-rendering gate.
- The browser MUST ignore responses that do not declare the expected contract and non-authorizing/url-non-authority state.
- A public `etg-dfsb/ajax-presentation-updated` DOM event MAY expose the sanitized presentation response to third-party widgets.

## Elementor/editor UX hardening

- The ETG Dynamic Content Slot selector MUST remain usable on a fresh install without mutating WordPress options. A bounded built-in slot catalog MAY be merged virtually with operator-saved overrides/custom slots.
- Built-in slots MUST remain presentation-only, use no language-specific authority, and MUST NOT enable a profile, Global, indexing, combinations or publication evidence.
- Elementor MUST expose dedicated presentation tags for term fields/sections and native Image/Gallery controls when the corresponding Elementor Dynamic Tag categories are available.
- Registered ETG Dynamic Tag classes MUST remain compatible with Elementor `Manager::create_tag()` recreation: the tag class must accept Elementor's single data-array constructor argument and MUST NOT require ETG service injection in its constructor. ETG resolver/catalog/slot dependencies are request-scoped internally.
- Primary media presentation MAY expose `image_id` and `image_url`; media continues to come only from governed term/profile context.
- An Elementor `Preview URL` control MAY resolve a same-site URL through `buildEvidence()` for editor/preview rendering only. The setting MUST be ignored during normal live front-end rendering and MUST never grant URL/indexing authority.
- Admin Inventory Control and Dynamic Content screens MAY improve layout, explainability and navigation, but MUST preserve existing fail-closed confirmation, Global-OFF, inventory-fingerprint and configuration-revision guards.
- Native Elementor image/gallery/CSS attributes are server/editor-rendered surfaces. Alpha13 does not claim automatic mutation of those native attributes after transient AJAX filtering; custom live URL/image targets remain governed by the explicit browser binding contract.

## Result count

- The Query Builder result-count adapter MAY consume the sanitized AJAX filtered query instead of relying on the current HTTP JetSmartFilters request.
- Provider/query identity and Query Builder binding MUST remain the same authority chain used by URL-state execution.
- Runtime AJAX result count is presentation evidence only and does not satisfy publication parity or URL indexing evidence by itself.

## Failure behavior

Missing provider/query identity, profile mismatch, unknown taxonomy, malformed state, unavailable runtime, failed Post Type binding, stale/missing terms, or disabled dark rendering MUST fail closed without altering the page SEO authority surface.

## Release boundary

This contract does not authorize merge or Production activation. `merge_authorized=false` and `production_activation_authorized=false` remain in force.
