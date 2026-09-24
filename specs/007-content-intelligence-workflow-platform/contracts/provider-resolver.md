# Contract — Workflow Provider Resolver

Contract: mad4b.workflow-provider-resolver.v1

## Input
A non-authorizing RequiredCapabilitySet:
- required capabilities[]
- optional capabilities[]
- environment
- risk ceiling
- locality preference/requirement
- cost constraints
- latency/performance constraints
- data residency constraints optional
- site compatibility requirements
- required release ring

Example requirements:
- execution.start
- mechanics.delay
- mechanics.branch
- trigger.webhook

## Candidate evaluation
For each provider:
- capability coverage
- capability certification state
- exact artifact identity
- site runtime compatibility
- environment eligibility
- risk/policy eligibility
- cost
- locality/data residency
- health/runtime availability
- performance evidence
- side-channel blockers

## Output
ProviderResolutionDecision:
- eligible candidates
- ineligible candidates with reason codes
- selected provider or none
- deterministic policy version
- evidence refs
- resolution_sha256
- non_authorizing=true

## Rules
- Resolver does not create grant/approval.
- Resolver cannot select uncertified write capability.
- Version number alone is not a selection criterion.
- Skills depend on semantic capability requirements, not vendor names.
- Policy MAY prefer Bit Flows for local WordPress workflows, external engines for distributed work, native MAD4B for short governance-only flows; these are configurable preferences, not hardcoded business logic.

## Tool executor resolver boundary

Workflow Provider Resolver selects a provider capable of workflow semantics.

Tool Executor Resolver, defined by `governed-tool-execution.md`, selects an executor/channel for one semantic ToolOperation.

These resolvers MUST remain separate:
- choosing Bit Flows/n8n/native workflow engine does not choose SSH/WP-CLI/Host Runner;
- choosing Host Runner/provider API does not choose a WorkflowProvider;
- neither resolver creates grants/approvals;
- both may share deterministic policy/evidence infrastructure.

A Skill requests domain/workflow/tool semantics independently and cannot force a vendor/executor unless policy explicitly exposes such a constraint.
