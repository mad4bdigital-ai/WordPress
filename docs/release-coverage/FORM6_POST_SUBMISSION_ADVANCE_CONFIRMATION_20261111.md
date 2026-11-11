# Fluent Form #6 nonredirect success + optional Woo advance panel

2026-11-11; locally reconciled source only; **HOLD**.

## Source behavior

Tours.dev Form #6 MU version 1.1.0 replaced its automatic Woo redirect with a rendered thank-you panel after verified Fluent submission. It uses the original Woo advance order resolved through RequestAdvancePayments.findBySource(6, submission ID) rather than creating or charging an order from a response callback. The rendered CSS marker mad4b-request-confirmation allows the separately configured optional Early Payment Benefits hook (priority 10070) to append its short text after the Form #6 panel hook (priority 10040). Quote-only responses still state no payment has been taken.

## Three additional reviewed candidate-only safety corrections

1. After deriving WPML's original Tour post language, recompute both the success body and headline label in that language. Source 1.1.0 computed body copy before correcting the language context; this caused English copy with Spanish labels.
2. Confirm the selected Woo advance order metadata source Tour ID matches the verified original Fluent submission context, in addition to existing form and submission metadata.
3. Display the Woo pay link only while the order explicitly remains in the awaiting_payment collection state, requires payment, has verified provider/Rate  eligibility, and the Tour currently has a usable native gateway. This avoids offering a second payment after a recorded advance when state and needs_payment temporarily disagree.

The pay link is the existing Woo URL and is restricted to the site host. All redirectUrl values are removed from an eligible successful request response. A wrong/duplicate order, unrelated form, invalid source, disabled gateway/rate or paid order does not expose a pay link.

The direct MU source inventory still has 164 PHP files. The Form #6 PHP is the **third** explicit, bounded, fingerprinted candidate overlay, alongside the optional transport native signature guard and WooCommerce plain-preview fallback; no generic allow-all source override was added.

## Validation and explicit limits

- Isolated PHP confirmation test passed 22 assertions including language, source identity, provider readiness, no duplicate/foreign link, no automatic redirect and refusal of unauthenticated source conditions.
- Python source integrity tests validate original Git preimage, exact live source digest and candidate digest, and the hook order with Early Payment Benefits.
- No real submission, order, payment, HTTP mail, cross-language browser/JetEngine AJAX transaction or company production change was performed.
- The native browser and signed Original313 release readback remain outstanding; the candidate is not authorized to deploy.
