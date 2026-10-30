# MAD4B Content Hub — Simple Connections + Auto Distribute

## Operator workflow
1. On each company WordPress site, create a dedicated minimal-role service user and a native WordPress Application Password. Configure the service user ID in the non-autoloaded option mad4b_simple_service_user_id_v1. Do not use the site's admin password.
2. Install remote/240-mad4b-company-simple-identity.php and remote/250-mad4b-company-private-hub-inbox.php on a separately tested consumer Staging site.
3. Install central/240-mad4b-central-simple-connections.php and central/250-mad4b-hub-auto-distribute.php in central Staging MU plugins in numeric order. Existing central 160 registry is required.
4. Go to Content Hub → Connections; enter username/Application Password; Connect and Test. Then Content Hub → Auto Distribute: select recipients, types, languages, specific Hub post IDs or auto-include newly-arriving Hub records; Save and enable.
5. Run Distribution Now or rely on WP-Cron every 15 minutes (traffic-dependent). View last results and private consumer inbox counts.

## Scope
- Source: existing central Content Hub published-reference records (mad4b_hub_nile), owned by Tourism.
- Delivery: selected Nile tours and categories, English/Arabic, with only source_company, source_id, kind, language, title, summary, and reference-only/no-publish/no-checkout flags.
- Existing records and new central Hub references are selected on every cycle. New source WordPress records must first be ingested into the central Content Hub by a separate source refresh job.
- Connections: native WordPress Application Password over HTTPS, strict exact host, no redirects, central AES-256-GCM encrypted credential store.
- Consumer: bounded private whole-snapshot option, administrator-only status and preview REST. No WooCommerce or company post writes, no media, orders, customers or historical prices.
- **No public JetEngine Listing yet**: consumer Query Builder, REST listings and SmartFilters must bind a separately approved read-only public display endpoint once source rights and content quality are verified. Current source payloads are marked may_publish=false.
- Existing P-256 source connector remains operational until a separate migration of source ingestion is completed; this is an additive rollout, not an insecure cutover.
- Initial pilot only recognizes existing three registered companies. Dynamic/custom provider onboarding is a separate increment.

## Tests
Run the two PHP CLI standalone contract scripts in the tests folder: test-simple-connections.php and test-auto-distribute.php. GitHub Actions checks both PHP 7.4 and 8.3.

## Deployment and rollback
Only deploy additive central files to central Staging. Auto Distribute defaults disabled. No consumer production mutation without separate consumer Staging validation and site release.
Rollback: disable Auto Distribute, clear its WP-Cron schedule, and remove only the new central 240/250 MU files after verifying backups. Do not remove the old P-256 connectors or any company data.
