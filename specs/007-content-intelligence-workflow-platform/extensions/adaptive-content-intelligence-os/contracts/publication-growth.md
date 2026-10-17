# Publishing, Media, SEO and Growth Feedback Contract
Contract: `mad4b.aci-os.publish-growth.v1`.

## Independent gates and authors

Evidence review, editorial/fact/rights/relation QA, content-creation authorization, publication authorization and Production activation are separate. Reuse existing native WordPress content, Media Library, SEO plugin and WPML operations through exact certified abilities. Never call `wp_insert_post` directly from external workflow machinery.

## PublishManifest

A reviewed immutable `PublishManifest` MUST contain site/locale/environment, target content identity/type, expected native revision, owner-managed fields, exact post title/content/blocks, excerpt, images with rights/alt/caption, SEO metadata, canonical, internal link targets, structured-data compatibility, WPML translation relation, per-field postmeta change set, citations, content hashes, policy revision, preview URL or sandbox screenshot evidence, approval actor, expiry, blast-radius budget and rollback class.

Create an initial draft in separately approved Staging if permitted; editing existing public content requires comparison to last managed and current state, conflict resolution and CAS. Publication is **not** implied by draft approval or an AI quality score. For a new post with ambiguous create outcome, query idempotency binding and reconcile before repeating.

## Post-commit acceptance

- WordPress native read: correct object ID, post type/status, locales/terms/meta, media IDs, SEO settings, modified version, canonical relation.
- Rendered browser: front-end content, links, semantics, images, accessibility, hreflang/canonical/structured data and dynamic/AJAX parity where relevant.
- External: search index/crawl status may lag, so mark `INDEX_PENDING`, not publish failure or fabricated success.
- Rollback: explicit native/SEO/media snapshots, exact reversible contract, completed readback; irreversible external effects are separately disclosed.

## Growth feedback and experiment policy

Comparability key = site + property + URL/canonical + locale/market + device + metric definition + currency + attribution model + comparable time windows. Store data-source receipt and sample constraints. Rank/traffic change is observational unless controlled evidence supports a causal claim. Ignore mismatched window/currency, missing sample, consent drift and seasonal anomalies in automatic decisions.

Report reach, engagement, qualified conversions, content-assisted bookings/leads when available, maintenance cost, time-to-publish and confidence bands separately. Ranking gain alone cannot trigger page overwrite. New optimization iteration goes back to Discovery as a **proposal**, with cooldown and budget.

## Denial examples

Missing rights/PII consent; unknown post owner; stale manifest; broken rollback; invalid link/locale; disputed fact; insufficient citations; SEO plugin not certified; publication route unavailable; unauthenticated readback; mixed attribution model; external effect unknown; missing browser receipt; transient crawler status. All remain blocked/review, never green by default.
