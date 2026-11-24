# MAD4B Mixed Template Family Public Island Compiler — 2026-11-24

## Scope

The original Tour Content Elementor family 24784 mixes general presentation with live rates, booking and Form 6. Whole-family caching is unsafe. This release adds a reusable container-level compiler to the already deployed public Tour Template widget compiler 453. It can replay only explicitly certified public child containers, leaving native Elementor outer wrappers and native dynamic display conditions unchanged.

First certified child root: 409e1bc Included / Excluded. JetEngine listings 15181 and 15188 are published Tour posts and Tour repeater content, respectively. Unapproved descendants, Rate Generator child 48317, Form 6 and all checkout/payment remain unchanged and rendered live. The registry is designed to support more roots/families through pinned manifests, but this release does not claim that every mixed widget is automatically certifiable or safe.

## Live performance and verification

Source, Tour 60623: direct profiler measured Elementor 24784 1,032.8ms, nested Included/Excluded 145.8ms, outer theme document 45149 1,735.7ms and separate header document 26212 1,113.0ms. Origin was 4–5+ seconds.

Canary for child 409e1bc: Source native 140.54ms, cached 0.52/1.52ms. Wayfa native 141.91ms, cached 0.67/0.75ms. These are per-component samples, not page load or p95 results.

Native-vs-cached Playwright exact fragment HTML, stylesheet handles, links and scope parity passed Source EN mobile, Source ES desktop, Source second Tour 57562 EN mobile, Wayfa EN mobile and Wayfa ES desktop. Form6, rates, Mega Menu 44 cards and TOC remained present. No tested asset 4xx or JavaScript page errors.

## Isolation and rollback

- Each site stores an explicit site-, environment- and family-bound approved manifest and exact effective Elementor family/root SHA256. The plugin checks the public subtree and JetEngine listing sources at runtime.
- Compiled 453 admission stays authoritative for canonical published anonymous Tours, WPML, WCML, market, user state and cookies. Logged-in/editors/REST/AJAX/cron/CLI remain native.
- Only approved Elementor container subtrees built from headings, icon boxes and Tour posts/repeater listings are eligible. Unknown widgets and dynamic commercial HTML use native rendering; content is never hidden on cache rejection.
- Context key includes the Tour, post metadata, locale/language, currency/market, device, user-agent, trusted client context, listing revisions, Term revisions and WordPress posts epoch for cross-post query dependencies.
- Cache TTL is 120 seconds. Native assets are replayed when registered, otherwise original rendering is used. No global Redis/CSS/JS purge.
- Rollback per site: set option mad4b_mixed_family_public_islands_mode_v1 to off.

## Separate Origin investigation

Header document 26212 was measured at about 1.1s. Public nav HTML across two Tours matches after a dynamic queried Tour ID is excluded, but a separate navigation-sharing canary produced a transient browser JS initialization difference on Wayfa. That experiment was explicitly rolled back: the experimental 450 MU file is absent on both sites, and its option is off. Existing 449 navigation cache remains unchanged. Do NOT claim that Origin under one second or the logged-in main-thread freezes are solved.

## Release controls

Source-first, canary, browser and unit evidence, CI and exact head owner approval before production on. Company-specific manifests are independent and remain limited to the audited root. No Elementor vendor file was modified.
