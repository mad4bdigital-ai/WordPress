---
name: wordpress-browser-acceptance
description: Execute governed external-browser acceptance from a fresh MAD4B plan through quota-aware managed browser providers and return evidence to the MAD4B reducer without granting the browser layer independent authority.
---

Use this skill when a WordPress release or provider integration requires real browser-runtime evidence beyond server-side semantic acceptance.

1. Read `mad4b/browser-acceptance-capabilities` first. Browser execution is external and non-authorizing; do not treat browser access as WordPress write authority.
2. Request a fresh `mad4b/browser-acceptance-plan` for the exact provider/profile through the authenticated MAD4B transport. Never fabricate, edit, or reuse an expired plan, challenge, route, taxonomy term, query ID, origin, or build identity.
3. Require the plan state to be `ready`, the expected provider contract to be present, and the freshness challenge to remain valid for the planned execution window.
4. Execute only plan-derived navigation and UI interaction. Do not accept caller-supplied URL, selector, JavaScript, taxonomy, term, query ID, SEO target, or arbitrary browser command.
5. Select browser infrastructure through the MAD4B managed-browser provider policy. Prefer recurring free capacity, require session-fit within the run budget, and keep provider limits advisory because live provider responses are authoritative.
6. A missing provider credential may be skipped. Quota, rate, capacity, provider authentication/configuration, or temporary provider transport failure may open that provider's circuit and fall back when `auto` policy permits.
7. A plan, observer, ETG/WordPress UI, DOM, filter binding, reset, semantic, or acceptance-execution error is not a provider outage. Fail closed and do not consume another provider quota attempting the same defect.
8. Enforce the per-run provider-attempt and browser-session budgets. Short-session providers may split the signed plan into bounded case chunks only while the original challenge remains valid; never create a replacement case or broaden scope.
9. Keep MAD4B MCP credentials separate from browser-provider credentials. Use a just-in-time short-lived MCP access token for live orchestration, never persist a refresh token in the browser runner, and never pass the MCP bearer into the browser subprocess.
10. Enforce the provider-neutral network boundary before navigation. Active cross-origin document, script, XHR, fetch, WebSocket, EventSource, manifest, or equivalent requests must fail closed unless the hostname is explicitly deployment-allowlisted; do not accept wildcard, URL, path, port, or caller-provided network scope.
11. Collect evidence only through the package-owned passive observer and complete result identity/order observation required by the signed case. Provider attempt logs must redact tokens, API keys, bearer values, and credential-bearing WebSocket query parameters.
12. Preflight the evidence against the same bounded contract used by the MAD4B reducer, then submit it through `mad4b/browser-acceptance-result` with the original `plan_digest` and `plan_signature`. Do not self-declare PASS from the external runner.
13. Treat `browser_runtime_parity_verified=true` and reducer `verdict=PASS` as browser evidence only. SEO publication, Production activation, write authority, plugin lifecycle mutation, and other release gates remain independent.
14. Return the selected provider, provider-attempt ledger, run-budget usage, plan digest, reducer verdict, parity verification, unresolved observation gaps, and the next governed release gate.

## MAD4B locale content-card acceptance extension
For any provider-approved tour/product/landing page plan, compare canonical and localized pages at 320, 360, 390 and desktop widths. Trace nested Elementor template IDs from DOM `data-elementor-id`, not the outer document only. Inspect each visible information card: heading/icon with empty rendered value is FAIL even when a dynamic widget exists in `_elementor_data`; record DOM and data-provider evidence. Compare displayed term identities and count against canonical WPML term families, detect duplicate translated term pairs, mobile placement of booking form, sticky Tour Contents hit testing, locale menu switcher overlay, and RTL differences. A marketing/analytics 204 response or privacy storage warning is not a JS failure. Route issues through the native Content Readiness source/Board workflow; only the existing browser reducer can verify PASS.

## Tour Content global and per-tour drag-and-drop regression
Use authorized browser plans to test one global section reorder and a per-tour JetEngine repeater override, including the reset-to-Elementor-default flow. Verify DOM child order, stable section IDs, unchanged Elementor Grid display, inherited WPML order across six active locales including RTL, no lost form, active Rate buttons, Extras/Optional Trips selection events and unchanged price quote policy. Separate actual rendering verification from HTTP 200 or successful PHP lint. Restore any temporary global order, purge affected cache, and require rollback proof. A missing source template, stale section ID or supported display mode without an active provider must be surfaced as a readiness gap, never silently considered PASS.

## Elementor editor-only CSS and native MCP regression
- After authorized Elementor UI Custom CSS edits, inspect rendered computed styles and exact saved selector text (especially `>`, `&`, angle brackets and backslashes). Verify Elementor native save, generated CSS rebuild, locale and relevant breakpoints. A successful HTTP 200 does not prove CSS integrity.
- If native Elementor MCP is used, require independently verifiable authenticated `initialize` / `tools/list` / matching tool schema and actor permissions; never label a local PHP Ability inventory a network handshake.
- Check desktop and mobile accordion ordering (Tour Season third after Tour Type and Tour Style), Price/Destinations outside accordion, native JSF filters, translated cards and RTL, without trading away existing visitor functionality.

## Cross-admin settings UX and public Tour parity

Test the Settings Alignment dashboard, original JetEngine Options Pages tabs, WooCommerce Settings, MAD4B Company Options and native Tour editor. Confirm each duplicate semantic field clearly identifies its source, effective/inherited/overridden status and original editor URL; when a screen does not own a value it must not offer an independent divergent write. For rate views, test an existing Accordion Tour plus a policy-first Card Slider Tour and an explicit per-Tour override; assert exactly one live DOM Listing and actual Swiper/JetSmartFilters behavior. Include the linked WPML translation, responsive widths, keyboard/focus, RTL where applicable, and full-page cold and warm cache after a save. Verify that a not-ready company email keeps Woo's fallback and never sends during read-only acceptance. Report site, route, language, browser, viewport, provider revisions, HTTP/cache headers, actual DOM counts and remaining blockers.


## Native Elementor template parity — mad4b.elementor-native-template-parity.v1
For an Elementor content/layout change, verify the published original template on each affected WPML locale (not copied test templates). Assert one intended widget, preserved JetEngine listing surfaces, expected inherited visibility, correct translated text, compiled post CSS and computed style. Inspect desktop/mobile and RTL when supported. Distinguish HTML/CSS evidence from a real headless/browser result; if the browser crashes, leave visual/JavaScript acceptance UNVERIFIED. Do not create duplicate HTML/CSS renderers to force a test PASS.
