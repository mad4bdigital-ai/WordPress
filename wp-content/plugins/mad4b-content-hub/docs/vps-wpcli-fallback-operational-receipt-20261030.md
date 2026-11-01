# MAD4B VPS Client → WP-CLI fallback (Staging receipt, 30 Oct 2026)

## Route and authority

Default: direct ChatGPT MCP client, when available. On a genuine **transport unavailability** (not an authorization denial), the authorized VPS client may invoke `wp mad4b hub fallback <allowlisted-command>` in the WordPress Staging container using explicit `--user=<WordPress-admin>`.

The fallback **does not simulate OAuth**, create a fake NHI/Agent, or call a background model. It uses the same registered WordPress Abilities with their permission checks, site write gate, tenant/site identity and idempotent Artifact handling. All fallback writes append both request and outcome events to MAD4B append-only Audit, including the exact Ability, actor WP user, input SHA-256 and transport name; payloads are not stored in audit receipts.

Supported commands: `status`, `handoff`, `resume`, `baseline`, `submit-keywords`, `release-assess`, `release`. No raw eval, shell, SQL, general-purpose arbitrary Ability dispatch, production mutation or breakglass is granted by this new surface.

The JSON object is provided on **standard input**, with a maximum size of 64 KiB, not exposed as a password or a command-line argument. The WordPress CLI principal must be explicitly selected by `--user` and hold central Hub administrative permissions. Site Profile, runtime environment, Write Gate and Abilities remain authoritative. Production writes are denied.

Example (read-only):

```sh
printf '%s' '{"operation_key":"owned-homepage-control-hub-e2e-20261030-v1"}' \
  | docker exec -i -u www-data etg-commerce-staging-php \
    wp --path=/var/www/html --skip-themes --user=2 \
    mad4b hub fallback handoff
```

## Staging execution evidence

- Original Job: `14d65a58-73f2-4f1c-8512-c9fb652317a0` at revision 5, stage `INFORMATION_GAIN`.
- Initial status and handoff: success. Without `--user`: denied.
- `submit-keywords` first execution: successful Artifact `553b2dad-2d33-45dc-a987-696a2b276d3d`, with `execution_transport=vps_wpcli_fallback`, `chatgpt_oauth_verified=false`, provider `client_observed_web`, and no published content.
- Evidence terms from the first-party source `https://tours.dev.mad4b.com/`: Cairo day tours, Egypt Nile cruises, pyramids and museum trips. Search volume and Google rankings were **not measured**.
- Same request key and payload: `replayed=true`, same Artifact ID (no duplication).
- Same key with different content: `mad4b_hub_executor_client_step_payload_conflict`.
- Stale job revision: `mad4b_hub_executor_job_revision_changed`.
- Audit chain contains `requested`, `ok`, and `failed` events for this fallback.
- Standalone PHP regression: `VPS_WPCLI_FALLBACK_CONTRACT=PASS`.
- Production untouched and Background AI left disabled.

## Peer-governance prerequisite and rollback

The first write failed with `mcp_write_side_channel_detected`. Inspection showed Fluent Boards (`fluent-boards`) and FluentCRM (`fluent-crm`) had 26 non-read-only tools exposed across two **external** MCP servers, and were not governed MAD4B peers.

On **Staging only**, the official plugin-specific `mcp_enabled` options were changed from `yes` to `no` via their documented public settings functions. No Fluent Boards/FluentCRM plugin was deactivated and no business CRM/board records changed. This disables their **MCP integration** on Staging, so workflows that relied on those vendor MCP endpoints cannot call them while disabled. After that, MAD4B MCP peer inventory was ready with `risk_count=0`, `external_peer_count=0` and the audited WP-CLI fallback write succeeded.

The prior values and rollback explanation are saved at:
`/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/vps-wpcli-fallback-20261030/vendor-mcp-staging-rollback.txt`.

Official reversible settings actions (Staging admin context):

```php
fluent_boards_update_option('mcp_enabled','yes');
fluentcrm_update_option('mcp_enabled','yes');
```

**Re-enabling them without first reconciling their write-capable MCP exposure into the governed catalog will restore the MAD4B mutation blocker.** Better long-term: review and enroll/restrict vendor MCP peers and their individual tools instead of keeping their integration disabled.

## Remaining scope

The fallback is a working internal transport; it does not itself complete keyword SERP crawling, competitor selection, Blueprint/Draft generation, Final QA, Canonical release, remote company distribution or production certification. AI research and editorial outputs still require actual client turns and input evidence. Extension to other operations requires explicit reviewed command mapping and corresponding regression tests. PR #650 remains Draft until CI is green and source parity certified.
