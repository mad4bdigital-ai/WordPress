# MAD4B SEO Opportunities — staging candidate

Site-wide SEO opportunity discovery for public, published WordPress posts, pages, and custom post types. WordPress multilingual content is resolved through WPML native language permalinks. Excludes Elementor templates, attachments, unpublished content, noindex records, and same-URL duplicates.

## Invariants
- Review-only. No automatic Content Jobs, publishing, robots, canonical, or index mutation.
- Existing MAD4B Content Hub stays sole Content Jobs owner.
- Stable site + post + issue fingerprints; deterministic re-scans preserve reviewed state.
- WordPress cron schedules a daily scan and a delayed scan after a newly published post.
- Admin-only REST reading: GET /wp-json/mad4b-seo/v1/opportunities?page=1&limit=30.
- WP-admin: SEO Opportunities, under MAD4B Control Plane when available.
- CLI: wp mad4b-seo-opportunities scan is dry-run; add --apply for explicit reconciliation.

## Evidence priority
1. Rank Math locally cached GSC rows, only when latest data is within 90 days and exact same-origin URLs match. Rank Math CTR is represented as 0-100 percentage points (low CTR threshold 2.0%).
2. MAD4B G5 certified Google Search Console retained evidence when the native adapter and owner-configured server-side observation refs are present. The server-only hook is mad4b/seo_opportunities/certified_g5_refs. G5 verifies consent, tenant, property, adapter provenance, retention and cost. G5 CTR must declare percent/percentage or ratio/fraction units; unknown units are rejected. Do not assume Google Drive's OAuth has Search Console scopes.
3. Rank Math global post-type metadata templates and recent SEO score observations. Missing description sources are candidates for review only, not assertions of absent rendered meta.

If provider observations are stale or missing, performance opportunities are withheld. No Google network calls or credentials are used.

## Deployment and policy
Staging source-only candidate. No automatic Production deployment or IndexFlow activation.
Source options: mad4b_seo_opportunities_v1 and mad4b_seo_opportunities_snapshot_v1.
Contract: mad4b.seo-opportunities.v1.