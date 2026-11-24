# Native SSR for Elementor navigation-editor-grid

Date: 2026-11-24
Sites: Tours.dev (source), Wayfatours production
Incident: Tour #60623 exposed a Mega Menu with missing destination cards on canonical public GET, while a query-param (non-lazy) control still showed the correct cards.

## Reproduction and root cause
The original `mad4b-company-adaptive-controls.php` enables JetEngine lazy AJAX for eligible listings, including the two `navigation-editor-grid` children of Elementor Mega Menu widget `6072b3f` in template `26212`. These listing widgets reference `67125` (destination cards) and `67124` (tour-type cards), both with dynamic links. On the canonical Tours.dev Tour URL, the menu contained **0** `.jet-listing-grid__item` elements; a URL with a non-allowlisted query parameter, which disables the adaptive pipeline, had **6**. A temporary forced-lazy canary also showed blank menu items on open and was **removed without promotion**. No earlier evidence justified treating layout proof as a complete signed AJAX content parity proof.

## Safe permanent remediation
The new first-party MU plugin `447-mad4b-navigation-native-ssr-guard.php` uses a **semantic CSS class** `navigation-editor-grid`, not a hard-coded listing ID, to require native server-side rendering for the public navigation component. Two hooks preserve the behavior:
- `mad4b/company_adaptive/listing_eligible` returns false before the adaptive widget mutates `posts_num` or `use_load_more`.
- `jet-engine/listing/grid/is_lazy_load` at priority 1001 preserves the SSR requirement after other JetEngine filters.
The guard is inactive for admin, AJAX, preview, REST, WP-CLI, and non-GET. All other listings, price/booking/Fluent Form 6, media assets, dynamic template links and per-site policies retain their existing behavior.

A temporary site-specific `native` postmeta override for the two source listing IDs was used solely to prove the built-in fallback. Both overrides were **reverted** before relying on the semantic source-code fix. Wayfatours listing meta was not changed.

## Verification
- `php -l` passes on both sites; exact live file SHA256 both sites is `817994f78e9cc33484447ec1e8f54f7dd6f7a1e56effae8e7138051f705f3de9`.
- Hermetic CLI PHP regression tests: 8/8 pass (semantic recognition, normal listings unaffected, protection at both filters, non-GET unchanged).
- On the canonical Wayfatours and Tours.dev tour #60623 URL, Chromium mobile 390x844 and desktop 1366x900: HTTP 200, **44** Mega Menu nested `.jet-listing-grid__item` elements, **0** native lazy placeholders inside that menu, original Form 6, price and TOC present, **zero uncaught JavaScript exceptions**. Before the new fix, Tours.dev canonical URL had 0 menu items.
- In both sites' initial DOM, header selects remain on-demand, approximately 249-255 `<option>` nodes. Note that rendering previously absent menu cards increases DOM compared with the temporarily broken, empty-menu page; this is required functional content, not a performance regression to hide.
- Existing `MAD4B_Cache_Control_V1::socket_purge` used only to version public published singular Tour paths: **221** Tours.dev + **84** Wayfatours. All batches reported status `ok` with matching version counts. No global Redis, JS or CSS cache purge, and CSS V2 certificate state was not modified.

## Rollback and open performance issues
To roll back the plugin, remove only `wp-content/mu-plugins/447-mad4b-navigation-native-ssr-guard.php` from the affected site and regenerate only affected public Tour path generations. **Warning:** rollback would reintroduce the observed missing menu cards unless a separately proven equivalent fallback is deployed.
The broader freeze incident is **not closed**: authenticated user Chrome `Page Unresponsive` remains non-reproducible here; source-side Origin MISS still takes seconds; Elementor tour content template `24784` and Mega Menu server-side rendering remain expensive; and Wayfatours LiteSpeed current combined JS asset has not been replaced by a smaller performance-certified build. A combined JS canary was larger and yielded worse main-thread blocking and was removed.

Keep this PR Draft until exact-head CI and the legitimate owner-attestation workflow succeed. SSH/root is a delivery fallback and **not a substitute** for the repository's signed release authority.
