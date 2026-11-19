# MAD4B — site-scoped dynamic CSS contract

Two identical WordPress MU plugins are deployed to Tours.dev Staging and Wayfatours Production:

- 414-mad4b-stable-listing-css-identity.php version 0.4.0
- 436-mad4b-private-session-critical-css-v1.php version 0.2.0

Both read runtime information from their current WordPress site. There is no embedded company domain, Elementor template ID, price list, or hardcoded archive post type.

## Individual WordPress settings

mad4b_stable_dcss_post_types_v1 is a site-owned option. Its shape is:
singular = [public CPT slugs], archive = [public CPT slugs].
Only registered public and publicly-queryable types qualify; archive types must have an archive link. Links are verified with get_permalink and get_post_type_archive_link.

mad4b_perf_stable_dcss_v1 controls existing anonymous home CSS identity.
mad4b_perf_stable_tour_dcss_v1 is the legacy site-owned singular CSS switch.
mad4b_perf_archive_stable_dcss_v1 defaults OFF until site-specific visual and CSS cohort QA.
mad4b_perf_private_home_stable_dcss_v1 defaults OFF.
mad4b_private_session_critical_css_v1 defaults OFF until a separate private certificate is proven.
mad4b_private_session_critical_css_routes_v1 is an optional locale-to-front-page-path map, populated only from that site's canonical WPML routes.

The actual site home hostname, staging/production environment, current WPML language and canonical page path determine private CSS certificate identity. Every site stores its manifest under its own WP_CONTENT_DIR/mad4b-private-session-critical-css-v1 directory. Matching requires the exact full source CSS SHA-256 and critical CSS SHA-256, private scope, device, route, locale, two viewport results (390px, 768px), and a separate interaction pass.

All authenticated and unsafe visitor HTML remains private/no-store. Certifying a stylesheet never enables FastCGI caching, and no cross-site manifest is accepted.

## Deployment and verification

Source Tours.dev and Wayfatours have identical component bytes. Each WordPress has its own locally confirmed public CPT allowlist; in these two installations it currently includes tours-and-activities. The production archive switch remains OFF following observed dynamic FAQ CSS cohorts and list reordering between independent requests. No private-session certificate has been issued.

The shared source test suite includes two-site route, CPT, cookie and exact-manifest QA. Never reuse a passing test from one site to certify another site's CSS, prices or interactions.
