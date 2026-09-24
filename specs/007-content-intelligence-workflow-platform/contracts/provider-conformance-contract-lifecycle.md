# Contract — Provider Conformance and Contract Lifecycle

Contract: mad4b.provider-conformance-lifecycle.v1

## Provider conformance

A provider adapter claiming a semantic capability MUST pass a shared conformance suite independent of vendor implementation.

Examples for workflow.execute:
- accepted input envelope;
- exact target selection;
- deterministic plan binding;
- normalized status;
- stable error classes;
- timeout behavior;
- idempotency/retry expectations;
- cancellation semantics if claimed;
- evidence/output mapping;
- authority non-bypass.

Conformance proves semantic compatibility; it does not create certification or grants.

## Capability profiles

Providers MAY support subsets or optional extensions.
The resolver matches declared conformance profile + certification.

## Golden fixtures

Shared fixtures test equivalent behavior across:
- Bit Flows;
- future n8n;
- future native engine;
- mock/reference provider.

Vendor-specific extras never alter the generic semantic contract.

## Contract lifecycle

Every externally consumed contract/capability/Skill schema may declare:
- introduced_in;
- current_version;
- deprecated_since;
- replacement;
- migration guide;
- sunset_not_before;
- removal conditions;
- compatibility window;
- owner.

## Deprecation

Deprecation is observable but does not silently remove runtime behavior.

Before removal:
- usage inventory;
- replacement availability;
- migration evidence;
- affected site/provider list;
- operator notification policy;
- rollback/restore consideration.

## Breaking change

A breaking contract change requires a new contract/schema version and explicit migration path.

## Unknown clients

Requests using removed/unsupported contract versions fail with stable reason and supported-version metadata, not ambiguous behavior.
