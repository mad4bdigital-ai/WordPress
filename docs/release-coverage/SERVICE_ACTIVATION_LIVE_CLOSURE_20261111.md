# Service Activation live-state and public policy closure

Date: 2026-11-11. Source site: tours.dev.mad4b.com. Target tenant: wayfatours.com. Original 318/313 composite release remains HOLD. Site-local deployments do not constitute a signed central release.

## Reproduced root causes

- Wayfatours had separate tenant-specific MU policies for Elementor and CPT routing without the source platform's complete guest REST/Rate guard.
- Guest Tour Rate HTML and REST returned 200 on Wayfatours even though Rates are internal, while source denied them.
- JetEngine Service Activation persisted 19 option values on both sites, with inherited fallbacks. Registered admin fields were 31 on source and 26 on Wayfatours.
- Unified Booking Fulfillment V2 worked on source but was missing on Wayfatours.
- A published Tour offered a misleading Book Now anchor to Fluent Form 6, which sends a request but does not certify a booking.
- Central Company Service Catalog is a plan, not remote tenant activation or live readback.

## Applied and tested

1. The site-neutral Service Public Entry Guard is deployed and SHA-matched on both sites. It denies guest internal Rate HTML, REST, search and sitemap entry points, plus standalone service routes excluded by local activation policy. Editors retain access.
2. A read-only Live Service Activation panel now appears on the JetEngine service-activation admin page, showing effective policy, source of settings, published content, fulfillment and payment readiness, and schema gaps. An opt-in button checks four guest HTTPS routes on the actual local site, and reports expected versus observed statuses.
3. The protected GET /wp-json/mad4b/v1/service-activation/live-status endpoint returns current local status with private no-store semantics to admins only; administrator=200 and anonymous=401 were verified on both sites.
4. The original Booking Fulfillment Policy V1 and Unified Booking Fulfillment V2 modules from the source were applied unchanged to Wayfatours. A published Tour gave effective inquiry_only, no ready instant booking, checkout_authority=false and payment_authority=false. Four native fulfillment controls are now registered, taking the Wayfatours schema from 26 to 30 fields.
5. A form-specific presentation bridge changes the Form 6 anchor Book Now to Request This Tour, and the Fluent submit label to the existing quote mode (Request a quote in English, Solicitar presupuesto in Spanish). It handles EN, AR, ES, FR, DE, IT at rendering time without saving changes to Fluent forms.

## Direct evidence

- Guest Wayfatours Tour Rate /tour-rates/176/ and Spanish /es/tour-rates/177/ now return 404 (previously 200).
- Guest Tour Rate REST and Transportation REST now return 404; published Tour page and Tour REST remain 200.
- Anonymous search subtype tour-rates returns 404, while authorized editors still receive 200 from Tour Rate REST.
- HTTP verification: four of four expected code matches on each site; source Property REST is correctly 200, while Wayfatours is correctly 404.
- Live Form 6 HTML and browser show Request This Tour and the request-mode submit button (Request a quote in EN, Solicitar presupuesto in ES). The old Book Now label no longer appears on the verified Form 6 anchor.
- WP admin panel smoke tests on source and Wayfatours passed four service rows, CSS/JS registration, HTTP verification control and current readback.
- Service Activation stored option hashes stayed unchanged: source 45b2a94eb85f0d1e7aba1ae3134c24d7bc5e5862586d978595fe4284fe0d6dc2; Wayfatours f95a833e5f07ccd65d810d74e5ac36c32c7067f921e484011202040439efeb86.
- Stripe and Early Payment Benefits stayed off. No customer mail, actual payment, booking, price update or service settings write occurred.

## Remaining independent tracks

- Source exposes 31 Service Activation fields; Wayfatours now has 30. The unregistered shared_tour_presentation_mode depends on a pricing presentation adapter not active on Wayfatours; the Live Readback UI explicitly marks this as a schema gap rather than giving the user a control with no runtime effect.
- Legacy Wayfatours MU service modules still have tenant/environment-specific guards; these govern additional Elementor and JetEngine behavior and cannot be blindly removed until functional parity is demonstrated with a neutral replacement.
- Central Company Manager does not yet consume the new remote state through a certified governed transport. It must continue distinguishing planned catalog state from observed local execution.
- Logged-in WordPress admin graphical browser acceptance, real checkout, and signed Original 318/313 release certification were not claimed.

## Follow-up: language-accurate asynchronous form submit

- The preexisting MAD4B Tour Form6 Submission Mode JavaScript is the runtime authority for quote_only versus advance_checkout. Initial UI hooks previously used different wording, and browser QA exposed a temporary English fallback on the Spanish page.
- The source plugin now exposes one translated wording map to initial Form 6 HTML and inline JS configuration. The browser uses language-accurate text before the HTTP result and whenever the readiness endpoint is unreachable; valid server responses continue to govern the effective mode.
- Source reconciliation preserves two stricter guards already in local Git: verifying _request_source_id on an associated advance order and requiring awaiting_payment before optional pay URL display. No older live source overwrote these security conditions.
- Real Wayfatours Spanish browser QA at 390px passed both before and after delayed readiness: Solicitar presupuesto, quote_only, no overflow. Aborted readiness requests kept Spanish disclosure, with no order-pay link.
- The native Form6 JS asset was incorporated into Git, avoiding source-only changes that disappear from future releases.
