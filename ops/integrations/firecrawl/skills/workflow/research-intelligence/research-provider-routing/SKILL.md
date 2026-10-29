---
name: research-provider-routing
description: Choose governed Firecrawl page research or certified SerpAPI Google results inside MAD4B without confusing public-page observations with SERP rankings or verified prices.
---

1. Inspect the task intent: `page_content`, `page_seo`, `competitor_pricing`, `google_serp`, or `keyword_discovery`.
2. Call `mad4b/research-provider-route-plan` with the exact intent. Treat `execution_ready=false` as a stop condition; it is not permission to fall back to an unapproved route.
3. For `google_serp` or `keyword_discovery`, use a certified/verified Search provider such as SerpAPI via the **existing** Search Providers authority. Firecrawl Search API is not a substitute for exact Google rank, location, SERP features or paid provider certification.
4. For page content, on-page SEO and competitor price clues, use the private Firecrawl provider with a registered source and exact hostname allowlist. Each network operation remains bounded and deliberate.
5. Route normalized, rights-reviewed findings through existing MAD4B Research Intelligence: `mad4b/research-serp-record` (certified search), `mad4b/research-competitor-select`, `mad4b/research-scrape-record`, `mad4b/research-coverage-build`, and `mad4b/research-information-gain-build` with proper ContentJob, governance evidence and write approval. Do not bypass these with direct SQL/options edits.
6. Use Content Intelligence Pipeline and `mad4b/blueprint-build` only after source reconciliation and original-content editorial planning.
7. For pricing, use `mad4b/competitor-research-plan` and `mad4b/market-growth-evaluate` for proposals, with independent price/availability/tax/market validation. Never automatically set WooCommerce prices.
8. Require human review before retained artifacts, material site changes, scheduled crawling and publishing. No recurring collection is enabled by routing alone.
9. Preserve exact source, timestamp, confidence, digest, tenant/site scope and approval receipts. Separate public competitor facts from supplier contracts and licensed media.
10. When skills are exported, the portable path is `skills/<skill-name>/SKILL.md`. Native runtime source is `wp-content/mad4b-skills/<level>/<target>/<skill-name>/SKILL.md`; the plugin's `skill-seeds/` directory is a different read-only canonical seed source, not the live registry.
