# MAD4B Form 6 — Thank-you advance payment redirect (v1.3.0)

## Scope and source ownership

The canonical **source** is now the site-neutral MU plugin and two assets in
`wp-content/mu-plugins/`; the three files were reconciled byte-for-byte from
the running Tours.dev and Wayfatours installations on 24 November 2026.

- `mad4b-tour-form6-submission-mode-v1.php` — existing Fluent Forms #6
  confirmation renderer and WooCommerce-native admin setting.
- `assets/mad4b-tour-form6-submission-mode-v1.js` — existing Form 6
  readiness copy plus the bounded thank-you redirect timer.
- `assets/mad4b-tour-form6-submission-mode-v1.css` — scoped form and
  confirmation/timer styles.

This is a reconciliation of an already-installed runtime module, **not** a
new payment gateway or an authorization to charge a customer.

## Admin settings

Open **WooCommerce → Settings → Request advances**, section
**Form 6 · Thank-you payment transition**.

| Mode | Behavior |
|---|---|
| `stay` (default) | After the request is saved, remain on the thank-you panel and offer the verified WooCommerce advance link manually. |
| `countdown` | After the request is saved, show a 1–15 second countdown and allow the visitor to cancel; navigate only to the verified same-origin WooCommerce order-pay link. |

The existing option `mad4b_form6_thankyou_redirect_v1` stores
`{"mode":"stay","seconds":15}` on both websites at reconciliation.
The setting is **per company**: changing it on Wayfatours must not silently
change Tours.dev. Only a user with `commerce_manage_policies` can save
it through the existing Woo settings tab with a valid settings nonce.

## Safety and operational contract

1. Fluent Form #6 saves its submission **before** the Commerce adapter tries
   to prepare any advance-payment Order. A failed payment preparation must not
   invalidate the saved enquiry.
2. The server emits a countdown only when the submission matches exactly one
   relevant Woo Order and `RequestAdvancePayments::safePaymentUrl()` has
   verified the saved payment reference, outstanding state, source and rate,
   total and native same-host WooCommerce payment URL.
3. The browser independently checks HTTPS, same origin, `order-pay` route,
   and a payment key. It cannot create a payment URL or amount.
4. The countdown can be canceled by the visitor; it pauses when the browser
   tab is hidden, stops on detached confirmation markup and avoids duplicate
   timers. The regular **Pay advance** link stays available.
5. A historical Order tied to a Draft Rate remains blocked from exposing an
   unusable payment link. This setting **cannot** bypass pricing readiness.
6. The payment itself is optional at submission. Payment/receipt handling and
   percentage remain under existing WooCommerce / company pricing policies;
   neither a redirect nor an advance confirms availability or the trip.
7. `stay` is the backward-compatible default, including when a setting is
   missing, invalid or non-scalar.

## CI regression

Run from the repository root:

```sh
php -l wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php
php tests/form6-thankyou-payment-redirect-v130.php
node --check wp-content/mu-plugins/assets/mad4b-tour-form6-submission-mode-v1.js
node tests/form6-thankyou-payment-redirect-v130.js
```

The first regression is a standalone hermetic PHP fixture (seven success,
failure and 1–15 second cases). The second uses a simulated browser DOM to
test eight cases: normal countdown, cancel, cross-origin rejection, no Woo
key, wrong route, 1-second, no duplicate mount, and background-tab pause.
Neither test sends email, saves WordPress content, creates Orders, calls
Stripe or handles live payment credentials.

Workflow: `.github/workflows/mad4b-form6-thankyou-redirect.yml`.
VPS proof: `/opt/mad4b-company-provisioning/reports/form6-thankyou-countdown-redirect-v130-20261124/manifest.json`.

## Release boundaries

At source reconciliation the two live sites still use `stay` with the
configured 15-second value. The historic pending advance Orders are not
changed. No real browser end-to-end Form 6 submission or payment was made by
these tests. GitHub PR #1046 remains Draft until all applicable CI and
independent exact-head owner release authorization pass; this document,
tests and PR comments do not constitute that authorization.
