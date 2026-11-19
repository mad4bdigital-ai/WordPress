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


## Per-site WP-Cron CSS scheduling (429 v1.1.0)

The CSS dispatcher now reads mad4b_css_wpcron_site_profile_v1
from each WordPress instance. Its required keys are site_id,
site_host, environment, and tasks, where the task map contains
only approved names with bounded schedule intervals (300-604800s).

An unconfigured/invalid/foreign-host/foreign-environment instance
has no CSS dispatch identity or scheduled tasks. A CSS queue profile
is NOT a host execution grant. The separate first-party VPS worker
continues to execute only the original site-specific adapters it
already knows. Existing source and wayfa queue identities and
pending ticket IDs were retained during source-first rollout.

Source profile: public 1800s; first_fold 900s; families 1800s;
visual 86400s; audit 86400s; retention 604800s.
Wayfa profile: public 1800s; first_fold 3600s.
These values are site-owned WP options, not constants in the MU
plugin, and do not imply any generic backend executor for other companies.

Rollback: /opt/mad4b-company-provisioning/reports/site-scoped-css-scheduler-20261119.
No CSS generation, certificate approval, pricing or FastCGI admission
was changed by this scheduler reconciliation.
