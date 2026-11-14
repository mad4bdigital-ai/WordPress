# Wayfatours UAEL Homepage — Permanent Proof Renewal & Safe CSS Admission

## Existing owner / zero-copy policy

This release **reuses** the existing Wayfatours-owned MU `394-mad4b-uael-home-selective.php` and its original Elementor graph builder, instead of adding a second stylesheet pipeline. The protected new builder is under the company overlay `ops/company-code-release-v1/overlays/wayfatours/mad4b-uael-home-selective-builder.php`. Tours.dev does not have this exact MU owner; **do not copy the company overlay to Tours.dev** or replace any newer source-company plugin.

The active Wayfatours `394` plugin is `on` but fail-closes when the graph manifest becomes stale. Its original production graph manifest epoch was `0` while WPML/Elementor template edits advanced the current site epoch to `1794689150.148167`; templates 54880 and 56092 also changed. The plugin correctly loads the full UAEL CSS in this state. The issue was that the off-request builder had no candidate-only path or scheduled renewal; even an otherwise unchanged graph's `generated_at` was preserved unconditionally until the seven-day TTL made it invalid.

## Changes deployed (no public CSS or JS rollout)

- The original builder now supports `MAD4B_UAEL_MANIFEST_CANDIDATE=1`. This emits `wp-content/mad4b-uael-home-v1/candidate.json`, leaving the public `manifest.json` untouched. It never purges pages or alters public CSS in this mode. The former explicit CLI publication path remains guarded by its existing manual release process.
- The original builder now refreshes the proof timestamp **before** the seven-day TTL only when the graph, CSS metadata, WPML home IDs, UAEL version and epoch remain byte-equivalent. Changes never inherit a previous timestamp.
- A bounded off-request worker, `ops/company-code-release-v1/tools/uael-home-candidate-cycle-v1.py`, uses the **existing builder and independent readback** with an exact builder SHA, site identity, resource guard, single lock, CLI timeout and schema checks. The authorized VPS cron runs hourly at minute 29 with `nice`, `ionice` and a 145-second timeout. No browser is run by that cron.
- On a changed graph, only the candidate is refreshed and `candidate_ready_css_requalification_hold` is reported. On an identical graph 5–7 days old, the worker may renew the already-active proof (content identical apart from timestamp) before it expires. Expired proofs or changed graphs **cannot** auto-promote, ensuring no new CSS source/hash reaches visitors without validation.
- Built a candidate for all 43 Elementor documents/EN+ES plus popup 41878. One subscriber-only UAEL gallery exists. Independent native readback returned `READY` with no issues. Original live manifest SHA `6a6ee95484c05740eb4257bf24f94c1bef2e6a3343622b9595e5518d3e2808e1` remains unchanged. New candidate SHA `106009179616264fb64d34dd42354cd75bd1e516ab8936ae6fce5db4933c5c80`.

## Why CSS candidate did **not** pass the release gate

The currently live LiteSpeed CSS includes the original UAEL stylesheet exactly once: **751,778 bytes uncompressed** and an estimated **78,528–78,553 bytes Gzip saving** if safely excluded. Chrome Coverage on mobile 390px found first-load utilization ~9.9% of the 3.0MB CSS source and ~25.8% of the 1.26MB JavaScript source. These utilization figures describe a first-load session, **not globally dead code**: opening search and calendar uses more CSS and JavaScript. Do not suppress Lighthouse findings or remove Swiper/Elementor/Fluent Forms assets blindly.

Paired browser A/B: original CSS versus exact original with UAEL bytes excluded; unchanged HTML/critical CSS, CPU 4× and downstream 1.6Mbps:

| Locale / Width | Original FCP | Candidate FCP | Original LCP | Candidate LCP | Original TBT | Candidate TBT | CLS |
|---|---:|---:|---:|---:|---:|---:|---|
| EN, 390px | 1808ms | 1756ms | 2568ms | 2292ms | 976ms | 1297ms | 0, both |
| ES, 390px | 1584ms | 1788ms | 1688ms | 2224ms | 904ms | 1367ms | 0.00093, both |

Other isolated geometry tests at EN 390px and ES 820px matched first-screen header/hero/listing dimensions, and EN/ES phone search/calendar opened without JavaScript errors. **However**, the latest paired 390px results show ES LCP +536ms and TBT +463ms, and EN TBT +321ms. Host load varied and the samples do not prove causal regressions, but they **do not justify a production release**. Existing production Critical CSS must not be invalidated to chase an unused-CSS byte saving.

## Required release gate for the actual CSS reduction

A later, **separate** company release must generate the exact post-dequeue LiteSpeed CSS file, verify the actual SHA and corresponding critical CSS source certificate per locale/device, then pass multiple matched throttled A/B tests at 320/390/414/820/1024px plus native search, popup, calendar, swiper, gallery and WooCommerce private/booking gates. FCP/LCP/CLS/TBT must not regress outside chosen acceptance budgets. Only then may the new public manifest replace the old one via exact CAS and selective `/`/`/es/` native cache invalidation. Never purge everything or reuse a prior CSS SHA as certification.

The proposed unused-JS reduction must likewise use the native Elementor/JetEngine dependency owner, only defer proven non-visible initialization, and retest date/price/checkout. It was **not** deployed by this CSS proof-cycle repair.

## Rollback and operator readback

Company source builder backup: `/opt/mad4b-company-provisioning/backups/uael-home-lifecycle-v11-20261114/builder-original-1c1da6378e98.php`. Root crontab backup: same directory, `root-crontab-before-uael.txt`. Preserve all unrelated cron entries; remove only the line marked `MAD4B_WAYFA_UAEL_CANDIDATE_LIFECYCLE_V1` if rolling back. Restore the builder only under a current-source SHA compare-and-swap. Because no public CSS/JS manifest was promoted, normal rollback needs **no** FastCGI or LiteSpeed purge.

Independent checks: `python3 ops/company-code-release-v1/tests/test_uael_home_candidate_lifecycle_v11.py` passes **6/6**, including changed epoch, changed graph, expired graph and wrong site/builder. Live WP-CLI candidate readback returned `READY`. Public homepage EN and ES returned `X-MAD4B-Mobile-CSS-Ready:1`, FastCGI `HIT` after warmup with ~25–47ms TTFB.

Evidence receipt: `ops/company-code-release-v1/reports/wayfa-uael-home-lifecycle-v11-20261114.json`. This is a local governed direct fix, **not** an Original313 signed deployment or a final Lighthouse/TBT performance certificate. PR #820 remains governed by its exact-head owner attestation.
