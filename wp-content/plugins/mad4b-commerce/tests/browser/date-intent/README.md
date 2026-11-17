# MAD4B Unified Date - browser QA (source demo)

Run only on tours.dev.mad4b.com in the authorized Playwright QA image.

- unified-date-regression.test.js: base Rate selection, Draft/Apply, Fluent Forms, AJAX and count.
- unified-date-cross-page.test.js: archive, WPML, WCML and mobile Rate modal.
- unified-date-clear-wpml.test.js: Clear -> AJAX -> URL, form, chip, translated link and USD.
- unified-date-revision-race.test.js: delayed first AJAX response; last edit must win.
- unified-date-quote-guard.test.js: intercept read-only quote POSTs; Flex cannot be quoted as a fixed departure.
- unified-date-mobile-home.test.js: source home search on 320/390px and Go -> archive.

No tests submit Fluent Form 6, create an order or process a payment. The quote test intercepts POSTs before the server. Fixtures target source Tour 60629 and Rates 60820/60830; do not use on other companies unchanged.

Run one script at a time with Node.js + Playwright, using its file as /tmp/qa.js in the QA image.
- unified-date-fr-visible-chip.test.js: shows localized French Active Date chip after applying Package and verifies USD.
- unified-date-ajax-503-fallback.test.js: injects an HTTP 503 response into native JetSmartFilters AJAX and verifies GET reload safety.
