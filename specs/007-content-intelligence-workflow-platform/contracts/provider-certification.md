# Contract — Provider and Capability Certification

Contract: mad4b.provider-capability-certification.v2

## Separation of facts and decisions

ProviderRuntimeObservation is factual:
- provider_id
- installed_version
- active
- package_digest
- critical_file_digests
- runtime_symbols
- native_mcp_exposure
- observed_at

ProviderCertification is a governance decision:
- exact package identity
- evidence bundle
- reviewer/approval
- certification revision
- validity state

CapabilityCertification is narrower:
- capability_id
- state
- read_eligible
- write_eligible
- reversible
- blockers
- evidence_refs

Runtime observation MUST NOT create authority.

## States
- UNKNOWN
- SHADOW
- READ_CERTIFIED
- CANARY_VERIFIED
- REVERSIBLE_VERIFIED
- CERTIFIED
- BLOCKED

## Exactness
Certification binds to exact package/runtime evidence. Presence of expected classes or plugin activation is insufficient.

## Version semantics
Track independently:
- installed_version
- certified_version
- upstream_latest_version
- upgrade_available

Rules:
1. installed != certified may block capabilities.
2. older certified baseline does not require downgrade.
3. newer upstream release does not require upgrade.
4. exact installed package may remain certified while a newer release exists.
5. upgrade is a separate planned/certified operation.

## Promotion
Certification promotion:
- does not create grant;
- does not create approval;
- does not activate plugin;
- does not widen site/host authority.

## Recertification
When package changes:
- static package inspection;
- semantic delta;
- security review;
- disposable runtime;
- capability tests;
- target runtime identity;
- evidence promotion.

## High-risk change triggers
Explicit review when delta affects:
- authentication;
- MCP server/client exposure;
- webhooks;
- arbitrary code execution;
- filesystem/network access;
- provider data mutation;
- cron/background execution;
- privilege checks;
- retry/resume semantics.
