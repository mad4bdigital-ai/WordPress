# Home CSS dependency-aware epoch, 2026-11-16

Root cause: MAD4B adaptive intake increased the site-wide CSS epoch on EVERY published post update, even unrelated internal content. Repeated hooks inside one authoring transaction also caused repeated epoch invalidation, leaving certified first-paint CSS perpetually stale.

Change: preserve all per-route dirty queue events; only increase the global home CSS epoch when the existing cache lifecycle proves/predicts homepage impact. If the graph is stale or missing, fail closed to global invalidation. Front-page/Elementor templates and structural changes always invalidate. Multiple structural changes in one PHP request coalesce to one increment.

Critical CSS runtime still requires exact original LiteSpeed source CSS SHA-256, route, language, current epoch, Elementor doc graph and certified browser QA. This edit cannot auto-certify or force-enable a stale manifest.

Production caution: Wayfatours has a DIFFERENT asset-intelligence file hash. Reconcile the company module independently; do NOT overwrite it with the staging file. A site-side deployment was executed on Tours.dev only.

Regression in tests/test-adaptive-epoch.php. Before patch: content-only fixture failed. After patch: 9/9 assertions pass.

## Reviewed concurrent source convergence (non-authorizing)

The separately maintained latest source for the public-page Origin Cache Gate
on Tours.dev is the exact deployed SHA256
3fa6cffb25dfb315133caef1716f285427c2a2749c3e6c57d2a9c665e7bb73d7.
Version 1.1.1 uses registered site scope and Company Browser Policy, with
unknown or private contexts blocked. The default remains audit-only canary;
it does NOT enable shared HTML cache when Nginx market isolation remains
uncertified. No Origin Cache Gate was activated by importing the source.

The Nginx Accept-family helper is a candidate-only topology-fenced transform:
it requires all five cache keys and the market/private guards and does not
reload Nginx. Its 4 synthetic tests prove idempotence and reject partial
or private-guardless transforms; importing it does not change live Nginx.
