# MAD4B Native Lazy Loading — default safety, v1.0.16

Verified on Tours.dev staging on 2026-11-16. Production Wayfatours has
different runtime/source ownership and was not modified.

## Root cause

Outer automatic Lazy and automatic Inner Progressive both manipulated the
same signed JetEngine get_listing request. The original browser certificate
had been produced while Inner was held, so outer-only QA passed but actual
site-wide automatic Inner truncated the 45-card EN result to 2 cards.

Controlled 390px A/B, exact same EN page:
- Outer Lazy auto + Inner off: SSR 45 / AJAX 45, links/text identical.
- Outer Lazy auto + Inner auto before fix: SSR 45 / AJAX 2, mismatched.

v1.0.15 adds independent signed complete-pagination admission for Inner
Progressive. A first-AJAX content-parity certificate never authorizes
truncation; without independent verified complete load-more delivery,
the first native AJAX returns the full listing, including all cards.
43 unit tests cover invalid signatures, stale/query/CSS fingerprints,
wrong currencies, incomplete pages, link hashes, CLS and width boundaries.

v1.0.16 generalizes measured Lazy placeholder CSS and wrapper to mobile,
tablet and desktop. Previously wp_is_mobile gated the reservation, which
allowed CLS 0.306 on 1920px despite a valid wide proof. Every viewport
family now requires both Elementor responsive footprint certificates.

## Evidence and live QA

Independent native signed get_listing Ajax parity:
- 12/12 EN/ES browser proof cases at 320, 390, 767, 768, 1024, 1920.
- Each width has exact SSR/AJAX item count, ordered links, visible content,
  source/CSS/doc SHA, signed native query and widget, CLS <=0.10.
- Four EN/ES mobile/wide market DE-USD proofs were compared with database
  values, source hashes and CSS epoch before atomic site-local admission.
- Inner remains auto-configured but has no complete-pagination certificate,
  so it does not reduce the first signed Ajax response.

Post-fix staging QA (outer and inner both auto):
- EN/ES 390px: 45/45 and 43/43, CLS 0.
- EN/ES 768px: 47/47 and 45/45, CLS 0.
- EN/ES 1920px: 51/51 and 49/49; CLS .02558 and .01596.

After removing QA plugins and clearing stale HTML cache, live Chromium
without any custom request headers:
- EN/ES 390px: 45 and 43 cards, CLS 0, native signed Ajax HTTP 200.
- EN/ES 1920px: 51 and 49 cards, CLS .00042 and .01596, HTTP 200.
No JS errors, horizontal overflow, or missing cards were found in
these cohorts.

## Boundaries and default

The site option defaults remain auto. Native market, WPML locale, post and
Elementor source hashes, style CSS SHA, query generation, responsive
breakpoint and seven-day evidence validity must match. Missing or changed
proof returns full server-rendered HTML. Other markets and other companies
are NOT automatically certified. Do not reuse DE-USD proof as EUR or EGP.

The internal Nginx same-IP private FastCGI microcache still hits near
20-24 ms for eligible repeat requests; PHP cold TTFB remains ~4-6 seconds.
No shared browser/CDN private-market cache is enabled by these changes.
Separate Tours.dev Critical CSS certificate can be missing for other
Accept/UA variants and is not considered globally certified.

No automated browser re-certification service is added in this patch.
After seven days, certificate expiry safely restores SSR until another
browser QA rollout. This is explicit, not a claim of perpetual Lazy wins.

## Rollback

Live rollback and report backups:
 /opt/mad4b-company-provisioning/ops/default-ip-market-cache-20261116/
 - native-lazy-v1015.rollback-for-v1016.php
 - native-lazy-v1014-conflict.rollback.php
 - layout-proofs.pre-v1016-release.json

This change is generic source code. Never overwrite a newer WordPress
company runtime or apply the site-specific Nginx config to Wayfatours.
No GitHub push or Production deployment was made by this task.

## Automatic renewal follow-up — 2026-11-16 (staging scoped)

The original v1.0.16 release did not install a recurring renewal worker.
A separately reviewed follow-up under auto-renew/ installs a daily bounded
native browser audit after the new Query Governor v1.4.2 membership scope.
It refreshes only the currently valid DE-USD EN/ES certificates after
the complete 12-viewport SSR-vs-signed-AJAX comparison.
It does not certify other countries or automatically re-admit a missing,
expired, held or source-diverged proof. Existing SSR fallback is preserved.
