# Native Critical CSS: Verified intermediate viewports (Wayfatours EN/ES)

## Production change — 14 November 2026 (Egypt local time)

The **existing** `MAD4B_Verified_Critical_CSS_V1` owner is unchanged. Two exact SHA-256 manifest entries for current LiteSpeed combined CSS were requalified to use two new critical CSS artifacts; every other historical entry, desktop flow, mobile 390px flow, WordPress option and full-CSS fallback is preserved. Only homepage EN `/` and ES `/es/` FastCGI path versions were invalidated by native `MAD4B_Cache_Control_V1::socket_purge` (not a global flush).

The root cause was multiple native Elementor header CSS rules missing from initial Critical CSS within 768–991px. They arrived with the ~7-second delayed complete bundle and rearranged the header/hero. A second shift near 991px was traced to the first-bar icon-list line wrapping after fonts loaded. A geometry reservation was measured at 47.3125px, rounded to 48px, and applied only for 900–991px. **These IDs are site-specific QA-derived CSS artifact data; they are NOT hard-coded in the general generator.**

The new tool `ops/company-code-release-v1/tools/derive_verified_midband_css.py` consumes browser CSSOM evidence, exact source CSS + old critical SHA, viewport boundaries, and an explicitly measured geometry reservation. It outputs candidate CSS and an unsigned QA receipt, **never auto-mutates production**. It refuses source drift and external resources in newly extracted rules. The original manifest and independent `critical_css_live_admission_readback.py` remain the admission + readback authority. Hermetic negative cases: 11/11 passed both regular and `python -O` modes.

## Measured results and limits

- Isolated 820px: CLS EN **0.567881 → 0.079349**, ES **0.561506 → 0.066192**; final header/hero/listing geometry matched baseline after full CSS finished.
- Isolated 991px: EN **0.667986 → 0.069810**; ES candidate **0.071267**. 768px passed EN/ES; 899 and 900px passed EN. No horizontal overflow or browser errors. 390px remains unaffected by the new media rules.
- **Production Chromium** using Android Pixel 5 emulation, 4x CPU slowdown and 1.6 Mbps: CLS EN 820 **0.077579**, ES 820 **0.063907**, EN 991 **0.071622**, ES 991 **0.073068**, EN 390 **0.000654**, ES 390 **0.000931**. Exact CSS SHA, `X-MAD4B-Mobile-CSS-Ready:1`, `X-Wayfa-Origin-Cache:HIT`, popup and calendar all verified.
- Outside scope: 992–1024px header navigation has a **distinct** late layout shift (~0.4–0.6 CLS) and did NOT pass; the new media CSS cannot apply in this interval, so its old behavior is unchanged. The city-grid/slider late shift (~0.05–0.08) and Main Thread Total Blocking Time (often high under CPU throttling) are **not** certified fixed. No new independent Lighthouse score was obtained.

## Safe future workflow

1. Identify the live source CSS SHA and compare with the manifest. If changed, stop; old approvals cannot be reused.
2. Capture critical-vs-full CSSOM for first-screen elements, including **hidden** header nodes and responsive boundaries, across each active locale and device class. Match rules from the *exact* full CSS bundle.
3. Generate candidates through the existing tools and geometry-reservation evidence. Use separately scoped `@media` rules; never mutate the full native stylesheet or Elementor theme.
4. Browser-test several widths, both languages, mobile interactions, TBT, LCP, CLS, plus final layout parity. Reject regressions. Where the 992–1024px guard fails, retain the known baseline rather than claiming a full-range fix.
5. Admit only the exact source-hash manifest entries, backed by QA receipt SHA and a CAS-protected backup. Invalidate affected route generations and verify `HIT`, `CSS-Ready:1`, byte-exact Critical CSS and actual browser CLS.

Report on VPS: `/opt/mad4b-company-provisioning/reports/critical-css-midband-20261114/`; before-manifest backup SHA and exact file hashes in `ops/company-code-release-v1/reports/wayfatours-critical-css-midband-20261114.json`.

This direct production repair is **not** an Original313 signed release and does not authorize automatic rollout across other websites, languages or templates without new CSSOM/browser evidence.
