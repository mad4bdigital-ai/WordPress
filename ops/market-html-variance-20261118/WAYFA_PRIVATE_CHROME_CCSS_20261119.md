# Wayfatours normal Chrome and private Critical CSS — 19 November 2026

## Actual persistent Chrome profile

The browser test used persistent Chromium user data, 390px mobile viewport,
4x CPU throttling and a slow network, not Incognito. Consent was saved in
Local Storage and retained across the archive journey and Chrome restart.
No actual personal WordPress login cookie was used.

| Journey | Edge | LCP | ResponseStart |
| -- | -- | -- | -- |
| First home | HIT | 1.78s | 94ms |
| Archive, same profile | BYPASS | 26.2s | 3899ms |
| Home return, same profile | MISS | 5.40s | 4032ms |
| Home after restarting same profile | HIT | 1.80s | 29ms |

Home has 506137 bytes of inline Critical CSS, and the asynchronous
full CSS took around 7.3-8 seconds to load in the throttled run.
No page JavaScript errors in this controlled browser test.
This does NOT prove the precise user's logged-in Chrome performance.

## Verified CSS source mismatch

Original live 415-mad4b-critical-css-orchestrator.php v0.3.22 excludes
is_user_logged_in and unsafe cookies. It differs substantially from
Git's newer 415 v0.3.29; do not downgrade or overwrite the original.

Anonymous bundle 3aea57d511a807f5a35944a7c038f6da.css has an
accepted SHA certificate (prefix 3159ff7dd9a1). Private cookie
requests instead generated unqualified 436bc90ea20698cb4bce1aea972f4940.css,
and repeated same-cookie requests produced different bundles
5ab0062e94d8b6b6883f381fc5b6efc9.css and
7f53d1bc1e55f2a0284ede5699f71315.css. Those native
CSS files differ through JetEngine listing item CSS and a changing
set of destination items. Reusing public CSS SHA for them is unsafe.

## Applied independent source-first extension

The new MU plugin 436-mad4b-private-session-critical-css-v1.php is
installed on live Wayfatours Production, matching this Git copy.
It is OFF by default and has NO accepted private CSS manifest, so
no current visitor is changed. It adds the permanent path for
private sessions once genuine evidence is available:

- Actual authenticated WordPress user, not just an unknown cookie.
- Exact production company, mobile EN or its separately certified WPML
  locale, canonical home GET; excludes Woo session, spoofed market
  headers, nonhome, admin and preview.
- Source bundle must match original CSS filename and full SHA.
- A distinct, browser-certified private CSS file <=250KB with its
  own exact SHA and both 390px / 768px visual+interaction proof.
- Final HTML is strictly private/no-store with FastCGI admission
  headers removed. Missing or mismatched certificates fall back
  to the complete original blocking CSS.
- Isolated PHP regression: 21/21 pass. Live PHP lint and source
  byte parity pass. Anonymous home remained HTTP 200, HIT and CSS Ready 1.

The private-session feature must remain off until original
JetEngine dynamic CSS volatility is isolated and the independent
browser visual/interactivity proof has passed.

## Remaining performance engineering

1. Separate changing JetEngine listing-specific CSS from stable
   Elementor template CSS without freezing genuine dynamic listings.
2. Derive and certify private CSS <=250KB, then enable private-session
   opt-in and measure real logged-in (non-Incognito) Chrome parity.
3. Collect used-rule coverage for the 506KB anonymous Critical CSS
   before reducing it; retain original source for rollback.
4. Review slow archive and home MISS path independently: a private
   CSS upgrade cannot make private HTML eligible for FastCGI.

To roll back the new module, remove only MU plugin 436 and
leave the private-session option off. Existing 415, 414, 424,
WooCommerce, WPML and Nginx were not altered.
