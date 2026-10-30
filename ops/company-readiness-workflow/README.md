# Company & Brand Readiness — governed workflow v1

Scope: central **Staging** Control Plane (tours.dev.mad4b.com) audits enrolled **Wayfatours Production** in read-only mode. The named WordPress tenant stays independent; no content, users, media, payments or secrets are cloned.

## Lifecycle
1. Read registered company identity + Site Profile from the central Control Plane.
2. Inspect target WordPress options, roles, active providers, Elementor template status and unresolved source references via WP-CLI; use PHP CLI memory_limit=512M.
3. Distinguish source **candidate-only** inventory from canonical Content Hub approved assets.
4. Classify source references inside Elementor JSON as *media*, *link*, or *other*. **Never blindly replace media URLs**; verify media existence, licenses and tenant mapping. All 82 identified templates remain drafts until validated.
5. Compute a closure plan with independent stages for access isolation, templates, filters, Content Hub, choices, sync, pricing, MCP and repeatable bootstrap.
6. Store one SHA-256-referenced diagnostic receipt on central Staging only; expose through the authenticated REST endpoint, WordPress Abilities, and the Company & Brand Readiness admin screen.
7. Run on a scheduled basis to expose changes. Actual content ingest, media licensing, commercial distribution and template activation remain separate actions and fail closed until validated.

## Execution

Source-controlled runtime: \`ops/company-readiness-workflow/orchestrate.py\`.

On the enrolled VPS, place the source at \`/opt/mad4b-company-provisioning/bin/company-readiness-workflow\` and install the exact 241 Company & Brand Readiness source and 242 companion MU files on **central Staging only** at \`wp-content/mu-plugins/242-mad4b-company-readiness-workflow.php\`.

\`\`\`bash
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow --selftest
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow             # read only
python3 /opt/mad4b-company-provisioning/bin/company-readiness-workflow --refresh   # central Staging status receipt only
\`\`\`

Cron schedule deployed on the enrolled VPS: \`/etc/cron.d/mad4b-company-readiness-workflow\`, every 10 minutes. The existing preference sync (every 2 minutes) is a separate consumer. This audit never triggers it, chooses tour references or changes the tenant.

Readback:
\`GET /wp-json/mad4b/v1/company-brand-readiness/companies/wayfatours/workflow\`.

The companion restricts status by company identity and WordPress capabilities. Single-company existing operator access is preserved; after multiple companies are enrolled, explicitly assign operators via \`mad4b_allowed_company_codes\` user meta. The original admin selector is denied for non-platform admins in a multi-company registry until a scoped selector is provided; API read/write still uses company scoping. Unknown companies and anonymous access are denied.

## Acceptance and deliberate blockers

- The workflow **must not** change Woo Vacation tenant data or replace source URLs inside Wayfatours without media rights and verifiable target assets.
- \`canonical_hub_ingestion\` is **BLOCKED** while all 46 references are preview candidates. Actual source approval and asset licensing must precede distribution.
- \`company_selection\` is **WAITING_SELECTION** until a real company choice is saved. Do not fabricate one solely to make tests green.
- \`preference_sync_readback\` remains **PENDING** until central revision equals Wayfatours mirrored revision after a real save.
- \`company_scoped_access\`, \`dynamic_pricing_rates\`, \`mcp_external_projection\`, and \`reusable_bootstrap\` remain pending until their respective independent negative tests/pass criteria complete.
- \`template_activation\` stays blocked for 470 Elementor drafts until source references, images, Query Builder IDs and mobile/desktop presentation pass.
- Status reporting does not require a package certificate, a new signed-owner attestation or a custom release runtime gate beyond ordinary WordPress permissions and project CI.
- **Production data must not be modified by the collector**; only central Staging diagnostic options are written with \`--refresh\`.

## Rollback

Remove Staging MU companion; remove cron file. Retain evidence receipt for diagnosis. The companion does not create or update tenant posts/options. No tenant rollback necessary.
