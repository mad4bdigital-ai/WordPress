# Nationality switcher first-paint optimization - Staging 2026-11-01

## Root cause
Lighthouse flagged the GET /wp-admin/admin-ajax.php?action=travel_booking_nationality_state (about 1.79 seconds in the supplied screenshot). The original nationality.js called update() every DOM ready. On anonymous first paint, WordPress already generated the passport nationality selector, current value, country flag, preference mode and localized text in HTML, so this repeated the state read. An independent mobile browser trial observed one such GET at roughly 600ms.

## Staging-only implementation
- The new MU #326, wp-content/mu-plugins/326-mad4b-nationality-server-first-paint.php, inserts a first-paint marker before the existing JS handle. Guarded by exact Staging host and site UUID, WPML homepage path, anonymous GET, no cookie, auth, custom currency, geo or query parameters, supported Woo base/default-currency settings and SHA256 of the companion nationality.js.
- The companion wp-content/plugins/etg-traveller-nationality-switcher/assets/nationality.js still enhances the search fields and handles existing preference events. It skips only its initial GET when the server marker is authorized, and dispatches header-preferences:state. Otherwise it performs the original native update(). The registered script URL version changes for the eligible request.
- Native per-request control: X-MAD4B-Nationality-Control: native-v1. Site-bound rollback: remove MU #326, restore nationality.js from /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/nationality-first-paint-20261101/nationality-before.js. No Production mutation.
- The first deployment correctly failed closed because the PHP worker could not read the updated JS file (root mode 660). Setting root:www-data ownership with 660 enabled the exact source hash check. Temporary diagnostics MU #384 removed.

## Browser acceptance
390px mobile Chromium native control against optimized Staging on six WPML homepage paths: EN, FR, DE, AR, ES, IT all changed from 1 nationality GET to 0, with identical original selector values, preference modes, flag state and searchable combobox enhancement. All six returned HTTP 200 without observed browser page errors.
Negative trials: explicit native control, cookie, alternate currency, geolocation override, filtered URL and archive each retained the original nationality request.
The search API /wp-json/travel-booking/v1/passport-options?q=Egypt&page=1 returned HTTP 200 with EG/Egypt. Browser inspection confirmed Egypt appears in the proper combobox. An initial false zero-options result came from inspecting the first hidden desktop combobox rather than the mobile one. No user country choice, passport metadata, price or booking state cached or changed.

Evidence and before-file rollback live on VPS:
 /opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/nationality-first-paint-20261101/

A reduction in one redundant anonymous request is confirmed; the 1.79s Lighthouse sample is not guaranteed end-user LCP/FCP saving. Origin cold PHP remains a separate unclosed issue.
