# MAD4B Experience — Native Brand Context / WPML

Date: 2026-11-07. Production: https://wayfatours.com. Source root: /opt/wayfa-rebuild-20261102/site-copy.
Staging tours.dev.mad4b.com retains its original MAD4B Platform Brand Context.

## Root cause
Wayfatours had MAD4B Experience installed, but company_brand_context() was absent, so the native Footer shortcode rendered a text wordmark. A production-scoped read-only probe on Spanish showed get_option(home) = https://wayfatours.com, while the translated home_url('/') was https://wayfatours.com/es/. MAD4B_Presentation_Experience::bound() compared the localized URL to canonical MAD4B_TENANT_ORIGIN, wrongly denied the site and never registered the provider for Spanish. The original HTTPS, environment and UUID checks were all valid.

## Implemented
- MAD4B Experience v1.2.6, optional Presentation feature company-brand-context, module 375-mad4b-native-company-brand-context.php.
- Native Look & Feel field mapping: mad4b_brand_logo_media, mad4b_brand_logo_inverse_media, mad4b_brand_emblem_media, mad4b_brand_favicon_media, mad4b_brand_name. Uses validated Media Library images and Elementor Kit colors; does not import demo identity or mutate company settings.
- Provider registered only if no original function exists, and only when MAD4B Experience is active and bound; avoids conflicts with an installed active MAD4B Platform.
- Request-only bounded memo per company blog and WPML language for anonymous public GET. No reuse in admin, signed previews, REST, AJAX, logged-in, WordPress CLI or write requests. In-request option/Kit changes invalidate the memo.
- Canonical stored WordPress home used for site identity check, rather than WPML-translated home_url; HTTPS/UUID/environment checks retained. Missing canonical value retains the original fail-closed fallback.
- Admin toggle: Settings > MAD4B Presentation Module > Company Brand Context. Local memo rollback: mad4b_native_brand_request_memo_v1=off.

## QA
- Native provider isolated contract: 18 PASS. Canonical binding exact-source contract: 10 PASS.
- Live WP-CLI: plugin v1.2.6, company_brand_context function present, company-owned Logo ID 67442, Inverse Logo ID 67443, Favicon 68932, name Wayfa Tours and profile site-native. No personal, quote or booking changes.
- Production FPM Spanish request after fix: locale es, home_url https://wayfatours.com/es/, feature_bound=true, provider available, footer shortcode returned logo image not wordmark.
- Both public EN and ES homepage normal HTML FastCGI HIT, HTTP 200, each with one mad4b-footer-logo-image and zero mad4b-footer-wordmark. Logo asset HTTP 200 image/png.
- PHP, Nginx, Redis, MariaDB healthy and no sampled Fatals. Staging homepage HTTP 200.
- Temporary read-only probe MU file was deleted. No global cache flush, no Nginx file deletion and no Production checkout changes.

## Rollback
Previous plugin entry, bootstrap and portability files are saved as *.before.php / *.before.json alongside this report. Only rollback after verifying exact currently deployed sha256 files; remove module 375 and restore three original files atomically. Alternatively disable Company Brand Context feature using the existing presentation feature switch. The separate Production Query Governor v1.1.2 has independent backups and must not be rolled back together.

## Performance caveat
This repairs brand identity and multilingual rendering. It does not close high uncached WordPress rendering time; Spanish first uncached request still exceeded 20 seconds. Continue Elementor/JetEngine/WPML and PHP-FPM performance work independently.
