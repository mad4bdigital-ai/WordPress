# MAD4B Responsive Media — Generate Only Requested Sizes

## Architecture

**Do not generate arbitrary image presets at upload, or resize inside a visitor's HTTP request.** The browser measures the rendered image/card width and device pixel ratio (DPR), and requests one physical pixel width rounded **up to the nearest 64px**. A 288px rendered card at DPR 2 requests only 576px; the same site at a 400px desktop card and DPR 1 requests only 448px.

The frontend waits until the page load event and idle opportunity, then batches up to eight same-origin image readbacks for cards that actually enter the viewport in **one** WordPress REST bootstrap, avoiding one PHP request per card. It never delays CSS Async/Inline, hero LCP or first paint. The backend validates that the requested term belongs to a company's configured card IDs (including its active WPML translations), resolves only the canonical source image from that term's configured media meta, then checks for a matching already-generated derivative. An existing image is served as a **static WebP file**; no resize, no proxy or PHP image stream in the user request.

If missing, the REST route schedules **one WordPress native Cron event** for the exact requested physical width and returns `pending`. The existing image remains as the fallback. The site's own WordPress PHP/Imagick worker generates the WebP asynchronously under a global site file lock and a 25-second CPU cooldown. Once generated, a later eligible viewport request reads it from cache and updates only that card's CSS background; image files and source meta remain untouched.

## Host-agnostic, device-aware company policy

The **same plugin source** is installed per WordPress company, on its own host. It never calls a central VPS executor or an external image optimizer; the host must support WebP-capable Imagick for generation. Companies must opt in with the local option `mad4b_responsive_media_policy_v1`. Example for Wayfatours:

```json
{
  "enabled": true,
  "taxonomy": "destinations",
  "ids_option": "mad4b_perf_home_city_card_ids_v1",
  "source_meta_key": "featured_image",
  "selector": ".elementor-element-95d1c9c",
  "root_lazy_class": "mad4b-city-geometry-lazy",
  "card_visible_class": "mad4b-city-geometry-visible",
  "protected_primary_term_option": "mad4b_perf_primary_city_term_id"
}
```

The option's IDs refer to JetEngine terms from the company's own live query; the frontend term identity comes from the actual rendered `jet-listing-dynamic-post-ID` class. WPML maps currently active locales dynamically. Nothing is hardcoded to three named cities or particular screen devices. Additional company surfaces must register their actual taxonomy/source/selector in their local policy before enabling; the initial adapter is for **one configured destination card surface** per company, not yet every image on a WordPress site.

## Safeguards

- Exact local site media URLs only; no user-submitted paths, URLs, file names or arbitrary source IDs.
- One native WordPress job per width + source; 64px bucketing, width 192–1536px, maximum **4** output widths per source *fingerprint* and 4 queued widths per term per hour.
- Source freshness encoded by fingerprint of original image path, modification time and file length. Stale versions cannot be returned as ready; static cache URLs change when originals update.
- Bounded media: local JPEG/PNG/WebP source 1KB–10MB, source <=22 million pixels and 8k per axis, output 1.5KB–1.2MB, WebP quality 80, no upscaling.
- Single site-local image conversion lock across every company image and active locale; defer additional processing for 25 seconds between successful jobs instead of parallelizing heavy conversions. Conversion is bounded by Imagick memory, map and time resources, and the original resource limits are restored.
- Never delete/rewrite the original file, overwrite a derivative, change booking or commerce, touch wp-config, disable WP-Cron, rewrite the source CSS/JetEngine query, or change MAD4B mobile CSS Async and Async Inline contracts.
- Same-origin GET readback is public because images are public; source metadata is only from allowlisted WPML terms. A pending or unavailable derivative falls back to the already-rendered media without layout changes. REST batch responses include `Cache-Control: private, no-store` so queued results are never cached as ready/failed forever.

## Live evidence prior to enabling

Offline PHP/Imagick source test: **26/26** checks including a real local derivative and original preservation. Browser canary, injected *without any live WordPress or site file change*, measured: mobile 390px screen (288px card, DPR2) requested **576px**; desktop 1440px screen (400px card, DPR1) requested **448px**. Browser console errors: zero. Requests were in exact bounded buckets.

Activation is separated from this source contract: a given company's local option must explicitly enable the adapter, and public HTML/CSS, language parity and site performance must be verified after that. A Source PR is not itself a Production rollout certificate.

## Performance-bound batching

Each browser card still asks for only one physical source/width combination.
The script combines at most eight currently visible pair requests into one
REST call, with at most two such calls concurrently, after window load/idle.
Each response contains only a key-to-result map for validated term IDs.
The server rejects malformed lists, arbitrary paths and batches >8, and
never resizes inside the request; WP-Cron remains site-local and bounded.
The original static image is always the fallback.

A live Staging guest REST response (before batching) took ~1.32 seconds to
boot WordPress. Batching avoids multiplying this overhead by the number of
visible cards. This is a risk-control design, not a new LCP performance
claim; measure after controlled company opt-in.

## Preventing duplicate CSS background downloads

Do not enable this adapter on a surface that does not already have a
working geometry-lazy background gate. The opt-in policy must declare the
site's exact root lazy class and card-visible class. The browser starts a
cheap batched lookup for cards *near* but not yet revealed. If the static
derivative is available it preloads only that image, and applies it when
the normal card reveal class is set. If the card reveals while the better
file is still loading or before the REST response arrives, the existing
legacy CSS image wins; the script **never swaps a background after it is
visible**, avoiding a second download. No image is ever hidden while a
remote REST response is pending. A missing lazy gate fails closed.

This safeguards the currently enrolled Wayfatours city geometry engine.
Other company layouts must register their equivalent existing lazy gate
before this opt-in applies; the plugin is otherwise inert there.

### Live browser non-regression evidence for the lazy delivery gate

An isolated Chromium network canary exercised the actual source JS with
Wayfatours-style lazy CSS, native DOM reveal, and a valid WebP stub.

- **Derivative ready before reveal:** 1 optimized WebP request, **0** baseline
  image requests; no blank card or console error.
- **Card reveals before derivative is ready:** 1 baseline image request,
  **0** optimized image requests; no late swap.
- **Card already revealed before adapter starts:** no REST lookup and 1
  baseline image request; no second image delivery.

This is also guarded by deterministic JavaScript event tests, including a protected primary LCP card. A missing
`root_lazy_class`/`card_visible_class` company policy prevents frontend
activation; sites must reuse an existing native lazy-background event
contract rather than adding a parallel rendering mechanism.

### Primary LCP media exception

Wayfatours' `mad4b-mobile-lcp-city` native CSS owner may force the selected
first-city image to load even when ordinary background lazy gating is active.
The optional company policy points to its actual WordPress option
`mad4b_perf_primary_city_term_id`; the source-owned PHP resolver maps that
term to the current WPML locale and supplies only its term ID as
`protectedTermIds`. The browser skips this LCP card entirely. This is not
a hardcoded city name, site-wide exclusion, or a new image priority. Other
company source owners can similarly mark protected first-paint media.
