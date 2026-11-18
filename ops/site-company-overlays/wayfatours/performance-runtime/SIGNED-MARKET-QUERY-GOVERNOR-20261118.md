# Signed Market Query Governor — Wayfatours Production, 18 November 2026

## Problem and architecture
The original private_context() rejected every request carrying a signed country cookie. All JetEngine Query Builder results recomputed on returning visitors even for safe public terms and posts. Full personalized HTML stays private,no-store. The anonymous FastCGI policy is unchanged.

The existing Query Governor MU plugin was updated to 1.5.0 with a distinct fail-closed signed-market scope. The new cache-group SHA-256 includes the HMAC-verified country, approved market-profile ID, native WCML country, actual WooCommerce runtime currency, WPML language, device class and blog ID; original query ID, data generation and source dependencies stay in the key. It does not use raw IP, accept browser currency headers, or infer currency from language. WC sessions and all unclassified cookies are refused.

The signed policy permits only previously vetted PUBLIC posts/terms query results for 90 seconds maximum, not arbitrary SQL, repeater, rates, prices, availability, booking, WooCommerce orders, or full HTML. Unknown/non-home/API/checkout/preview/admin requests use the previous uncached path.

## Production-first evidence
- Original Wayfatours module backed up at /root/mad4b-backups/20261118-query-governor-signed-market-partition-v1/render-query-governor.before.php .
- Deployed manually to the live MU owner, verified php -l, first admitted behind WP option mad4b_render_query_signed_market_v1=canary. Promoted to on after verification. No global FastCGI purge or Redis flush.
- Of seven observed JetEngine Query Builder definitions, six public queries were cache-eligible (four terms, two posts); sensitive query ID 5 stayed uncached. New signed TTL is max 90 seconds.
- A US/en repeated request reused the same opaque group bucket b81b9e0b95e88f9539f6ce47. EG/en bucket c2128c632ed37eac851f7c98 and EG/es bucket e50a528fc3159c9789087d37 were different.
- Additional unknown cookie: 0 signed cache groups. Browser-supplied currency override header: 0 signed cache groups. Signed country HMAC must verify and allowed source-bound telemetry/locale cookies are checked.
- Native versus cache HTML listing parity (US): EN 41 public JetEngine items, including 8 Top Cities and 6 menu cards, identical text/link SHA cba03ba0f2b53ad1; ES 43 items, identical SHA 9c4d153a2700cf83. No observed JS page errors.
- After promotion, Chrome ordinary signed US session stayed private,no-store and retained country across EN/ES; TTFB samples remained 3.5–4.6 sec. This is a query-level isolation fix, NOT incognito speed parity or a 0ms TBT guarantee.
- Current WCML multi-currency flag is false; live currency is USD. The key structurally includes actual WCML/currency dimensions and fails closed on unresolved identity, but real USD/EUR simultaneous caching has NOT been verified; do not enable WCML just to test this optimization.

## Source and rollback
The runtime owner was 1.4.5, while the Git source held 1.4.3 and newer scoped-generation logic. The signed-scope function, policy path, and group namespace were installed in both separately, preserving the source's scoped-generation code. The unified source remains draft until CI/release review. Other companies and routes default to off; no unproven shared rollout.

Immediately rollback eligibility via WP CLI inside Wayfatours: wp option update mad4b_render_query_signed_market_v1 off --skip-plugins --skip-themes . Existing new cache groups expire within 90 seconds; do not purge shared HTML. If needed, restore exact saved original MU PHP file and lint. No booking, rate, WooCommerce payment or private record was changed.
