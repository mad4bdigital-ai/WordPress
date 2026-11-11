# Native Service Activation Readback — center and Wayfatours

Date: 2026-11-11. Scope: tours.dev.mad4b.com and wayfatours.com only. Other companies remain outside scope pending reconciliation or Full Clone.

## Implemented

- An enrolled-host-scoped same-VPS WordPress CLI reader obtains a challenge-bound WordPress service snapshot from Wayfatours as www-data. It first checks the center production registry, Site UUID, HTTPS origin and company observation pilot scope. No externally transmitted WordPress admin credentials or new OAuth flow.
- Source and Wayfatours service-readback module SHA-256, plus dynamic JetEngine schema count, must agree before the result is accepted. The host captures the actual runtime modes, published counts and fulfillment/payment indicators. The host never changes tenant records.
- The host writes only one private center WordPress option containing the source WordPress JSON snapshot, content hash, scoped company identity and expiry. The center checks the same binding, snapshot contract, site module hash and freshness before display.
- The admin-only center endpoint for native-observation is private and uncached. A separate central company UI button reads the latest native WordPress state. The existing guest HTTP probe remains separate. Other company rows are deferred.
- A root-owned bounded systemd timer refreshes every ~240 seconds. Results expire at 360 seconds; failed or outdated readings are unverified, not activated.
- First-party Original 318/313 signed authority is NOT claimed. SHA-256 and a host nonce verify integrity and freshness, not cryptographic identity of the issuer. The center explicitly returns signed=false and plan_applied=false.

## Verified

- Wayfatours WordPress native snapshot: 31/31 Service Activation fields; modes Tours standalone_and_tour, Properties tour_only, Transportation tour_only, Visas disabled. Published: Tours 84, Properties 597, Transportation 7, Visas 0 at the measured instant. Stripe and Early Payment Benefits both OFF.
- The center returns fresh_local_wordpress_wpcli_unsigned, observed UTC and the registered Wayfatours Site UUID. Site plan remains tour_only, revision 0, Not Deployed.
- Native REST admin status 200; anonymous status 403. The central screen renders one native button for Wayfatours and two deferred reconciliation messages.
- In-process negative tests rejected a corrupted digest, an expired record and a rehashed payload with wrong source module digest. No production WordPress options were changed by these tests.
- Offline Python host tests rejected wrong enrollment scope and invalid identity while preserving read-only preflight.
- Systemd oneshot succeeded; timer was enabled. Bounded source provenance covers the exact central PHP, host Python worker, two systemd units and nonsecret host scope configuration.

## Original 318/313 blocker

The current official operation discovery returned no company.code.release provider, enrollment operation or governed write ability for the intended release. A recent center recovery plan also reports first-party trust enrollment incomplete and a signed publisher still required. No release grant or Original 313 independent signed postcondition receipt has been issued by this work.

The remaining official closure needs a first-party signed publisher/identity enrollment, an authorized exact-operation provider, a signed Original 318 release, and a separately verified Original 313 postcondition. A local Git commit or host readback may not substitute for either signature.

## Rollback

Disable the center-Wayfa native readback timer and stop its service. The center observation becomes stale within 360 seconds; no tenant changes need reverting. A center PHP preimage is preserved in the internal dated report path. Other company sites remain untouched.
