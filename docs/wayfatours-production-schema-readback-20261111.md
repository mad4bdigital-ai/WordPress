# Wayfatours native tenant schema: current SQL readback

## Why this change
The earlier `wayfa-native-schema.json` snapshot was from 2026-11-10 03:54 UTC, but was used by the Migration Preflight without checking its age. The snapshot was accurate when collected, but is **not** current schema authority.

The new `wayfa_native_schema_information_schema.py` checks the enrolled Wayfatours database scope and executes exactly one **read-only** `information_schema.tables` SELECT through the first-party VPS MariaDB container. It verifies all expected `wp_mad4b_tenant_*` table names and refuses unexpected names, duplicate rows and non-database output. The private result is written to a separate report file without modifying production schema.

`wayfa_tenant_migration_preflight_current.py` now consumes that report only when it is root-owned, non-symlinked, source-matched and at most 15 minutes old. It never infers DDL authorization from a current schema read or a previously restored backup.

## Live verified sample
- Site: Wayfatours Production.
- Exact expected tables: `wp_mad4b_tenant_approvals`, `wp_mad4b_tenant_content_maps`, `wp_mad4b_tenant_field_overrides`, `wp_mad4b_tenant_hub_cache`.
- Readback on 2026-11-10 around 22:53 UTC: 4 missing, 0 present.
- Current code: matches expected two-source PHP SHA256s.
- Prior backup: isolated-restorable, but more than 1 hour old by latest migration preflight. A **new** backup and isolated restore are mandatory at actual DDL execution time.
- No signed schema-migration Broker Grant exists. The readback does not create one.

## Release and security
Source-only commit and read-only private report. Production Preflight was replaced with byte-verified source and the original script backed up for rollback. No table creation, `dbDelta`, WordPress state write, user delegation or grant issuance occurred.

Schema DDL must use a distinct approved First-party operation with precise table scope, no ambient environment-dependent bypass, own signed 305/306/312 grant, and independent post-DDL Original 313 readback. This PR does **not** implement that executor.
