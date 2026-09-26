# Contract — Workflow Provider

Contract: mad4b.workflow-provider.v2

## Purpose

Provide a stable orchestration abstraction independent of Bit Flows, n8n, or a future native engine.

## Provider descriptor
- provider_id
- provider_key
- label
- runtime_available
- installed_version
- certification_ref
- native_mcp_exposure
- operations

## Semantic capabilities

Read:
- workflow.list
- workflow.read
- workflow.execution.read
- workflow.definition.validate
- workflow.definition.diff

Write/lifecycle:
- workflow.execute
- workflow.enable
- workflow.disable
- workflow.execution.retry
- workflow.execution.cancel
- workflow.create
- workflow.update
- workflow.delete

## Operation declaration
Every operation defines:
- operation_id
- capability_id
- risk
- reversible
- provider_ability
- required_certification_state
- required_authority
- input_schema
- output_schema
- expected_state/fingerprint requirements
- evidence contract
- blockers

## Execute requirements
workflow.execute requires:
- exact workflow ref;
- exact workflow SHA-256;
- exact reviewed plan SHA-256;
- provider capability eligibility;
- exact NHI grant;
- one-time approval when policy requires;
- budget;
- audit;
- provider-local explicit allow policy where applicable.

Changed workflow or plan fails closed.

## Lifecycle mutation requirements
enable/disable/create/update require:
- exact current definition/state;
- deterministic desired change;
- read-after-write verification;
- rollback/recovery declaration;
- capability certification.

delete remains unavailable until restore semantics are proven.

## Native MCP exposure
Provider-native MCP server:
- may be observed;
- may be disabled externally;
- may be classified read-only;
- may be federated;
- MUST NOT become a second independent privileged authority.

## Bit Flows mapping

Current provider implementation:
- provider_id = bitflows
- provider_key = bit_pi

Existing adapter abilities:
- bitflows/list-flows → workflow.list/read family
- bitflows/get-flow → workflow.read
- bitflows/get-executions → workflow.execution.read
- bitflows/run-flow → workflow.execute

Unimplemented lifecycle operations remain unavailable until certified.
