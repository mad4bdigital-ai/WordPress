# Early Payment Benefits + WooCommerce-native lifecycle mail — local source candidate

**Captured:** 2026-11-11. **Release:** HOLD. No live WordPress, WooCommerce, SMTP, Stripe, company data or customer message mutations.

## Functional contracts

- Company-wide Early Payment Benefits default OFF; rate/tour override ON cannot defeat a disabled company switch.
- Service loyalty benefit only: no automatic monetary discount, no confirmation of tour booking, six languages with English fallback. Immutable offer terms are fingerprinted on an advance order; fulfilment/payment/refund state is stored separately. Manual benefit fulfilment needs a request-operations permission.
- WooCommerce email type mad4b_request_lifecycle registers as manual, reuses WooCommerce's email wrapper and sends NOTHING on its own. Existing RequestLifecycle single queue, sender policy and mail idempotency receipt remain authoritative. The HTML payment CTA requires an eligible payment gateway, an advance order, outstanding payment and supported lifecycle event.
- Tours.dev had no emails/plain/mad4b-request-lifecycle.php. This candidate adds a local-only plain template, and one narrowly reviewed change to the email class to implement get_content_plain() for native WooCommerce email PREVIEW; WooCommerce base's default method returns an empty string. The plain template strips an embedded old raw payment URL even after an order becomes paid, and emits a single new verified URL only when the order is an eligible advance requiring payment with a currently eligible gateway. It never sends mail or creates a new sender. **Current runtime RequestLifecycle still dispatches HTML**, not plain text; this does not claim plain-text delivery was activated.

## Source provenance

Five live source files were imported: two direct MU PHP files, the WooCommerce HTML template, and two matching CSS copies. Four remain byte-exact to Tours.dev; the email-class PHP has exactly one reviewed semantic patch. The plain template is one new candidate-only file. Strict source SHA-256 + Git object digest readback, individual file path allowlist, and candidate-only digest checks are in early-payment-woocommerce-email-source-v1-20261111.json and verify_early_payment_woocommerce_source.py.

Direct MU manifest now covers 164 PHP paths (two new live PHP sources and exactly two independently reviewed patches: native transport signature verification and WooCommerce plain-text preview). It does not turn on automatic MU activation or authorize deployment.

## Tests, limitations and blocked release

- Offline PHP tests: 51 loyalty cases (including idempotent manual fulfilment and native board task reversal) + 25 WooCommerce email cases; no SMTP/Stripe/WordPress runtime.
- Python provenance tests guard both hashes and deployment authority; PHP lint checks native rendering sources.
- No browser, real Fluent Form #6, payment, refund, WPML multi-market, WooCommerce live gateway or WooCommerce mail transport end-to-end test has yet passed for this release.
- Tours.dev still has divergent Form #6, media source changes and other unreviewed executable sources; the top-level source inventory will continue to HOLD until those differences are reconciled.
- Original318 authority issue/revoke and signed independent Original313 receipt remain unproven. No real release is authorized.
