# Wayfatours Mobile Prime v2: original CSS proof alignment

## Root cause

The mobile-home Nginx cache gate in the live company already accepts either:
`X-Mad4b-Mobile-Css-Ready: 1` (verified original critical+async CSS) or
`X-Mad4b-Mobile-Css-Native-Complete: 1` (verified complete, render-blocking
LiteSpeed native stylesheet). PHP `415` emits these distinct final-buffer
proofs; Nginx only caches when one is proven and all other visitor, cookie,
authentication, privacy and Set-Cookie checks pass. The old prime v1 required
critical async markers regardless of the accepted native path, causing
`RuntimeError:css` every scheduled run even on valid public cache HITs.

Live EN and ES mobile homepage responses contain exactly one complete native
LiteSpeed bundle each, with matching disk MD5 and the original WordPress
native-complete evidence. Their CSS SHA differs from the frozen historical
Critical CSS manifest; do not reuse old critical certs across CSS variants.

## Permanent fix

The replacement primer accepts *only* the same two explicitly proven CSS
modes as Nginx. Native mode independently checks exact trusted WordPress
final-buffer headers, body invariants, the single render-blocking
`data-optimized=2` bundle, allowlisted origin, local non-symlinked CSS asset,
file size and original LiteSpeed MD5 content address. Critical mode continues
to require all exact critical/async tags and trusted `Ready: 1` proof.
Contradictory/unknown headers, missing files, asynchronous native CSS, wrong
market host or stale hashes fail closed. The JSON private receipt states
`css_modes` for every enrolled language/cookie variant and separates
`css_certified=false` for native fallback. This neither edits WordPress
options nor marks the original Critical CSS manifest/Chrome QA as passed.

The source-owned systemd drop-in replaces only the Mobile Prime service's
`ExecStart`, retaining its original site lock, strict low-CPU/WPCLI admission,
15-minute randomized timer and resource limits. For rollback remove only
`/etc/systemd/system/mad4b-wayfa-mobile-prime.service.d/95-mad4b-css-origin-contract.conf`,
run `systemctl daemon-reload`, and leave original v1 script unchanged.
