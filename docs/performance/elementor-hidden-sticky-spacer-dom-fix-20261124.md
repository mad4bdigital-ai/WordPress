# Elementor Sticky hidden-spacer DOM duplication — root-cause fix (24 November 2026)

## Exact cause
On the Wayfatours **Spanish** version of Tour #60623, a desktop-only Elementor sticky inquiry column is hidden by responsive classes on 390px mobile, but Elementor Pro's first-party MAD4B sticky lifecycle adapter still runs `addSpacer()` and calls `$element.clone()` on that entire hidden container. The deep clone includes Fluent Form 6. The second, unusable duplicate is renumbered `fluentform_6_sticky_spacer_1` and marked inert, but its **1,457 descendant DOM nodes** remain. That clone inflates live DOM (~6,657 vs ~5,200), repeats native form fields and adds needless jQuery and layout work. The original form and all booking calculations must remain untouched.

## Corrective code
Existing first-party script `wp-content/mu-plugins/assets/mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js` was renamed to the content-addressed **`9ebb20e0bb022722.js`** successor. No vendor Elementor/JetEngine files were modified. The existing fail-closed selector in MU plugin `407-mad4b-elementor-sticky-lifecycle-guard.php` accepts exactly one asset whose filename matches the first 16 chars of its own SHA256; no loader changes or activation switches.

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
- Source Tours.dev first; hash `9ebb20e0bb022722b5b10cf9bc7372edfcd80bd26671498f81208f67f127899d`.
- Wayfatours second, same content hash, backed up original per company.
- LiteSpeed generated a new optimized JS artifact containing the patched first-party code without a global purge; verified **source** bundle `ed07a67fa8703a8a8aceca7f82e0dddd.js`, **Wayfatours** bundle `8461d0e4ad3cadced476db04b96aec86.js`.
- Existing coordinator `MAD4B_Cache_Control_V1::socket_purge` versioned only published singular Tour paths: Tours.dev **221** / Wayfatours **84**, all batches `ok`, with CSS, JS and Redis-wide purge all `false`.
- Final **public canonical URL, no diagnostic cookie** Chromium smoke: Wayfatours ES 390px DOM **5,196**; one Form 6; 0 sticky spacer form clones; 44 menu cards; price, TOC present; zero JS errors. Wayfatours ES desktop, EN mobile, Tours.dev ES mobile, Tours.dev EN desktop all HTTP 200, correct Form 6/menu/price/TOC and zero JS errors in the samples.
- Hermetic Node test `tests/performance/test_elementor_sticky_hidden_spacer.js`: actual extracted `addSpacer()` body, 3 scenarios `display:none|flex|block` PASS; real original unchanged; correct hidden CSS and insertion. Node syntax `--check` PASS.

## Safety, rollback, remaining limitations
Both live original JS copies retained at:
- `/opt/mad4b-backups/sticky-hidden-placeholder-20261124/source/original.js`
- `/opt/mad4b-backups/sticky-hidden-placeholder-20261124/wayfa/original.js`.

Rollback: atomically restore `mad4b-elementor-sticky-lifecycle-c77a2b2fbdc39daa.js` under MU assets and remove the `9ebb20e0bb022722.js` file so exactly one matching content-addressed JS exists, then only regenerate affected Tour HTML path generations. Avoid indiscriminate CSS V2/Redis/LSCache purges.

**Do not mark overall Page Unresponsive fixed solely by DOM reduction.** The page's LiteSpeed/Elementor/jQuery runtime still showed >2s aggregate long-task blocking under 3x CPU throttle in an earlier public test, and the owner's authenticated WordPress browser session was not controlled from Chat mode. Origin PHP still measured ~3.7–4s on menu fragment HIT. These require separate performance receipts.

## Desktop duplicate Form 6 and full breakpoint-resize parity (final successor)

A deeper on-site inspection showed that even **visible desktop** Sticky containers duplicated the full 1,457-element Fluent Form 6 into the hidden spacer *after scrolling*. The final successor `9ebb20e0bb022722.js` extends the narrow fix:

- When `getComputedStyle($element[0]).display === 'none'`: keep the empty shell and zero height (as before).
- When the element is visible and `$element[0].querySelector('form.fluent_form_6')` exists: keep an **empty shell** but set its border-box `height` and `minHeight` to the **real `getBoundingClientRect().height`** at the moment the Sticky spacer is inserted.
- For all unrelated visible Sticky elements, keep the original full Elementor clone, classes, CSS and after-insertion.
- The original Form 6 never moves or loses its input values. Its event handlers and commercial state are not re-created for the spacer.

Controlled Wayfatours ES desktop 1366px, after deep scrolling:

| Metric | Before (mobile-only fix) | Final desktop+mobile fix |
|---|---:|---:|
| DOM nodes | 6,780 | **5,323** |
| Duplicate Form 6 in Sticky spacer | 1 | **0** |
| Real booking column height | 852px | **852px** |
| Spacer height | 852px | **852px** |
| Total document scroll height | 11,668px | **11,668px** |
| Mega Menu cards | 44 | **44** |
| JS exceptions | 0 | **0** |

The exact same geometry and zero duplicate Form 6 held after dynamic **desktop → mobile → desktop** resizing. Source Tours.dev ES mobile/desktop and Wayfatours ES mobile/desktop passed full smoke with price, Form 6 and TOC present, no JS errors. The public no-cookie Wayfatours canonical URL after selective cache versioning showed ES mobile DOM 5,196 and desktop-scrolled DOM 5,323 with no duplicate Form 6.

Live final file SHA256 on **both** sites: `9ebb20e0bb022722b5b10cf9bc7372edfcd80bd26671498f81208f67f127899d`. Previous mobile-only `bc8f...` copies retained at `/opt/mad4b-backups/sticky-visible-form-spacer-20261124/{source,wayfa}/previous-hidden-only.js`. Original upstream PR #895 SHA `c77a...` remains archived immutably under `ops/site-runtime/tour-mobile-canonical-source-20261119/archive/` and copied in per-site backups.

The canonical MU Source QA manifest was updated for the final SHA while preserving and independently verifying the immutable original SHA, upstream PR/head, and that **only the `addSpacer()` function differs**. Node tests additionally assert the original Form 6 is preserved, visible spacer preserves an exact 852px border-box and zero height is kept while hidden.

Again, this is a reproducible Chrome/DOM improvement, not a full authenticated-browser `Page Unresponsive` closure. No global CSS/JS/Redis purge, no production checkout submitted.
