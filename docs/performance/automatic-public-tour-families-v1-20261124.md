# MAD4B Automatic Public Elementor Family Certification v1 — 2026-11-24

## Goal and scope
The mixed Tour Elementor template 24784 combines public itinerary/description with live Rate Generator 48317, Fluent Form 6 and booking/payment islands. No whole-document Elementor cache is safe. Previous first-party certified implementations covered public Related Destinations template 48366 and hand-pinned Included/Excluded container 409e1bc.

This release adds an autonomous **read-only structural discovery** engine for public child containers in site-authorized Elementor family documents. The discoverer examines raw Elementor widget kinds and JetEngine source types, tests dynamic-tag/field semantics and display conditions, rejects unknown widgets, and validates the actual rendered HTML before storing. It is not a blanket whitelist for entire mixed families. The source of truth is each site's native Elementor data.

## Independent family eligibility
453 v0.2.2 exposes public_route_eligible as an independent canonical Tour GET/anonymous/cookie/site-scope/nonce authorization gate. Its original eligible still requires the pinned 24784/48366 hashes. 454 v0.2.2 now uses the independent route gate for separately certified family documents, so edits to 24784 no longer deactivate a still-valid certificate for family 45262.

## Scanned families and qualifying roots
Both site-specific approved manifests pin **8 published family documents**: 24784, 45262, 25921, 24780, 45149, 53521, 45152, 14557. Family SHA256 values differ by company and derive from live WPML-aware WordPress metadata.

Live scans found **9 structurally eligible roots across 3 families** on each site:
- 24784: 55314bb (Tags). Existing manual 409e1bc Included/Excluded is excluded from repeat certification to prevent duplicated nested cache.
- 45262: 6e24fd9, 7827835 (public informational Tour facts). Conditional hidden elements remain naturally absent from the DOM.
- 25921: df200da, 50111aa, 3147022, f69717d, 99b649a, 29280ab (static informational children). A candidate only caches if its native HTML actually passes the runtime size and data checks.
- 24780, 45149, 53521, 45152 and 14557: no approved public roots due to gallery/JS state, template/shortcodes, query filters, commercial extras or Fluent Form. These still render normally; failure to cache is NOT failure to display content.

No individual auto-root IDs are manually inserted in the manifest. Newly changed Elementor family hashes are intentionally NOT silently promoted to Production; they need a renewed family certificate and native-vs-cached validation.

## Security, cache, invalidation
Public canonical Tours and GET only; logged-in/editor/REST/AJAX/CLI, unknown cookies, payment/nonce/cart contexts denied. Family and root digests validated per WordPress/WPML runtime, known Elementor widget types and post/terms/repeater sources only, approved post-meta fields only. Exclude unauthorized dynamic fields, Jet query counts, forms, scripts, inputs, inline event handlers, currency values, checkout and token-bearing attributes, without banning ordinary words like payment or booking.

Per Tour/market/WPML locale/device/UA/post/meta/listing/terms/generation snapshot key; TTL 120 seconds; replay native registered asset handles, native fallback on missing assets. Original Elementor outer container/display conditions remain live. Record template/listing/Tour dependencies after successful write; no full Redis, CSS or JS flush.

Rollback: setting mad4b_auto_public_family_mode_v1 to off per company disables all auto roots without touching the original two independent cached components.

## Real acceptance and limitations
Hermetic PHP verifies route/family independence, nonce/cart/query/POST denial, subtree discovery, unsafe widget/metadata/HTML denial; existing 453 and 454 tests pass. Native-vs-auto Chrome compared Source Tour 60623 EN mobile and ES desktop, Source 57562 EN desktop, Wayfa Tour 60623 EN mobile and ES desktop, and Wayfa 57562 EN desktop. **6/6 render parity checks**: exact visible root HTML SHA, scripts/stylesheets, Form6, 44 menu cards. No observed page errors/asset 4xx.

Wayfa Tour 57562 has a missing displayed selling price in BOTH original and auto outputs. The separate commercial readiness issue is not fixed or certified by this release. Source live component samples: Tags 55314bb approximately 57ms on initial render; Tour facts 6e24fd9 approximately 6ms. These are component measurements, not end-to-end page improvements or p95.

Cold Origin remains multiple seconds and authenticated long-task freezes are not proven solved. Initial auto mode was canary; enable Production on only following exact-head CI/owner signoff and final no-header browser acceptance.
