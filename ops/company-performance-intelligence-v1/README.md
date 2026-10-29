# Company Performance Intelligence v1 — Control Plane read-only extension

Source-owned, tenant-neutral Staging-only WordPress MU module.

## Scope and abilities
- Registers native WordPress Ability mad4b/company-performance-scan-plan in the mad4b-read category, using MAD4B_SCP_Policy::can_read. Unauthenticated execution is denied. Native ability metadata is nonpublic and not exposed through REST.
- Registers the administrator-only Staging page Tools > Company Performance Scan with manage_options.
- Reads two fixed tenant config filenames (Critical CSS and Fragment Cache), a bounded published Elementor document inventory, plugin/version status, and Woo market/tax configuration.
- Detects document hash changes and UAEL version drift and emits P0 fail-closed remediations. Optional caller-supplied previous SHA256 fingerprint enables P1 drift detection.
- Emits sorted P0/P1/P2 plans with readonly=true, auto_apply_allowed=false, mutation_performed=false. It does not persist data, install plugins, execute remediations or change WooCommerce.
- Browser Core Web Vitals and real provider GeoIP behavior are not inspected by the WordPress ability itself.

## Private, per-company host operator
ETG Woo Lab Staging uses an operator-managed scanner outside the WordPress webroot. Host Cron executes every 30 minutes with an exclusive file lock, private 0700 state, atomic snapshots and 30-history rotation. The operator runs the WordPress scan via local container process and samples Chromium DOM and first-viewport UAEL for English home mobile/desktop, Arabic home and Tours archive. It adds P0 for unsafe newly above-fold UAEL; P1 for snapshot drift and sampled slow TTFB; P2 for asset inventory and full browser audits. The operator never auto-applies fixes.

Host operator scripts and domain/port paths are tenant-specific and not part of this generic module. Do not copy the operator scripts across companies without replacing their exact host, runtime scope, credentials, and pages.

## Staging verification (29 October 2026)
- PHP lint and portable contract PASS. WordPress Ability registered; unauthenticated execution returned ability_invalid_permissions.
- Synthetic Elementor metadata drift in an in-memory test produced P0 critical_css_document_26212 and P1 snapshot change, without changing stored content.
- First two actual scans matched, without P0 or unexpected deltas. Four Chromium cases returned HTTP 200. English home mobile/desktop had no above-fold UAEL; archive had two and retains original CSS.
- Unthrottled mobile homepage sampled 4.55s TTFB and was planned as a P1 investigation, NOT an SLA verdict. LCP/CLS remain unmeasured in this scanner.
- DISABLE_WP_CRON=true in this Staging site; host Cron is used for performance scans.

## Security and governance
- Staging only, Control Plane can_read policy, admin UI manage_options, bounded document and plugin inventory. No caller-specified URL/file paths.
- The native WP Ability registration does NOT claim reviewed, bundled Control Plane adapter status. Its adapter registry explicitly rejects unreviewed external implementations; do not circumvent this. Formal adapter mounting requires separate owner-reviewed source.
- GitHub PR must remain Draft until exact-head source/CI/owner approval. Production and other companies remain unchanged.
- Current performance presets and their governance are unaffected.

## Rollback
1. Remove only this site's host Cron entry.
2. Remove the matching Staging MU file and verify the ability disappears.
3. Do not remove cache/critical CSS presets, WooCommerce data or Control Plane package.

Run python3 test-contract.py and php -l company-performance-intelligence.php in CI. Full runtime integration tests require WordPress and the MAD4B Control Plane policy.
