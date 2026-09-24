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
