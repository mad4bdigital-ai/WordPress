# Tours.dev Staging CSS Epoch 27 — exact-source requalification, 2026-11-06

## Scope
This is **Staging only** on `https://tours.dev.mad4b.com`; Wayfatours Production was not modified and public CSS Async was not activated. The tenant-specific `wp-content/mad4b-predictive-css-v2/manifest.json` is intentionally not checked into the shared plugin source tree.

## Exact-source checks, before manifest amendment
- WP-CLI scope: environment `staging`, canonical origin `tours.dev.mad4b.com`, global CSS epoch `27`, Discovery Dirty `0`, published discovery state `observed`, published graph digest `1c6c9862c344de0e5f5759a9452bc1efd50316b196bb3071b493a2a553441f3a`, public Async `off`, approved variants `0`.
- Both new homepage responses were HTTP 200 and independently contained **exactly the same 29 Elementor document IDs** as the current route plan; their document fingerprint matched `c2ab8588745a2f61604dff3c7c86e8e4cffbd30730035be04ba83eea1694b219`.
- Both desktop and mobile used LiteSpeed CSS bundle `753c892e7e8c5151228b4458057f42cf.css`, and the local CSS file matched its pinned SHA256 `ec6e183f54b276773619a41ecc53bda6a0de45290a50544960793ffc4d192d46`.
- The mobile Canary critical CSS file was separately verified against its registered SHA256.
- The prior `epoch26` evidence remains historical; no result was copied to `epoch27`.

## Exact change made
The staging manifest was backed up to `/opt/mad4b-company-transport/css-drift-regeneration-20261106/epoch27-refresh/manifest-before-epoch27.json` and atomically amended for route `/|en`:
- `css_epoch: 26 → 27`.
- Added `css_epoch_requalification` provenance with the matching CSS/document/discovery digests and explicit `prior_browser_evidence_carry_forward=false`.
- Set current mobile variant `performance_gate.status=pending_epoch27_browser_evidence` (not PASS).
- Retained `release_state=canary_only`; no global LiteSpeed Async or UCSS setting changed, and no Production publication.

## Actual policy readback after write
Immediately after the amendment, WP-CLI returned: `epoch=27`, `dirty=0`, `selected_source=null`, `gate_state=hold`, `reasons=[no_v2_evidence]`, `receipt=native_hold`, `approved=0`. **This is the intended fail-closed result.**

## Browser acceptance not completed
Fresh native and canary Staging mobile probe HTTP 200 captures were collected. Native included no MAD4B CCSS/Async markers; Canary contained exactly one each, with actual generated content. The independent route bodies differed because JetEngine rendered dynamic content, so a pinned identical-body fixture was prepared before comparing strategies.

A new six-pair Chromium execution for `epoch27` was **blocked by the tool safety check before execution**. Thus no epoch27 performance result exists; the earlier six-pair `epoch26` result (p75 post-TTFB LCP -1497ms and FCP -3021ms) **must not be promoted or labeled epoch27 evidence**. A subsequent WP-CLI readback was also blocked by tool safety checks; do not assert more recent configuration than the successful post-write readback above.

## Independent WPML and cache release gaps
Previous live multi-language filtering tests showed EN/DE/IT changing the target JetEngine query after AJAX 200, while AR/FR/ES returned AJAX 200 but did not change target post IDs in the measured selection. The checkbox click alone does not count as a provider-parity pass. EN mobile native-versus-canary menu/FAQ/Swiper/JetSmartFilters interaction checks did pass at both 320px and 390px; that must not be generalized to other language canaries without testing.

Native LiteSpeed `litespeed_purge_url` was successfully dispatched to the staging homepage in earlier QA. A **genuine approved-to-revoked CSS lifecycle** was not available because `approved_count=0` throughout this exercise; no synthetic approval was installed or forged to bypass release policy. This remains a distinct acceptance requirement.

## Decision
**HOLD public release.** Re-run epoch27 3 cold + 3 warm matched HTML/asset browser A/B, finish dynamic locale parity and validate genuine prior-release cache revocation when a legitimately approved staging variant exists. Do not merge PR #763 or deploy to Production based on this route-source proof alone.
