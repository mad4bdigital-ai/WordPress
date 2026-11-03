---
name: firecrawl-competitive-research
description: Extract public competitor-page content, SEO metadata candidates, and literal price mentions via the governed private Firecrawl provider; never bypass source rights or site permissions.
---

Use this Skill only with a connected MAD4B Control Plane and the actual owner-enrolled site competitor registry.

1. First call `mad4b/research-provider-route-plan` with intent `page_content`, `page_seo`, or `competitor_pricing`, and verify preferred provider is Firecrawl with `execution_ready=true`. Route planning does not itself authorize any request.
2. Inspect `mad4b/research-firecrawl-cycle-plan` to discover approved competitor IDs and source domains. A domain is only technically eligible when both the site competitor registry and the exact Firecrawl domain allowlist permit it.
3. Review applicable source rights, robots.txt, terms of access, crawl budgets and data-retention rules independently. Never infer collection permission from a successful HTTP response.
4. Call `mad4b/research-firecrawl-cycle-observe` for ONE owner-approved HTTPS URL and explicit competitor ID, with `lane=content|seo|pricing|all` as appropriate. `demo=true` is reserved for `https://example.com` acceptance only and must never be used as competitor evidence.
5. Treat title, description, language, headings, questions, and prices as **unverified source observations**. Do not infer Google ranking or verified selling price. Price literals require date, currency, market, product/variant, taxes and availability checks.
6. Keep source URL, observation timestamp, rights/robots verdict, content digest and provenance. The preview is bounded and truncation may omit structured evidence.
7. `mad4b/research-scrape-record` requires an existing governed ContentJob, certified provider evidence, rights clearance and a separate authorized write. This Skill does not create that authority or write data itself.
8. Do not import competitor wording, media or brand assets into publications without separate rights and editorial approval. Never change WooCommerce product pricing from a scraped price mention.
9. Credentials remain in the site's private credential vault. Never print, export, request the password in a Skill, or inject secrets into WordPress options/logs.
10. The private gateway is reusable across companies, but every company must receive its own scoped credentials, isolation, allowlist, quotas and approvals; do not reuse Woo Lab's identity.
