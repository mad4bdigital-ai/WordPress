# Contract — Artifacts and Quality Gates

Contract: mad4b.content-artifact-gates.v1

## Artifact envelope
Every durable artifact has:
- artifact_id
- optional job_id
- site/tenant scope
- artifact_type
- schema_version
- artifact_version
- payload/content location
- payload_sha256
- authority_class
- producer
- process_version
- created_at
- invalidation state

Versions are immutable.

## Lineage
ArtifactEdge relations:
- derived_from
- selected_from
- verifies
- invalidates
- replaces
- summarizes
- cites

## Initial artifact types
- ContextPack
- WriterProfileSnapshot
- KeywordResearch
- SERPSnapshot
- CompetitorSelection
- ScrapedPage
- TopicCoverageMatrix
- QuestionCoverageMatrix
- EntityCoverageMatrix
- EvidenceCoverageMatrix
- UXCoverageMatrix
- InformationGainPlan
- ContentBlueprint
- SectionPlan
- ArticleDraft
- FactLedger
- EditorialQA
- SEOQA
- MediaManifest
- PublishManifest
- IndexStatus
- SearchPerformanceSnapshot
- ContentDecaySignal
- CannibalizationSignal
- RefreshRecommendation

## Source classes
At minimum:
- first_party
- external_source
- provider_observation
- operator_decision
- model_analysis
- derived

Model analysis must not silently become source fact.

## Gates
CAN_PLAN:
- valid job;
- required current ContextPack;
- minimum required research;
- no hard source blocker.

CAN_WRITE:
- CAN_PLAN;
- approved/current blueprint;
- exact WriterProfile version;
- non-stale context.

CAN_PUBLISH:
- current draft;
- FactLedger without hard unsupported claims;
- EditorialQA pass;
- SEOQA pass;
- exact PublishManifest;
- target expected-state preconditions.

CAN_SCHEDULE:
- CAN_PUBLISH + schedule policy/authority.

CAN_ACTIVATE_PRODUCTION:
- separate release/Production authority; never inferred from content quality.

## Hard-failure rule
Scores/warnings may inform review but cannot average away a hard blocker.
