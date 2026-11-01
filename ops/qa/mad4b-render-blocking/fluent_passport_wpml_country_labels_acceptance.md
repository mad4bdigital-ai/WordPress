# Passport country WPML localization - Staging acceptance, 2026-11-01

## User-visible issue

The passport country switcher and Fluent Forms nationality fields repeated English country names across WPML languages. WooCommerce stored correct ISO2 keys but its country display labels remained English. Three booking forms (#6/#7/#8) stored 195 historic English demonym values. Changing those stored values could break order integrations and historical submissions. The native passport REST search also treated accented letters asymmetrically, using strtolower for the incoming query and mb_strtolower for the labels.

## Implemented on Staging

- New WPML country-label MU file: wp-content/mu-plugins/327-mad4b-fluent-passport-locale.php, gated to the precise tours.dev.mad4b.com Staging site UUID. Native WooCommerce country keys (250 countries) and checkout/session/market values are unchanged. Only visible names use PHP Intl regional translations for EN/FR/DE/AR/ES/IT. This feeds the passport switcher and approved market-country selector.
- Fluent Forms #5 uses existing ISO2 select_country and receives translated display labels without changing the submitted country code.
- Forms #6/#7/#8 preserve all 195 English legacy submitted values, protected by an exact SHA256 check of the ordered option values. Their displayed labels become translated country names, and an existing explicit passport preference is converted to the corresponding historical selection.
- Tour/inquiry forms #3 and #11 and future tour/booking/reservation forms that lack a passport country field receive an optional select_country at render time. Newsletter/help-center forms remain untouched. Existing nationality fields are not duplicated; form records are not changed in the database.
- New ISO2 country fields and legacy values preselect a saved manual passport choice. An event handler also synchronizes unedited form fields when the header preference is saved on the same page, respecting deliberate user edits and Fluent Forms Choices.js UI.
- The native nationality.js was corrected to send localized searches without rewriting the input to English; the passport REST PHP handler now uses Unicode-aware mb_strtolower for both query and label. The first-paint guard MU #326 has an updated source hash/version matching the new script.
- The GitHub source passport plugin previously lacked five passport-preference/search methods present on the live Staging site. Source reconciliation restores those existing live methods, preserving the existing API after a later source-based deployment.

## Acceptance

- 250 WooCommerce country keys with EG/DE/US rendered in six languages, e.g., EG: Egypt, مصر, Égypte, Ägypten, Egipto, Egitto.
- Playwright 390px native select: 251 options including placeholder in all six languages. The localized query for Egypt returns a single EG option with the localized label for all six including Arabic and accented French/German. REST HTTP 200.
- Fluent Forms rendered under each WPML language for IDs 3,5,6,7,8,11, exactly one passport field per form. Form 5 and new forms use EG; legacy forms 6/7/8 submit Egyptian but display the translated country name. No form-row mutation.
- An isolated signed test cookie preselected EG across all six forms and languages, without writing a saved session. The event test selected Egyptian and EG on the corresponding forms; a later saved preference did not overwrite a user-edited German choice.
- PHP syntax, JS syntax and isolated source-policy tests pass. The pre-existing anonymous first-paint nationality skip continues working for all six languages.

## Rollback and boundaries

Staging evidence and original copies are under /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/fluent-passport-locales-20261101/. The rollback script is site-and-checksum guarded, not executed. It restores nationality.js, first-paint MU #326 and native passport PHP, and removes the new MU #327 together. Staging only; Production and customer/order tables untouched.

The earlier Lighthouse waterfall savings and the independent first-cold PHP TTFB are not claimed as closed by this internationalization fix.
