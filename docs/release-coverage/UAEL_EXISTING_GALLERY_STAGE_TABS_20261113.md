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

## QA Tour 69715 / Extra 60469 native gallery composition

Tour `69715` is a **draft eight-day Cruise**. Its two published optional
Extras `60467` and `60469` are stage-mode activities; their parent Cruise
must retain actual day labels. Extra `60469` has a four-stop itinerary, and
its `Aswan` section carries one legacy number `[3]`: the **existing** UAEL
Gallery must render `Stop 3 · Aswan`, not `Day 3 · Aswan`. This exact case
is now covered by the source-only PHP label tests.

In the approved same-origin Extra Quick Preview iframe, the original Elementor
UAEL Popup remains the **only gallery**. The original child-frame script
observes that popup opening and closing and informs the existing parent dialog
through a source-checked, origin-checked postMessage. Parent removes its
redundant Quick Preview toolbar while the Gallery is active; scoped CSS gives
the native Popup's `.dialog-message` one vertical scrollport, without
modifying UAEL/Fancybox. Closing restores the preview chrome and iframe.

The Quick Preview heading previously selected the first JetEngine card field
(`Featured`), not the actual Tour title. The existing shortcode now exposes
a server-escaped, WPML-translated child Tour title as a data attribute, which
the existing parent JS uses directly. No new widget or gallery is created.

### Release scope: keep companies independent

- `mad4b-uael-tour-gallery-tabs`: two common gallery PHP files.
- `mad4b-extra-quick-preview-native-gallery-interop`: four **existing**
  Quick Preview files currently deployed on Tours.dev but **not** on Wayfatours.
  Do not install Quick Preview on Wayfatours as an implicit gallery fix.

Source-only checks: 15 PHP tab-label tests (including Extra 60469), 14
Node VM state/title/security assertions, 9 Code Release CAS tests, isolated PHP
lint, Node syntax checks and component non-overlap contract. No site/runtime
files were changed by these edits. Confirm exact approved Original 305/306/312/313
admission and company-target preimage CAS before any publication. Authenticated
browser QA of Tours.dev's draft 69715 Quick Preview, Extra 60467/60469,
EN/ES desktop/mobile, inner gallery close/Escape, image lightbox, scroll
restoration, and Fluent Form 6 selection is **still required** after deploy.
