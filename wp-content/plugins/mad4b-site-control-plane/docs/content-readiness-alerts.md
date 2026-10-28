# MAD4B Content Readiness & Alerts v1

A site-neutral module within MAD4B Site Control Plane, reusing existing WordPress, WPML and provider contracts.

The scanner reads configured published CPTs, source meta fields (origin, location, media, owner, date and stop sale), missing WPML languages, WP-Cron backlog, and optionally Fluent Forms unread follow-ups. Rules are stored in WordPress option mad4b_content_readiness_rules_v1 and are independently configurable per company. There are no fixed business domains, Tour IDs, brand names, or commercial fields in the shared module.

Admin UI: MAD4B Control Plane > Content Readiness. Editors can inspect issues. An administrator can save rules and explicitly scan. Issues use stable site-ID + type + object-ID + gap-code digests to prevent duplicates, auto-resolve after a complete scan and reopen on regression. A dashboard notice and admin bar counter expose critical issues. No emails or webhooks are fired on page loads.

Fluent Boards: the module can create a governed task linked to an open issue and store its task ID; the central MAD4B mutation gate, administrator permission, and an explicitly selected board and stage must authorize execution. The site has no approved content board yet and its global write gate is disabled, so no external tasks or mail notifications were created during tests. Fluent Support and Bit Flows outbound routes remain separate follow-on certification; never broadcast unapproved mass alerts.

Source example for ETG Lab (SITE POLICY ONLY): post types tours-and-activities, properties, transportations, tour-rates; origin meta tour_origin_address; taxonomy location_jet; property gallery key property_gallery; Tour Rate owner related_tour_id, date tour_rate_end_date and stop_sale. Configure Fluent Forms ID 6 only on the proper origin. An empty media meta field signals review, not conclusively missing visitor-facing imagery.

No automatic booking, pricing, content modification, extension of expired rates, or generic REST write endpoint. Source scan has a 3000-post cap per CPT and a 3000-issue storage cap. Partial scans do not reconcile. The operator must explicitly trigger a scan; scheduled scans depend on verified scheduler readiness and are NOT enabled while WP-Cron is unhealthy.

Acceptance: isolated ledger tests exercise discovery purity, deduplication, resolution, reopening and permission rejection. Main Lab bootstrap is reversible from the VPS backup created before deployment. Production remains unchanged.
