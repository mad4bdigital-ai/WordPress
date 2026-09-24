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

## High-risk canary bootstrap

A high-risk write MUST NOT require evidence produced only by the canary execution itself before the first canary can run.

The first isolated canary MAY become eligible only when all of these are exact/current:
- provider runtime is available;
- exact provider artifact/runtime certification passes;
- capability structural probes pass;
- artifact authority is bound;
- the ability is actually exposed by the adapter surface;
- the adapter explicitly opts that exact ability into governed canary execution.

These facts produce a deterministic non-authorizing `canary_basis_digest`. They MAY advance the capability from SHADOW to isolated CANARY eligibility, but they MUST NOT make the provider ability write-eligible, mount it on the normal write surface, create a grant, create an approval, or activate it for Production.

Canary execution still requires the governed non-production write authority, exact candidate/build binding, exact artifact and capability-contract binding, the current `canary_basis_digest`, adapter opt-in, one-time approval when required, budgets/audit, and provider-local policy. A current trusted behavioral receipt, when one already exists, is additionally bound and verified; it is not a circular prerequisite for the first canary.

Successful canary execution emits evidence only. Promotion to normal ACTIVE write eligibility remains a separate governed decision and is never implied by package identity or by the canary side effect itself.
