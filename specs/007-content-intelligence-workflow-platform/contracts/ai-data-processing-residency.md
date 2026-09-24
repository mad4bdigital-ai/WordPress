# Contract — AI Data Processing and Residency Policy

Contract: mad4b.ai-data-processing.v1

## Purpose

Control which data classes may be sent to which AI/model providers and under what retention, training and regional constraints.

## Provider processing profile

For each AI provider/model endpoint record:
- provider_id;
- endpoint/service class;
- approved environments;
- allowed data classifications;
- prohibited data classes;
- retention policy/known setting;
- training-use policy/known setting;
- region/data-residency options;
- subprocessors/contract reference if tracked;
- encryption/transport expectations;
- logging policy;
- policy revision.

## Data decision

Before model call:
input artifacts → classification → provider processing profile → effective decision.

Decision:
- ALLOW;
- REDACT_THEN_ALLOW;
- LOCAL_ONLY;
- REQUIRE_APPROVAL;
- DENY.

## Redaction/minimization

Where possible send:
- required excerpts;
- normalized facts;
- pseudonymous identifiers;
instead of entire confidential source corpora.

## Residency

Tenant/site policy MAY require:
- specific region;
- regional provider;
- local model;
- no external AI processing.

Provider Resolver respects these constraints.

## Model fallback

Fallback provider/model must independently satisfy the same or stricter processing policy.

Cost/availability never overrides data-processing denial.

## Evidence

Durable AI artifact records:
- processing policy version;
- provider/model;
- region if known;
- input classifications;
- redaction result;
- decision reference.

No raw secrets are persisted in this evidence.
