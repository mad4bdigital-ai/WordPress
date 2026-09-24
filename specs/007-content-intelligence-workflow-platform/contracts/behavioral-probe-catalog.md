# Contract — Behavioral Certification Probe Catalog

Contract: mad4b.provider-behavioral-probes.v1

## Generic workflow.execute probes
- deterministic_success
- controlled_failure
- timeout_surface
- correct_workflow_selected
- input_preserved
- expected_output
- branch_correctness
- no_duplicate_execution
- logs/evidence_created
- disabled_workflow_denied_or_expected
- retry_semantics
- cancellation_semantics where supported
- resume semantics where supported

## Historical regression probes
Every confirmed provider bug may become permanent.

Bit Flows seed probes:
- trigger_isolation_test:
  one WordPress Action Hook event executes only the intended bound flow.
- paused_execution_state_test:
  paused/resumed execution follows certified lifecycle and does not silently violate disabled-state policy.
- native_mcp_privilege_test:
  provider-native MCP cannot independently perform governed write outside MAD4B.
- arbitrary_php_exposure_test:
  Run Code/arbitrary PHP is not exposed through normal WorkflowProvider capability.
- outbound_network_policy_test:
  controlled/private-network policy matches certified expectations.
- webhook_authentication_test:
  inbound workflow webhook respects required auth/binding.
- duplicate_trigger_test:
  idempotency/dedup semantics are known and evidenced.

## Probe requirements
Each probe defines:
- fixture version
- disposable/site class
- exact artifact SHA
- capability contract version
- inputs
- expected result/reason code
- side effects
- cleanup/rollback
- evidence SHA

Behavioral tests run in disposable environment before target-site canary where practical.
