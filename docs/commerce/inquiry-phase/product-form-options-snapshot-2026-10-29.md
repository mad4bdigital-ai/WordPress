# Product-page Fluent Forms — immutable inquiry selections and catalog-rate reference

**Date:** 2026-10-29  
**Target:** ETG WooCommerce Lab only (`local`, database `etg_woocommerce_lab`)  
**Source:** Commerce Composition, stacked on PR #602 / #600.

## What is implemented

1. `ProductFormSourceReceipt` renders a short-lived (1-hour), site-bound, form-bound, product-bound, HMAC-signed hidden receipt **only** when Fluent Forms is rendered on a server-recognized product page and `FormRouter` approves its Form ID and real Rate-to-parent relationship. The receipt is checked via `fluentform/before_insert_submission` and converted to a **trusted PHP context** consumed by the existing `SubmissionContext::capture` hook. A visitor cannot make a submitted product ID authoritative by editing a hidden field. Invalid signatures, mismatched forms, expired receipts, disabled booking policies and misrelated Rates fail closed. The source token never includes customer PII.

2. `InquirySelectionSnapshot` records a **signed immutable snapshot** at `fluentform/submission_inserted` priority 110, after the existing source capture at priority 80. It uses the **original saved Fluent Forms response**, the published Form's declared schema and optionally the verified product/Rate context. Supported selected fields include select, checkbox, radio, CPT selections, dates and quantities. Selection values and labels are retained with their verification state, subject to strict bounds. Undeclared fields, free-text notes, name, email, phone, and hidden fields are not copied to the snapshot. Some selected attributes (for example nationality) can still be personal data, so **readback is administrator-only** with a `private,no-store` REST response.

3. The source-bound Rate is verified with `NativeOrderItemIdentityResolver` and a contemporaneous `wc_get_product(rate)->get_price()` reference is stored where numeric. The snapshot does **not** assert an all-inclusive estimate, currency conversion for the visitor, unit certainty, taxes, child pricing, extras price, availability, supplier booking or financial finality. `policy_selections_verified` and `selection_digest` record approval by `FluentSubmissionAdapter::prepareSubmissionIntent` only when this existing provider contract verifies options. Unverified CPT selections are preserved **as visitor-submitted values**, not authoritative products or add-on prices.

4. `wp_commerce_inquiry_snapshots` uses a unique `(form_id, submission_id)` key, immutable write-once logic, and an HMAC seal over canonical saved JSON. Replay with changed price or selections returns `inquiry_snapshot_immutable_conflict`; previous values cannot be overwritten by later product/catalog changes.

5. Woo Lab configuration `commerce_inquiry_snapshot_policy_v1`: enabled for published Form IDs **1,2,3,5,6,7,8,9,11**. Form #4 remains excluded. Source receipt only appears on product-page forms with an approved server-side policy. Does not require separately editing or replacing the existing Fluent Forms templates.

## Tests performed

- **26/26 PASS** — captured Form #6 itinerary/date/passenger quantities and catalog Rate proof, rejected tampered submission mutation, confirmed no customer email/name/phone/free text in snapshot; captured Form #7 additional choices and CPT fields as unverified where no Rate proof exists; read authorization enforced; SQL rollback kept all counts unchanged.
- **12/12 PASS** — signed hidden receipt rendered and checked for matching Form #6 / product #66996 / Rate #67082; forged receipt rejected, server-owned SubmissionContext captured, native `fluentform/submission_inserted` hook captured snapshot, with complete SQL rollback. Clean final test reported no PHP warnings.
- Standalone test files and JSON proof reside on VPS in `/opt/commerce-components/evidence/etg-inquiry-options-20261029/`, especially `options-final.json` and `saved-hook-clean.json`.
- A broader synthetic CLI simulation of the plugin's **entire form insertion process** was rejected by Fluent Forms' own submission guard, so **a browser-based real product-page submit is not yet certified**. Do not treat direct hook coverage as public-browser acceptance.
- No Woo Orders, payments, FluentBoards tasks, supplier bookings or customer emails were created. Tests used SQL transactions and rollback.

## UI and release gates still open

- Full product-page **browser** verification for actual Elementor/JetEngine-rendered form and AJAX submit, including whether the receipt is serialized through every Form mode (particularly conversational/multistep).
- Customer-visible **total estimated price** at submit, inclusive of selected add-ons and exact local currency/unit. Current snapshot is **only a reference catalog Rate** + options, and the existing inquiry readout correctly keeps `estimate_form_display_bound=false` and `estimate_immutable_snapshot_persisted=false` for *customer-visible final calculated estimate*.
- FluentSMTP: zero outbound connections configured in Woo Lab; email delivery not tested.
- A real operational request → FluentBoards staff assignment → closure acceptance.
- The Woo Lab public hostname presently reaches a different Staging site; do not assume the browser URL is this Lab or add credentials on the wrong site.
- GitHub CI and owner-certified staging release for PRs #600, #602, and this stacked candidate. **No Production mutation.**

## Operational controls

Private admin read: `GET /wp-json/commerce-composition/v1/management/inquiry-snapshot/{form_id}/{submission_id}`. Only WordPress administrators can read. The snapshot must not be sent to external ChatbotX, CRM marketing automation or email without separate purpose/permission review.

Disable Woo Lab snapshot capture (leaving historic snapshots intact) by setting `commerce_inquiry_snapshot_policy_v1.enabled=false`. To revert source, remove registration lines and the two modules. Do not delete submitted records as part of code rollback.
