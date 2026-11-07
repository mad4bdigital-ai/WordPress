# MAD4B company-local destination presentation parity — Wayfatours

Checkpoint: 7 November 2026, extending existing PR #764.

The canonical Shared Product Core source now matches Tours.dev: global
MAD4B_COMMERCE_RUNTIME_ENABLED replaces the old Local Lab-only guard and
eligible_for_hierarchy() includes public, active but non-Featured cities.
Featured marketing cards and sales remain separately gated.

The Wayfatours portable controller is read-only and deliberately does not
install or enable the entire mad4b-platform or Commerce runtime. It checks
the enrolled tenant origin, site UUID, environment and Site Profile; the
current mode is live, with a rollback to the previous canary root file.

Source Elementor information template 45799 and hierarchy template 45886
remain native Elementor/JetEngine. Source-backed conditions hide empty
Address, Country and relationship sections; the Query Builder source stays
intact. WPML mapping normalizes 56118 to 45799, 56110 to 45886, and
56108 to 45774, without changing translated Elementor template data.

Deploy the entrypoint in wp-content/mu-plugins/ and the two dependency PHP
files in wp-content/mu-plugins/mad4b-destination-unified/.
Copy the dependency files first, then the entrypoint last.
The existing separate mad4b-destination-context-cache-isolation.php
must remain active: Elementor document HTML cache is not safe for dynamic
taxonomy-term and language context. Do not disable global page caching.

No WordPress options, company content, passwords, term/meta, bookings,
rates, Woo offers, or centrally issued grants were copied or modified.

Live QA covered Wayfatours Alexandria English and Spanish, Egypt country,
Pompey's Pillar attraction, and the original Tours.dev source plus Cairo.
All tested URLs responded HTTP 200. For Alexandria, Address, Country,
landmarks, areas, non-Featured city and sibling governorates rendered.
PageSpeed, CSS visual parity and mobile browser playback are not certified.

The checkpoint's uncached destination-page TTFB remains several seconds.
Do not describe this presentation transport as a PHP-performance fix.

Local backup and rollback:
  /opt/mad4b-company-transport/destination-info-parity-20261107/wayfa-unified/
  rollback-to-canary.sh

Run hermetic regression:
  php ops/mad4b-company-live-convergence-20261106/tests/test_destination_hierarchy_scope.php
