# MAD4B live mobile CSS regression (Staging, 2026-10-31)

PageSpeed screenshot flags Ultimate Addons for Elementor comprehensive CSS as render blocking. 751,778 bytes uncompressed / 84,262 bytes gzip. Origin HTTP transfer about 16-19ms, but Lighthouse critical-path mobile savings can be ~2.8s.

The fix preserves the plugin and stylesheet. It changes UAEL CSS to media=print onload=all with noscript fallback only on the source and WPML translated homepages. All other pages keep the stylesheet as blocking when required. The English homepage includes one UAEL gallery wrapper from template 27219 but the element currently has zero height and zero images; Arabic has no UAEL widget. Egypt destination has a real UAEL infobox height 248px; styles remain untouched there. The live browser verified CSS eventually applies and the no-JS fallback is present, header remains 96px and there is no mobile overflow.

A bounded, read-only Chromium probe checks mobile CSS on all six home languages, Egypt destination, tours archive, a Nile cruise product page and Plan Your Trip. It records initial HTML media attributes and encoded resource bytes, plus observed UAEL widgets. High alerts for blocking CSS >=64KiB flow to the existing MAD4B Content Readiness external issue ledger, company dashboard and authenticated read-only MCP ability. Stale probes raise a separate alert. The staging runner is protected by host identity and cron flock twice per hour.

Evidence: latest live 10-page scan 10/10 HTTP 200. Homepages have no blocking UAEL CSS. Egypt destination still shows four High live performance issues, including perf_rbcss_daa3ffea4aa4 needing later per-widget CSS optimization. This is not a claim that PageSpeed performance scores or LCP have been remeasured independently.


## 2026-10-31 Mobile product/catalog plus font follow-up
Exact product page and tours archive are now covered by a CSS media query: mobile downloads UAEL asynchronously and desktop remains blocking; script-disabled fallback remains enabled. Other routes are untouched. Astra Cairo font is redirected from Google stylesheets to verified same-site Elementor WOFF2 font assets. Product #60629 vertical LCP image source matched local pixels exactly (SHA256 69e30866c5dc483e2c3cc79620eb7430253877d18aefbd4c5ee088febf2643f7); its meta was rebound to local URL after original value backup and narrow page cache purge. The home H1 in nested Elementor template #41727 is currently hidden on mobile; separate custom widget should not be inferred from this built-in heading. Post-fix LCP score is not independently certified due high staging TTFB.


### 10-page live LCP observation
The browser probe now records Navigation Timing TTFB, buffered Largest Contentful Paint element identity and post-resource render delay. A reproducible 2,500ms+ element delay yields a per-page perf_lcpdelay High finding, and a 2,500ms+ site median TTFB across 7+ samples yields perf_ttfb_site High. Staging sampled ten pages on 2026-10-31: median TTFB 5,095ms, triggering the site alert; observed element delays ranged around 40–284ms and did not reproduce the supplied PageSpeed H1 3,880ms delay. Avoid treating the Lighthouse report as fixed or extrapolating it from a different environment. The original H1 is an Elementor Heading within hero template #41727 and is currently hidden on mobile.

### Local image parity across WPML
All six language records of Tour product #60629 now use the same local image_vertical URL; each translation had an exact old remote-image value and retained an independent backup. Their byte-identical image content is preserved and individual page caches purged. CSS mobile tuning resolves translated product slugs by WPML source ID, not by assuming translated slugs equal the English one.

A third-party fonts.googleapis.com CSS request is independently flagged as perf_fontcss_<hash>, even if transfer bytes are small, to catch regressions against the already verified same-origin Cairo WOFF2 policy.

The dedicated Best of Egypt 12 Days product URL has been included as an 11th browser check, alongside the other sample tour and catalog pages.

The runtime probe now reports large embedded Elementor CSS separately via perf_inlinecss_<hash> after 256KiB; it records largest style block IDs and does not strip any widget CSS. Initial offline HTML profiling measured approximately 1,349KiB embedded CSS on the requested product, 681KiB on tours archive, and 705KiB on homepage. External CSS Print Method is an independent, potentially broad Elementor change that requires before/after design certification before release.
