# Home CSS dependency-aware epoch, 2026-11-16

Root cause: MAD4B adaptive intake increased the site-wide CSS epoch on EVERY published post update, even unrelated internal content. Repeated hooks inside one authoring transaction also caused repeated epoch invalidation, leaving certified first-paint CSS perpetually stale.

Change: preserve all per-route dirty queue events; only increase the global home CSS epoch when the existing cache lifecycle proves/predicts homepage impact. If the graph is stale or missing, fail closed to global invalidation. Front-page/Elementor templates and structural changes always invalidate. Multiple structural changes in one PHP request coalesce to one increment.

Critical CSS runtime still requires exact original LiteSpeed source CSS SHA-256, route, language, current epoch, Elementor doc graph and certified browser QA. This edit cannot auto-certify or force-enable a stale manifest.

Production caution: Wayfatours has a DIFFERENT asset-intelligence file hash. Reconcile the company module independently; do NOT overwrite it with the staging file. A site-side deployment was executed on Tours.dev only.

Regression in tests/test-adaptive-epoch.php. Before patch: content-only fixture failed. After patch: 9/9 assertions pass.
