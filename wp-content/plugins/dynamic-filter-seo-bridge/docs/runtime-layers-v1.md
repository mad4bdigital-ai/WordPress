# DFSB Runtime Layers V1 — source and deployment contract

## Goal

A blocked **SEO Full Boot** must not, by itself, disable independently verified **native read-only Elementor context** and **read-only filter context**. Rank Math remains the sole SEO publisher; JetEngine and JetSmartFilters own their native listings and filters.

Three distinct layers:

| Layer | Entry | Authority |
|---|---|---|
| Presentation Read | `ContextTagRegistration`, `ContextValueTag`, `ContextImageTag`, `ContextPresentationShortcodes` | Public source-safe text/images for published WordPress contexts only; no publish, quote, booking, or storage writes |
| Filter Context Read | `ReadOnlyFilterContext` via `apply_filters('dfsb/filter-context/read', null)` | Read native WP/JSF provider **identity/status**, not authorization to run, alter, or publish provider queries |
| SEO Publication | Existing `BootGuard` + `Bootstrap` + profile, Rank Math and publication gates | **Unchanged**. Safe Boot still blocks SEO Full Boot, canonical/robots/sitemap, mutating services and advanced integrations |

The presentation layer includes **native context tags and shortcodes**, plus a separate **AdvancedPresentationRuntime** for Elementor Dynamic Tags and JetEngine ListingIntegration. It only starts while SEO Full Boot is held and source verification succeeds. If SEO Full Boot is permitted, its existing Bootstrap remains the sole advanced initialization path. Native JetEngine/JSF run independently of DFSB.

### Advanced presentation dependency slice

- Build a **read-only** `FilterContextBuilder::buildEvidence()` from the existing site-local profiles and native JSF/WordPress observations. Never call the authorizing `build()` path, publish canonical/robots/sitemaps, or register managed rewrite routes.
- Register the existing advanced `DynamicTagRegistrar` (19 tags observed on the Staging lab) and `ListingIntegration` via their existing Elementor/JetEngine hooks. No new tag name or JetEngine native widget override is introduced.
- Preserve the original context validators: profile scope, published context, correct provider, term resolution, WPML language, verified media, malformed/unknown filter checks. Unbound or inactive context returns empty data, not synthetic values.
- **During SEO hold, do not inject `ContentSourceResolver` into the advanced presenter.** Arbitrary Query Builder, relations, Users, CCT and listing-meta/slot-row execution is not approved for a source-only gate; those paths remain unavailable, with existing native repeater data unchanged. Their independent tenant/data-ownership certification is a separate follow-up.
- No new public REST endpoint or independent AJAX presenter is registered under Safe Boot. Dynamic Tag server rendering is ready; advanced live AJAX refresh still requires an independently certified rate-limited endpoint, and must not be claimed complete.
- Bootstrap retains advanced integration when SEO is fully certified. The isolated runtime is idempotent per request and does not modify configuration options or activate the plugin.

### Advanced integration smoke

Isolated candidate classes loaded into a **single WP-CLI process** against Wo Vacation Staging (installed plugin unchanged), with `elementor/dynamic_tags/register` fired in that test process:

- 19 advanced Elementor tags, including image/gallery and term tags, registered.
- JetEngine dynamic-repeater hook registered; normal (unmatched) JetEngine content remains native.
- Read-only advanced runtime registered once; second call made no changes.
- Empty/unverified filter context returned no fabricated title.
- Source-backed arbitrary query/catalog access remained disabled.
- SEO Rank Math hooks were unchanged, `etg_filter_seo_bridge_booted` was not fired, and the existing SEO BootGuard remained held.
- Captured **zero** database DML statements during the isolated registration.
- PHP 8.3 syntax checks and the 7-case independent-source/fault matrix passed.

This is **source-level and isolated WordPress-process validation**, not a deployed-browser acceptance test. Never install unmanifested files directly into the active `alpha.14-local.1` package.

## Independent read gate

`RuntimeLayers::decide()` grants presentation/filter-context reads only if:
- Embedded build identity is valid and matches the package version.
- The installed-content manifest matches all installed package bytes.
- Provenance is complete and exact build status is `ok`.
- The registered boot identity matches the stored build and there is no recorded fatal fault.

A local source candidate **without the detached package publication certificate** can qualify for the above **read-only** gate when its installed source manifest is verified. This **does not certify the candidate for SEO Full Boot**. Full Boot's separate package attestation requirement remains unchanged. A tampered file, missing manifest, missing identity, fatal fault or build mismatch blocks public data output.

No `DFSB_FORCE_FULL_BOOT`, no rewriting BootGuard state, no fake attestation, no release receipt edits.

## Ownership and safeguards

- All presentation reads use `PageContextResolver`, `ContextValueResolver` and native WordPress publication checks.
- Non-published, restricted, commerce transaction and unresolved contexts fail closed.
- No public query-wide permissions, no business ownership inferred by post/CPT or query string names.
- All read responses are explicitly `authorizing:false`; no quote/booking ownership is conveyed.
- The native read filter does not register new public REST routes or intercept JetSmartFilters results.
- SEO Rank Math hooks and IndexFlow publication paths are not registered under Safe Boot.
- Editor/source previews retain their own existing scope and user capability checks.
- The WordPress `dfsb_context_*` shortcode names and `dfsb-context-value` / `dfsb-context-image` tag names are unchanged.
- Full Boot no longer re-registers the separate context shortcodes.
- An admin notice distinguishes SEO Full Boot from native read-only presentation.

## Tests run against isolated candidate files on Wo Vacation Staging

PHP 8.3 lint: new runtime and native context registration/tag/shortcode files, main entry and Bootstrap passed. Seven pure decision cases passed: verified source with SEO held, invalid installed manifest, missing provenance, bad build reason, recorded fatal, wrong build identity, and unheld Full Boot. Read-only filter hook test returned a non-authorizing response, and the installed DFSB BootGuard remained `effective_hold=true`.

The runtime source installed at `tours.dev.mad4b.com` is **alpha.14-local.1** with a signed file-set manifest. Its current Full Boot is held for `local_source_candidate_not_certified`. **Do not overwrite installed files from this PR**: doing so without rebuilding and verifying the full installed-content manifest would invalidate source verification. The changes are committed as source and tested **out-of-tree**, not installed into the running package.

## Release gate

1. Check PR CI and review the exact SHA.
2. Build a new package from the approved head using the repository's official deterministic manifest/package builder; verify all source files, ID and manifest on an isolated target.
3. Obtain the required release provenance and owner approval. Keep SEO Full Boot held.
4. Deploy to a separate Staging candidate first. Test existing Elementor content, native JSF widgets, WPML languages and SEO output non-regression while held. Verify `RuntimeLayers::status()` and source hash at runtime.
5. Only then promote an independently approved presentation runtime and (separately) certify/publish SEO Full Boot. Production deployment requires a separate authorization.

A false positive in the independent source gate is a release blocker. No implicit activation or tenant-wide rollout is permitted.
