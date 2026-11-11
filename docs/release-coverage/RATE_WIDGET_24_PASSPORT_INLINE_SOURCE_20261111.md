# MAD4B Rate UI 2.4 / Passport inline v1.2 — source recovery and acceptance evidence

Captured 2026-11-11, local Git only. **State: SOURCE_ONLY / NO DEPLOYMENT**.

## Live Rate Widget provenance

- Tours.dev's public Giza Pyramids Half Day Tour HTML referenced mad4b-rate-widget-lifecycle-2.4.0-20261111.js.
- Wayfatours public HTML still referenced mad4b-rate-widget-lifecycle-2.3.0-20261111.js.
- The source site's publicly fetched v2.4 JS had exact SHA-256 ab93afe8f65db760631efc9364d83cefc03988dd8381251c18fee2e7385b1765, identical to its original MU asset.
- v2.4 only tries exported window.JetTabs.accordionInit(jq(widget)) when Elementor ready hook did not register click.jetAccordion; no custom accordion click implementation is introduced. The installed JetTabs plugin's jet-tabs-frontend.js contains that exported initializer and accepts $scope as its argument.
- The source-only Node DOM-stub tests cover first pointer click, existing handler, missing hook, exported JetTabs fallback, absent vendor export, missing jQuery, delayed AJAX and keyboard interaction; 6 scenarios PASS in v2.4, no duplicate handler.
- This is *not* proof of working accordion or Rate Select on live mobile browsers. The remote Playwright service rejected an independent Chromium launch. No changes were made on Wayfatours.

## Passport Search provenance

- Tours.dev changed mad4b-passport-country-ajax-v1.js and CSS from native dialog to inline combobox/dropdown v1.2, preserving native Fluent Form #6 select and original 195 option values.
- Exact live SHA-256 JS 66d150071d88d5f222115fc2d264ebdf88dbe402d7e89c0d9c508030f4d54384, CSS fb6cb1d61159bee46a6c0e9e863a2f8e4e12e3394206e03cde4d143c06e55849.
- JS retains normal field.value, native input/change events, aborting stale searches, 14-result bounded server lookup and fallback to original select. No form schema, paid booking status or saved submissions were changed by this source capture.

## Release controls

The four originally scoped live components now have 533 recorded PHP/JS/CSS paths and exact hashes. This snapshot is not global MAD4B branch-coverage certification, and omits unrelated modules, including wp-content/mu-plugins/shared-product-core.

Original318 v82 and Original313 v2 remain separately blocked signed-runtime deployment steps. Release state is HOLD pending authorized publishing and independent signed readback.
