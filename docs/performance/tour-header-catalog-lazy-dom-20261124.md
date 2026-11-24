# Tour header country/passport DOM inflation — source-first remediation

Date: 2026-11-24. Targets: Tours.dev and Wayfatours, tour #60623 and other published singular `tours-and-activities` pages. First-party MU plugin `445-mad4b-header-select-lazy.php`.

## Root cause
Elementor's two header contexts each print the native `[mad4b_country_switcher]` and `[etg_nationality_switcher]` separately. Each shortcode produces 251 `<option>` nodes, accounting for **1,004 header options** before a user opens either switcher. Form 6 separately owns its own full nationality field and must never be rewritten.

## Runtime contract
- Intercept only the two exact header shortcode outputs via `do_shortcode_tag`, and only public GET requests for a published Tour singular, excluding Elementor editor, admin, REST, AJAX, CLI and preview.
- Accept the transformation only for an unambiguous native ISO2 catalog of 150–300 options, with distinct ISO2 codes, one placeholder and at most one selected choice; otherwise retain normal complete native rendering.
- Keep the original full native select inside `<noscript>` for a complete no-JavaScript form. The compact select is initially disabled (so no duplicate submitted field when JS is off) and is enabled by early `wp_head` hydration JavaScript.
- Embed the source-localized country label catalog as inert `application/json`, only once per distinct catalog per request. Nothing is fetched on each keystroke; there is no new API.
- Preserve the original `select.name`, nonce, form action, ISO values, native validation, user preferences, WPML labels, WooCommerce market policy, pricing and currency logic.
- Enhance only tagged native selects. Restore a missing selected ISO option when existing visitor-state modules assign `select.value`, keeping all four selectors in sync. Materialize all native options for the active header panel when a user focuses/clicks its search. Never virtualize Fluent Form 6.
- Do not modify Elementor or WooCommerce vendor files. No new persistent databases, cookies, templates or WP options.

## Source-first Chromium acceptance
- With JavaScript enabled on 390×844 and 1366×900: **255 options** at initial render on Tours.dev vs ~1,240 before; Form 6, tour price, TOC present; country options restore to 251 and Egypt search finds the same ISO value. No uncaught JS errors.
- Wayfatours two browser runs had 249–253 total options depending on selected public visitor state, versus 1,249 earlier. Country/passport selector numbers remain two copies each; Form 6 preserved; no page errors.
- With JS disabled: 251 complete options remain in each `noscript` native fallback, all four forms submit exactly one country/passport key each, and the compact selects are hidden and disabled. No JS-dependent market change is required for this fallback.
- Source locale EN+ES, interactive manual passport search for ISO `FR`: native `visitor-preferences/v1/apply` returned HTTP 200, both passport copies show `FR`. Behavior of the non-explicit advisory market country after manual passport save matched the untreated Wayfatours baseline.
- Source EN country search: choosing Egypt returned visitor-preferences apply HTTP 200, two country selectors and both auto-passport selectors show `EG`, Form 6 unaffected.
- The selected native `DE` state can be reassigned after programmatic `EG`/`FR` writes before hydration, proving the native option setter compatibility.

## Deploy and rollback
- Installed as new, additive MU plugin on source first and Wayfatours second.
- Exact PHP SHA256: `8741b45b562605b4c7221cd1e7e8803640a953d7abbc2ad1c6231337cc69e111` on both sites.
- Rollback: remove only `wp-content/mu-plugins/445-mad4b-header-select-lazy.php` from one environment, invalidate only affected tour-page HTML versions with the existing coordinated path-generation mechanism, and rerun saved browser tests. Existing source shortcodes remain fully functional.
- No blanket Redis, CSS/JS or FastCGI purge. Existing FastCGI HTML hits may still reference previous server output until the path is specifically invalidated; verify cache cohorts separately before calling rollout complete.

## Limitations
The change removes a major source of live DOM inflation, but does not prove Chrome `Page Unresponsive` has stopped for a WordPress-authenticated user. Source template #24784, Mega Menu SSR overhead and LiteSpeed's historical JS bundle are separate. A full commercial checkout/payment flow was not exercised.
