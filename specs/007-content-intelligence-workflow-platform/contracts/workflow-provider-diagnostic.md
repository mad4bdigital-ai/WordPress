# Contract — Workflow Provider Diagnostic

Contract: mad4b.workflow-provider-diagnostic.v1

Read-only by default.

## Artifact identity
Return:
- provider_id
- provider_key/plugin_file
- installed_version
- active
- plugin/package source
- archive_sha256 if known
- plugin_directory_tree_sha256
- critical_files_sha256
- composer/package metadata
- database migration/schema version
- update source/channel
- observation timestamp

## Structural capability discovery
Probe candidate support for:
- workflow.discover/list/read/validate
- workflow.create/update/enable/disable
- execution.start/read/retry/cancel
- trigger.webhook
- trigger.wp_action
- trigger.schedule
- workflow.export/import
- mechanics.delay/branch/iterator/repeater
- provider HTTP/custom app surfaces

Discovery does not certify.

## Security surface discovery
Return booleans/status for:
- arbitrary_php_execution
- outbound_http
- incoming_webhook
- native_mcp_server
- native_mcp_client
- credential_storage
- cloud_cron/background runner
- ai_agent
- custom_app
- local/private-network access controls
- arbitrary_url capability

## Policy classification
Examples:
- run_code: DENIED_NORMAL
- native_mcp_direct_transport: DENIED or FEDERATED/READ_ONLY
- outbound_http: constrained/certification_required

## Output
- structural findings
- capability candidates
- capability fingerprints where computable
- security surfaces
- blockers
- recommended probes
- mutation_performed=false
- secret_values_returned=false
