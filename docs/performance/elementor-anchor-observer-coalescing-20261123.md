# MAD4B Elementor anchor observer coalescing — 23 November 2026

## Live browser symptom and cause

Wayfatours tour 60623 showed intermittent Chrome `Page Unresponsive` and substantial lag on desktop and mobile. Public HTML reached FastCGI in ~12ms once warm but was 761KB and contained ~3,935 initial DOM elements, 1,240 options, 101 inline/external `<script>` blocks, and multiple Elementor/JetEngine/Fluent Form integrations.

A bounded Chromium/CDP CPU profile found repeated `getScrollPosition()` calls inside Elementor's anchor/sticky tracking module. The **exact**, vendor SHA `bbfe6403...` module creates a document-wide `MutationObserver` and invokes a full anchor/sticky recomputation separately for each relevant mutation record, without coalescing. On this large, highly dynamic tour this creates layout work and long main-thread tasks. A related upstream report exists at https://github.com/elementor/elementor/issues/31501, but this is independent local evidence and not a claim that the upstream issue is fixed.

## Exact mitigation

- Keep the original Elementor PHP and JS files byte-for-byte unchanged.
- Generate a first-party JS copy from the exact known binary. Replace **only** the observer callback with one that schedules a **single `requestAnimationFrame` recomputation** for all matching mutations pending in that frame. Do not change selectors, anchor navigation, sticky logic, form/price scripts, or mutation capture.
- A small `script_loader_src` MU hook serves the reviewed copy on `is_singular('tours-and-activities')` only, with exact vendor SHA and exact generated asset SHA checks. Admin pages, Elementor editor previews, other post types, unexpected vendor updates, missing/corrupt assets all use untouched Elementor.
- The source and Wayfatours generated JS both match SHA `a2ff13fb4b2d624179d1304fe7fc02771e512c157f624351d5f2d4dbed7a578c` while the original Elementor file remains `bbfe6403b4ddba29284c72d20f000154d04588d60850fc39a6fd65861c6d83e6`.
- The generated **third-party derived JavaScript is intentionally not included in Git**. Rebuild locally with `tools/performance/build_elementor_anchor_coalesced.py --wp-content <site wp-content> --apply` after validating original source hash. Existing file hashes must match exactly; no overwrites.

## Evidence — controlled browser and live site

The isolated A/B Chromium experiment on the same Wayfatours mobile page, with the same scroll sequence and a controlled `--cpus=1.8` browser, showed Long Task blocking duration (sum of task time above 50ms) decreasing from **707ms baseline to 285ms** with the single changed observer. The Elementor `getScrollPosition` CPU sample count fell from 373 to 45. This is a single synthetic comparison, not a statistical median or production INP measurement.

On the actual Wayfatours deployed PHP path, a query-specific uncached HTML response generated a new LiteSpeed JS aggregate containing the **new** observer callback (not the old callback). Browser in bridge-network mode rendered the real query (HTTP 200), found Fluent Form 6 with 40 booking fields, a visible pricing widget, TOC, and Elementor initialized; no new uncaught JS exceptions. The live patched Chrome profile observed 21 `getScrollPosition` samples. A prior host-network run failed due to Docker `ERR_NETWORK_CHANGED` and was excluded from metrics.

Exact source and Wayfatours PHP behavior checks each passed scoped script replacement, unchanged unrelated handle, unchanged non-tour, and unchanged admin page. PHP 8.5 lint and JS syntax checks passed.

## Limitations / follow-up

The testing was anonymous Chromium: the user's actual logged-in `mad4b_bootstrap_admin` session was not accessed and a full `Page Unresponsive` dialog was not reproduced. Origin cache MISS on the same page remained **3.5–6 seconds**, and other JS (Elementor/Fluent Forms/jQuery, TOC/layout, LiteSpeed aggregate) still contributes to blocking. Do not claim all lag has ended.

The CSS certification and FastCGI privacy rules were unchanged. HTML already cached before deploy may still reference the previous aggregate until a narrowly scoped invalidation or natural expiry. Signed private page sessions naturally bypass the shared HTML cache and are served the updated script on fresh HTML generation.

Rollback: remove only the first-party `439-mad4b-elementor-anchor-observer-coalesce.php` from the affected site's `mu-plugins` directory (keep its private backup receipt and original vendor files). For cached HTML, invalidate only affected tour URLs if necessary. Do not modify Elementor core/vendor files or bypass release/owner governance to promote this workaround globally.

Further action: regression-test authenticated browsing, booking changes, tab anchors and sticky placements, and investigate remaining JS Long Tasks separately. Prefer an upstream Elementor fix when available; rebuild only against a newly reviewed vendor source SHA.
