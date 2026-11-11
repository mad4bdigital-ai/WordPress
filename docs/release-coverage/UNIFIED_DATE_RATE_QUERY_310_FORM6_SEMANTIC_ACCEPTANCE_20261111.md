# MAD4B Unified Date v0.2.5 and Form #6 – source-only semantic acceptance

**Date:** 2026-11-11. **Environment:** isolated local Git worktree. **Release status:** HOLD.

## Corrected date filter contract

The current Tours.dev 0.2.5 Unified Date source adds a bounded native JetEngine query 310 filter for rate-accordions (tour_rates / tour_rates_accordion) and a single visual date facade over JetSmartFilters provider #46259. The original JetSmartFilters control and its provider contract remain in the DOM while only the duplicate visible control is hidden after a successful facade mount.

The added Git semantic guard mad4b_unified_date_intersect_post_ids_v1() applies the selected travel window only as an AND filter to existing native results in three contexts: Tour Archive, JetSearch, and Tour Rates Query 310. It deliberately preserves post__in=[0] and invalid/denied original IDs, rather than treating an explicit deny-all as an empty unconstrained list and accidentally re-enabling ineligible rates. The filter also fails closed on invalid dates, unknown Tour IDs, non-published Rates, missing verified tour context, or lack of authorized rate associations. It does not set prices, availability, or booking rights.

An offline, fake-WordPress PHP integration fixture verified 15 cases including Rate Query 310 scope, valid and invalid dates, explicit native deny-all, intersection with package constraints, another query ID, missing verified Tour context, and no travel date. Source checks verify all three lanes use the same AND function. This is NOT actual JetEngine AJAX acceptance in a running WordPress site.

## Date UI and Form6

- The current live Unified Date JS/CSS source implements a single date field before price filters, with a WeakSet protecting Elementor popup clones, and routes Apply/Clear to canonical URL state. It removes the old advanced-date URL parameter; it never creates another pricing provider or Last Minute eligibility receipt.
- Form #6 source and server labels retain six translations (EN/AR/ES/FR/DE/IT). The request defaults to quote-only, expressly disclaims payment and booking confirmation, and removes a duplicate price note. The existing server readiness gate still owns any advance-checkout eligibility, with children/infants outside unpriced checkout flow.
- An additional five source tests verify native widget ownership, date URL normalization, original provider preservation, six-language user notices and non-authorization of payment.

## Reconciliation provenance

Original live source is separately fingerprinted in the Commerce manifest. Only one reviewed Commerce PHP file has a deliberate semantic delta from live: the original date source now calls a single fail-closed AND intersection function. The Commerce source verifier is explicitly constrained to that one path and to the exact reviewed Git blob SHA-1 and SHA-256, while requiring all other 268 files to match the live source. The 533-file platform, 161-file direct MU and 238-file additive plugin source manifests were also advanced to the latest observed 0.2.5 / Form6 wording with exact SHA-256.

No WordPress site, database, original runtime signer, company configuration, Stripe payment, WooCommerce order, or user authorization was changed by this Git reconciliation.

## Remaining acceptance gates

Browser click-through/keyboard interactions, JetSmartFilters AJAX, Fluent Form submit and pricing quote readback, and Company release verification remain untested. Original318 v82, real Host Issue/Revoke provider registration and independent Original313 signed receipts remain separate HOLD blockers. The rest of the global plugin/MU semantic conflicts are not resolved by date-specific tests.

**Do not distribute this Git tree as a live company plugin release before the governed publisher and those separate acceptance gates.**
