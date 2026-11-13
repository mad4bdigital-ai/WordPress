# UAEL Native Gallery: Visit Stages tab category fix

**Scope: existing renderer only. No new gallery, new JS, CSS, Elementor widget, or postmeta schema.**

## Source of truth

- Existing MU plugin: `wp-content/mu-plugins/mad4b-uael-tour-gallery-addon.php`.
- Existing defer AJAX: `wp-content/mu-plugins/mad4b-uael-server-defer-v1.php`.
- Existing `MAD4B_Gallery_Stage_Policy_V1::enabled(canonical_tour_id)` decides whether the itinerary entries are visit stops; **tour_mode alone is not authoritative**.
- Existing `timeline_manifest()` supplies verified `days` indexes, stage/day labels and localized plural names. One instance is reused for category labels and the popup timeline payload.
- Existing UAEL filter identities (`mad4b-s{term}-o{order}`), photo membership, masonry, Fancybox and native gallery widget ID `1e96a81` remain unchanged.

## Specific regression

Before: `Days 1, 2, 5, 7 · Luxor` appears on the Day Tour gallery even while its existing timeline says `Visit Stages`.
After: `Stops 1, 2, 5, 7 · Luxor`. Only stage indexes present in the actual resolved stage timeline are eligible for labeling. An unresolved stage is never represented as a made-up day or stop. Multi-day tours continue using `Day(s)`.

## Tested read-only WordPress rows

- Wayfatours tour `60481`, `tour_mode=tour`, taxonomy `day-tours`, stage mode true, nine stops.
- Activity `60605`, stage mode true, six stops.
- Half Day `55260`, activity mode, taxonomy `half-day`, stage mode true, five stops.
- Cruise `60435`, `tour_mode=cruise_tour`, stage mode false.

## Cache / deployment

The existing AJAX popup HTML cache stores markup in `mad4b-uael-gallery-html-v1` for 180 seconds. Its key now includes SHA-256 of the existing server-side gallery-addon PHP file. On **approved deployment** the next AJAX popup render computes the new key instead of reusing previously cached Day labels. No broad Redis or FastCGI eviction, `Purge All`, or additional rendering layer is introduced.

Deploy in the existing site-neutral release/reconcile channel: source Tours.dev first, verify exact source SHA and readback, then Wayfatours under approved Company Reconcile. Preserve original three provider/receipt governance and do not use direct root copy to bypass Original 318 / approved write operations. The upstream legacy MU plugin files are now explicitly tracked in this source branch because they were absent from the Git release tree; their WordPress paths match the existing deployed file paths exactly. Verify deployment inclusion before publishing.

Test with `php tests/uael-stage-gallery/test-native-category-labels.php` in an isolated PHP environment and `python3 tests/uael-stage-gallery/test-asset-and-cache-contract.py`. The PHP fixture stubs WordPress hook registration, does not execute WP content writes. Confirm browser QA EN/ES, desktop/mobile, authenticated and guest/deferred gallery, all/filtered tabs and Fancybox; a CLI smoke test is not a substitute for browser verification.

## Existing Company Code Release Catalog registration

The existing dynamic `ops/company-code-release-v1/catalog/mad4b-release-components.json`
now has a two-file `mad4b-uael-tour-gallery-tabs` component. It uses the
already-existing `registered_release_targets()` Git catalog admission, not a
new release executor or a broad static operation allowlist. Source tests cover
9 non-mutating CAS admission/denial cases, including exact Git SHA and preimage
binding. A source manifest does not issue a Broker grant.

**Important live deployment distinction:** current root-owned
`/etc/mad4b/company-code-release-policy.json` still targets the older approved
Git worktree, contains only `wayfatours:production`, and has no exact static
file entries for the two gallery files. The new component is therefore NOT
currently authorized on either site; its future use requires an approved
first-party Git source reconciliation and an exact company-specific release.
The legacy Worktree is dirty and must not be overwritten automatically.
Tours.dev staging also needs an enrolled company/environment target before any
source-first rollout. No live policy file or production code was modified.
