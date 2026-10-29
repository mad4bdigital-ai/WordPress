# MAD4B Research Provider Continuation — Fail-soft, no false authority

This contract extends (does not replace) the existing MAD4B_SCP_Connector_Resilience retry and partial-check framework.

The private WordPress Ability is mad4b/research-provider-continuation. Tasks: content_brief, seo_audit, competitor_brief, price_intelligence, publication_readiness. Optional collect_preview and approved URL/enrolled competitor ID control exactly one real Firecrawl request. Default is readiness-only, no network scrape.

- When Firecrawl is unavailable, record the actual provider state and missing page content, SEO and price-mention evidence. Continue independent preparation and reporting; do not invent page observations.
- When SerpAPI is unavailable, record missing certified Google search rankings, results and features. Never substitute Firecrawl page text for observed Google rankings.
- Read-only collection may retry at most once for eligible transient errors via the existing Connector Resilience mechanism. No write or mutation is retried.
- Return explicit completed_stages, pending_evidence, blocking_evidence_for_finalization, notes, observed source digest when available, and resumption steps.
- Failure cannot become success. Continuation is authorization to complete safe research preparation, **not** to publish WordPress posts, mutate product prices, approve SEO facts or create unreviewed research artifacts. All output declares business_publication_authorized=false and wordpress_mutation_performed=false.
- Resumption requires revalidating provider credentials, source rights and exact-domain enrollment. No cross-tenant account sharing. Publishing requires independent ContentJob, editorial checks and write permission.

## WP-CLI publishing verification, no plugin skip

Traefik currently routes the PUBLIC origin https://tours.dev.mad4b.com/ to etg-commerce-staging-nginx:8080 and the associated etg-commerce-staging-php container, which already has /usr/local/bin/wp installed. An older separate etg-woo-lab-php is exposed on local port 8091 and points at a DIFFERENT WordPress database snapshot. It must not be treated as the public origin.

Public Commerce staging WP-CLI with ALL plugins loaded created QA post ID 67459 as draft, published it, verified both public permalink AND public WP REST GET returned HTTP 200 and the test sentinel, then set it back to draft and force-deleted it. The public URL correctly returned 404 after deletion. Customer posts and product prices were untouched.

A separate read-only CLI boot investigation found that the older etg-woo-lab-php Control Plane live-truth source was missing the GitHub-master-approved three-line passive WP-CLI bootstrap guard. A backup was made, source file reconciled with GitHub merge SHA dc42211cb156c984cc36ec51d15765f6e11a88a4 and PHP syntax validated; WP-CLI then loaded all plugins and read options successfully without --skip-plugins. This guard only prevents **automatic expensive full authority reconciliation on passive CLI initialization**; explicit authority reads and governed mutations still require the ordinary safeguards.

WARNINGS: Public Commerce staging WP-CLI logs reported some nonexistent fluent-boards/* and fluent-crm/* Abilities. Publishing nonetheless succeeded. Those registrations need a separate review; never hide them with --skip-plugins.
