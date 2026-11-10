# MAD4B Frontend Contract Monitoring v1

The `mad4b-frontend-contract-monitor.php` MU plugin is a brand-neutral read-only adapter to MAD4B Configuration Jobs. It does **not** run on visitor page requests and does **not** replace Elementor, JetEngine or WPML.

A scheduled Chromium probe checks actual public WPML switcher state, bidirectional translation navigation, selected Elementor Nested Tabs after AJAX, and successful JetEngine lazy-loading responses. The browser script uses a site-specific JSON manifest; the test engine has no Wayfatours markup hard-coded. Extra companies may be enrolled by adding their own selectors, translated routes, origin, and registered Site UUID to the manifest, followed by an explicit staging acceptance test.

Only minimal status (`pass`, `fail`, `incomplete`), exact Site UUID, epoch and SHA256 of the full host-only evidence is ingested over authorized host WP-CLI. Other origins and stale runs are rejected. Full evidence is stored privately in `/opt/mad4b-company-provisioning/reports/frontend-contract-monitor/`, not exposed through public REST or site HTML.

`MAD4B_Configuration_Jobs::scan()` turns failing contracts into `frontend-contract` configuration alerts; operators can promote alerts into non-executing Configuration Jobs through native permissions and sync them to Fluent Boards. Recovery is determined by a new browser pass. Missing or expired evidence produces an incomplete-monitoring alert instead of falsely clearing previous failures. The existing fleet collector reads open alerts from tenant ledgers into the central dashboard.

Deployment includes a 30-minute systemd timer and a one-shot service. The first Wayfatours Production test verified four browser contracts; injected failed contracts on Tours.dev were opened and subsequently resolved by scan without changing frontend data. Browser and WordPress probes must remain off the visitor request path.

Avoid copying a company's selectors or tour URLs into the shared MU module. No OAuth, certificates, or staging-only condition is added to ordinary site runtime; signed execution governance remains unchanged.

## Multilingual navigation order (November 2026)

`navigation_menu_language_order` compares all actual Elementor top-level header links to the expected per-language route order in the tenant manifest, rather than assuming that a WordPress nav-menu table describes the rendered header. This detects both WPML menu-order drift and stale Elementor `_elementor_element_cache` output. Failure creates a `frontend-contract` Configuration Alert; the reader never reorders menu items automatically.

The separate brand-neutral `mad4b-navigation-cache-consistency.php` MU module listens to native WordPress menu updates and selectively drops cached Elementor header documents containing `product-native-navigation` in either translation, without globally purging page/object caches. It preserves the Elementor widget's authored styling and linked panels. Update the language-specific `expected_paths` per enrolled tenant; no hard-coded brand behavior belongs in the MU module.
