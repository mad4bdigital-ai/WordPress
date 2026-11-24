# Elementor Sticky hidden-spacer DOM duplication — root-cause fix (24 November 2026)

## Exact cause
On the Wayfatours **Spanish** version of Tour #60623, a desktop-only Elementor sticky inquiry column is hidden by responsive classes on 390px mobile, but Elementor Pro's first-party MAD4B sticky lifecycle adapter still runs `addSpacer()` and calls `$element.clone()` on that entire hidden container. The deep clone includes Fluent Form 6. The second, unusable duplicate is renumbered `fluentform_6_sticky_spacer_1` and marked inert, but its **1,457 descendant DOM nodes** remain. That clone inflates live DOM (~6,657 vs ~5,200), repeats native form fields and adds needless jQuery and layout work. The original form and all booking calculations must remain untouched.

## Corrective code
Existing first-party script `wp-content/mu-plugins/assets/mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js` was renamed to the content-addressed **`bc8f3f18efcfa4a1.js`** successor. No vendor Elementor/JetEngine files were modified. The existing fail-closed selector in MU plugin `407-mad4b-elementor-sticky-lifecycle-guard.php` accepts exactly one asset whose filename matches the first 16 chars of its own SHA256; no loader changes or activation switches.

Only `addSpacer()` changes:
- If `getComputedStyle($element[0]).display === 'none'`: shallow-clone the element shell and immediately `.empty()` its descendants before adding the normal spacer class and CSS.
- If visible: preserve the **unchanged complete existing deep clone**, its CSS, element placement and behavior, including desktop Sticky movement and resize.
- Keep the original live Form 6, payment links, Elementor plugins and all app event listeners untouched. Never remove an actual field, nor modify `fluentform_6` values or IDs.

This is a *presentation lifecycle fix*. It changes neither prices, rates, currency, admin permissions nor checkout.

## Evidence (source-first A/B and two live sites)
Controlled browser-only A/B on Wayfatours Spanish mobile 390px:
| | Original Sticky | Lightweight hidden spacer |
| --- | ---: | ---: |
| DOM at initial load | 6,657 | 5,200 |
| Descendants of hidden cloned inquiry column | 1,457 | 0 |
| Real Form 6 duplicates | 2 | 1 |
| Menu cards | 44 | 44 |
| JS exceptions | 0 | 0 |

The desktop 1366px control preserved existing full visible spacer clones after scrolling, ensuring no desktop layout behavior changes.

Live rollout:
- Source Tours.dev first; hash `bc8f3f18efcfa4a1022d1d2de6c030d7be1445318358a6607e48d9895199cf53`.
- Wayfatours second, same content hash, backed up original per company.
- LiteSpeed generated a new optimized JS artifact containing the patched first-party code without a global purge; verified **source** bundle `fbf190a33686e938ee3cd9bce37deacb.js`, **Wayfatours** bundle `820fc5d577c4af157c944e3c8c506e40.js`.
- Existing coordinator `MAD4B_Cache_Control_V1::socket_purge` versioned only published singular Tour paths: Tours.dev **221** / Wayfatours **84**, all batches `ok`, with CSS, JS and Redis-wide purge all `false`.
- Final **public canonical URL, no diagnostic cookie** Chromium smoke: Wayfatours ES 390px DOM **5,196**; one Form 6; 0 sticky spacer form clones; 44 menu cards; price, TOC present; zero JS errors. Wayfatours ES desktop, EN mobile, Tours.dev ES mobile, Tours.dev EN desktop all HTTP 200, correct Form 6/menu/price/TOC and zero JS errors in the samples.
- Hermetic Node test `tests/performance/test_elementor_sticky_hidden_spacer.js`: actual extracted `addSpacer()` body, 3 scenarios `display:none|flex|block` PASS; real original unchanged; correct hidden CSS and insertion. Node syntax `--check` PASS.

## Safety, rollback, remaining limitations
Both live original JS copies retained at:
- `/opt/mad4b-backups/sticky-hidden-placeholder-20261124/source/original.js`
- `/opt/mad4b-backups/sticky-hidden-placeholder-20261124/wayfa/original.js`.

Rollback: atomically restore `mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js` under MU assets and remove the `bc8f3f18efcfa4a1.js` file so exactly one matching content-addressed JS exists, then only regenerate affected Tour HTML path generations. Avoid indiscriminate CSS V2/Redis/LSCache purges.

**Do not mark overall Page Unresponsive fixed solely by DOM reduction.** The page's LiteSpeed/Elementor/jQuery runtime still showed >2s aggregate long-task blocking under 3x CPU throttle in an earlier public test, and the owner's authenticated WordPress browser session was not controlled from Chat mode. Origin PHP still measured ~3.7–4s on menu fragment HIT. These require separate performance receipts.
