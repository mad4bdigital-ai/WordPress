---
name: wordpress-performance-intelligence-v2
description: Preserve MAD4B's certified mobile Core Web Vitals and PageSpeed improvements; compare per-company CSS, HTML, JavaScript and cache releases using source-matched browser evidence and a fail-closed performance gate.
---

# MAD4B Performance Intelligence V2

This Skill is part of the automatic MAD4B company Skill catalog, not a universal permission to change production. It inherits the company's exact canonical origin, language/routes, trusted device class, current CSS manifest, Company Reconcile and Original release authority. Do not maintain a fixed whitelist of companies or operation types in the engine.

## Always preserve certified first paint

The current successful mobile delivery contract is a single inline Critical CSS, one full stylesheet loaded async by MAD4B's inline loader, and noscript fallback. Keep HTML visible to search crawlers, non-scripted visits and assistive technology. Do not re-enable global LiteSpeed Async, UCSS, arbitrary UAEL CSS pruning or page-wide HTML fragment caching that loses Elementor/JetEngine style dependencies.

## Evidence registry

Store *separate* immutable evidence with provenance and device/site/locale:
- Google PageSpeed Lighthouse laboratory results and screenshot evidence; score is a noisy diagnostic, not a hard deployment threshold.
- Browser matched A/B for FCP, LCP, TBT, CLS, LCP element, visual/functional interactions, device viewport, cache cold/warm, CSS hashes and Elementor content graph.
- Google CrUX/Core Web Vitals rolling-field evidence at p75, when available, via the existing MAD4B_SCP_Core_Web_Vitals collector. Missing field data is insufficient, not failure.

Source of truth for prior Wayfatours screenshots: 81/100 historical, and user-provided current screenshot 78/100 on 12 Nov 2026 22:06 EET. Their direct difference does not prove a regression because PSI runs vary. Current FCP 1.7s, LCP 4.7s, TBT 230ms, CLS 0, Speed Index 2.3s. Protect these benefits; focus on LCP image delivery and main-thread long tasks without assuming CSS is the sole bottleneck.

## Release gate / staged decisions

Use tools/performance/performance_release_gate_v2.py with a company-owned performance policy and genuinely paired Chromium before/after evidence. The gate checks all page-critical metrics together, CSS ownership, cache HTTP status, unchanged content graph, LCP identity, JS errors, horizontal overflow, and key functions. It requires at least four matched trials covering every policy viewport and both cold/warm states. A faster FCP never overrides a severe LCP/CLS regression.

Gate outputs passed/hold as performance evidence only; it does not grant a Native Executor operation, alter Original313 receipts, publish code, or authorize company writes. A company release orchestrator must explicitly call this preflight and honor HOLD before any CSS/JS/cache promotion. Do not self-report PASS when the gate has not run, nor run external PSI repeatedly against rate limits.

## Cache and capacity

A benign-cookie returning visitor must get a device/locale-appropriate FastCGI HIT with the same Critical CSS/Async delivery. Keep exact locale_seen redirect keys partitioned on root paths. WooCommerce sessions/checkout, personalization and manual currency are private. On tours.dev, WCML EUR-rendered HTML versus base USD is an origin-private veto; never bypass it to force HIT.

Use the existing Adaptive Cache Governor for generation-aware warming. A stable cache generation skips unnecessary warm HTTP requests; true content changes invalidate appropriately. Avoid Redis flushing, overcommitting its cgroup and unconditional Nginx Purge All.

## Continuous lightweight readback

Run tools/performance/performance_http_readback_v2.py on each explicitly enrolled and configured company route. It verifies HTTP 200, one Critical CSS/async/full-loader triple, native LiteSpeed CSS bundle, public HIT where allowed and private/no-store for authorized market veto. This is an HTML/cache integrity check only; **it does not measure LCP, Lighthouse or PSI score**. Run full browser A/B preflight before performance-changing releases, not on each public request.

## Rollback

On CSS asset or performance evidence regression, keep/restore the previously certified per-company manifest and CSS bundle using exact SHA/readback and targeted mobile cache key invalidation. Restore functionality and SEO before optimizations. Any update that changes timing, critical DOM or cookie consent requires matched evidence, not best-effort assumptions.
