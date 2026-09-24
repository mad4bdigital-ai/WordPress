# Tasks — Feature 007

Notation: [ ] pending; P0/P1/P2 priority; GATE blocks downstream work.

## Phase 0 — rc.59 canonicalization
- [ ] T0001 P0 GATE Snapshot PR #47 exact head and CI.
- [ ] T0002 P0 GATE Snapshot PR #45 head and merge base.
- [ ] T0003 P0 Extract the 10 PR #45-only commits.
- [ ] T0004 P0 Classify 0313e03 semantics.
- [ ] T0005 P0 Classify a533de8 semantics.
- [ ] T0006 P0 Classify 5ea91a7 semantics.
- [ ] T0007 P0 Classify b0f2c8a semantics.
- [ ] T0008 P0 Classify 93f99fb semantics.
- [ ] T0009 P0 Classify 218bf66 semantics.
- [ ] T0010 P0 Classify 173985e semantics.
- [ ] T0011 P0 Classify 8775f56 semantics.
- [ ] T0012 P0 Classify ced93ea semantics.
- [ ] T0013 P0 Classify b5d697f semantics.
- [ ] T0014 P0 GATE Require evidence-backed SUPERSEDED/EQUIVALENT/REQUIRED for every commit.
- [ ] T0015 P0 Port any REQUIRED semantics deliberately; no blind cherry-pick.
- [ ] T0016 P0 Rerun exact-head CI.
- [ ] T0017 P0 GATE Assert REQUIRED=0.
- [ ] T0018 P0 Freeze final rc.59 SHA.
- [ ] T0019 P0 Build exact package/provenance.
- [ ] T0020 P0 Deploy exact Staging package.
- [ ] T0021 P0 Verify source/build/package/MCP provenance.
- [ ] T0022 P0 Run exact-head live acceptance.
- [ ] T0023 P0 Generate fresh Full Staging Authority status/plan.
- [ ] T0024 P0 Apply only an exact fresh ready plan.
- [ ] T0025 P0 Verify candidate binding/runtime reconciliation/write readiness.
- [ ] T0026 P0 GATE Mark PR #47 Ready.
- [ ] T0027 P0 Merge #47 under repository policy.
- [ ] T0028 P0 Verify canonical master semantics.

## Phase 1 — provider certification
- [ ] T0101 P0 Inventory existing certification code/config.
- [ ] T0102 P0 Define ProviderRuntimeObservation.
- [ ] T0103 P0 Define capability certification states.
- [ ] T0104 P0 Separate installed/certified/upstream versions.
- [ ] T0105 P0 Add non-authorizing upgrade_available.
- [ ] T0106 P0 Add read_eligible/write_eligible/reversible/blockers.
- [ ] T0107 P0 Add semantic-delta evidence refs.
- [ ] T0108 P0 Add package/runtime drift invalidation.
- [ ] T0109 P0 Test partial capability certification.
- [ ] T0110 P0 Test unknown capability fail-closed.
- [ ] T0111 P1 Add read-only admin projection.

## Phase 2 — Bit Flows
- [ ] T0201 P0 Acquire exact installed package.
- [ ] T0202 P0 Hash archive/critical files.
- [ ] T0203 P0 Semantic delta against 1.24 baseline.
- [ ] T0204 P0 Inventory Flow/Node/History/Executor.
- [ ] T0205 P0 Inventory lifecycle APIs.
- [ ] T0206 P0 Inventory retry/cancel.
- [ ] T0207 P0 Inventory definition create/update/validate.
- [ ] T0208 P0 Inventory webhook/action-hook behavior.
- [ ] T0209 P0 Inventory native MCP surface.
- [ ] T0210 P0 GATE Prove no unmanaged privileged MCP side-channel.
- [ ] T0211 P0 Build disposable runtime.
- [ ] T0212 P0 Verify list/get/executions.
- [ ] T0213 P0 Create harmless canary flow.
- [ ] T0214 P0 Verify flow SHA determinism.
- [ ] T0215 P0 Verify plan SHA determinism.
- [ ] T0216 P0 Verify stale-flow denial.
- [ ] T0217 P0 Verify stale-plan denial.
- [ ] T0218 P0 Verify global execution gate/per-flow allowlist.
- [ ] T0219 P0 Verify audit/result evidence.
- [ ] T0220 P0 Promote read capabilities.
- [ ] T0221 P0 Promote execute according to risk policy.
- [ ] T0222 P1 Add enable/disable only if public contract proven.
- [ ] T0223 P1 Add retry/cancel only if semantics proven.
- [ ] T0224 P1 Add definition validate/diff.
- [ ] T0225 P1 Add create/update only with plan/readback/recovery.
- [ ] T0226 P2 Keep delete blocked until recovery proof.

## Phase 3 — Content Job
- [ ] T0301 P0 Add jobs schema.
- [ ] T0302 P0 Add job events schema.
- [ ] T0303 P0 Add generic artifacts schema.
- [ ] T0304 P0 Add artifact edges schema.
- [ ] T0305 P0 Add writer-profile identity schema.
- [ ] T0306 P0 Implement registry.
- [ ] T0307 P0 Implement independent state/stage validators.
- [ ] T0308 P0 Implement append-only JobEvent.
- [ ] T0309 P0 Implement optimistic revision.
- [ ] T0310 P0 Implement artifact append/version/SHA.
- [ ] T0311 P0 Implement lineage graph/invalidation.
- [ ] T0312 P0 Add job/artifact read abilities.
- [ ] T0313 P0 Add governed create/transition/cancel.
- [ ] T0314 P0 Test concurrency/retry/cancel.
- [ ] T0315 P0 GATE Prove no default Production authority.

## Phase 4 — context dispatch
- [ ] T0401 P0 KnowledgeClass registry.
- [ ] T0402 P0 Job requirement schema/resolver.
- [ ] T0403 P0 Map Context Authority to semantic knowledge classes.
- [ ] T0404 P0 KnowledgeDispatcher.
- [ ] T0405 P0 ContextPack builder.
- [ ] T0406 P0 Enforce review/site/brand/language policy.
- [ ] T0407 P0 Enforce context bounds.
- [ ] T0408 P0 Persist source/version/fingerprint lineage.
- [ ] T0409 P0 Missing-required blockers.
- [ ] T0410 P0 Dependency invalidation.
- [ ] T0411 P0 GATE Prove no raw Drive-folder bypass.

## Phase 5 — writer profiles
- [ ] T0501 P1 WriterProfile registry.
- [ ] T0502 P1 WriterProfileVersion artifact.
- [ ] T0503 P1 Source eligibility.
- [ ] T0504 P1 Distillation plan/process metadata.
- [ ] T0505 P1 Review/activation.
- [ ] T0506 P1 Read/list abilities.
- [ ] T0507 P1 Exact version pinning.
- [ ] T0508 P1 Historical-job immutability test.

## Phase 6 — research providers
- [ ] T0601 P0 KeywordProvider.
- [ ] T0602 P0 SERPProvider.
- [ ] T0603 P0 SearchProvider.
- [ ] T0604 P0 ScrapeProvider.
- [ ] T0605 P0 Error/freshness/request fingerprint contracts.
- [ ] T0606 P0 Usage/cost/budget metadata.
- [ ] T0607 P0 First keyword adapter.
- [ ] T0608 P0 First SERP adapter.
- [ ] T0609 P0 First scraper adapter.
- [ ] T0610 P0 Provider certification.
- [ ] T0611 P0 Retry/backoff/budget integration.
- [ ] T0612 P0 Research artifact persistence.
- [ ] T0613 P0 GATE Verify no vendor fields in ContentJob.

## Phase 7 — competitive intelligence
- [ ] T0701 P0 SERPSnapshot.
- [ ] T0702 P0 CompetitorSelection.
- [ ] T0703 P0 ScrapedPage.
- [ ] T0704 P0 TopicCoverageMatrix.
- [ ] T0705 P0 QuestionCoverageMatrix.
- [ ] T0706 P0 EntityCoverageMatrix.
- [ ] T0707 P0 EvidenceCoverageMatrix.
- [ ] T0708 P1 UXCoverageMatrix.
- [ ] T0709 P0 InformationGainPlan.
- [ ] T0710 P0 Partial-research policy/freshness.
- [ ] T0711 P0 Reproducibility fixtures.

## Phase 8 — blueprint/writing/QA
- [ ] T0801 P0 ContentBlueprint.
- [ ] T0802 P0 BlueprintQA.
- [ ] T0803 P1 SectionPlan.
- [ ] T0804 P0 CAN_PLAN.
- [ ] T0805 P0 ArticleDraft.
- [ ] T0806 P0 Section assembly lineage.
- [ ] T0807 P0 CAN_WRITE.
- [ ] T0808 P0 FactLedger and unsupported-claim blocker.
- [ ] T0809 P0 EditorialQA.
- [ ] T0810 P0 SEOQA.
- [ ] T0811 P0 FinalQA with hard-blocker semantics.
- [ ] T0812 P0 Dynamic Skills for stages.
- [ ] T0813 P0 Replay/resume tests.
- [ ] T0814 P0 GATE Approved draft artifact without site mutation.

## Phase 9 — media/SEO/draft
- [ ] T0901 P0 MediaManifest.
- [ ] T0902 P0 SEO intent schema.
- [ ] T0903 P0 PublishManifest.
- [ ] T0904 P0 Bind exact draft/media/SEO/plan SHAs.
- [ ] T0905 P0 Reuse Media, Rank Math and content abilities.
- [ ] T0906 P0 Expected target-state fingerprint.
- [ ] T0907 P0 CAN_PUBLISH for draft operation.
- [ ] T0908 P0 Staging create/update draft canary.
- [ ] T0909 P0 Read-after-write verification.
- [ ] T0910 P0 Mutation/rollback/audit evidence.
- [ ] T0911 P0 GATE Ensure public publication remains blocked.

## Phase 10 — schedule/publish
- [ ] T1001 P1 Scheduling capability.
- [ ] T1002 P1 Publish capability.
- [ ] T1003 P1 Environment-aware approval.
- [ ] T1004 P1 Stale target-state denial.
- [ ] T1005 P1 Post-publication cache/SEO verification.
- [ ] T1006 P1 Bounded Staging publish canary.
- [ ] T1007 P1 Production remains separately authorized.

## Phase 11 — Host Connector
- [ ] T1101 P1 Connector transport/auth contract.
- [ ] T1102 P1 HostTarget.
- [ ] T1103 P1 host.files/logs/php/cron/process/backup/database/domain read families.
- [ ] T1104 P1 First provider adapter when required.
- [ ] T1105 P1 GATE Verify WordPress grants do not authorize Host Connector.
- [ ] T1106 P2 Bounded write/recovery contracts.
- [ ] T1107 P2 Keep arbitrary Production shell unavailable.

## Phase 12 — cron
- [ ] T1201 P1 WordPressCronProvider read/health.
- [ ] T1202 P1 Governed cron.run.
- [ ] T1203 P2 cron.schedule/unschedule.
- [ ] T1204 P2 HostCronProvider.

## Phase 13 — provider completion
- [ ] T1301 P1 WP All Import dry-run/rollback.
- [ ] T1302 P1 WP All Export artifact ingest/receipt.
- [ ] T1303 P1 Post-execution reconciliation.
- [ ] T1304 P1 JetSmartFilters query/provider/indexer contracts.
- [ ] T1305 P1 JetSmartFilters reversible execution.
- [ ] T1306 P2 Yoast writes.
- [ ] T1307 P2 SEOPress writes.
- [ ] T1308 P2 Generic unknown-provider onboarding automation.

## Phase 14 — growth
- [ ] T1401 P2 SearchPerformanceProvider.
- [ ] T1402 P2 IndexStatus.
- [ ] T1403 P2 SearchPerformanceSnapshot.
- [ ] T1404 P2 ContentDecaySignal.
- [ ] T1405 P2 CannibalizationSignal.
- [ ] T1406 P2 RefreshRecommendation.
- [ ] T1407 P2 Revision ContentJob creation.
- [ ] T1408 P2 No silent rewrite test.

## Spec/CI governance
- [ ] T1501 P0 Add Feature 007 spec consistency test.
- [ ] T1502 P0 Validate required documents/contracts.
- [ ] T1503 P0 Validate one governance owner.
- [ ] T1504 P0 Validate Production not implicitly authorized.
- [ ] T1505 P0 Validate ETG is not a required generic schema field.
- [ ] T1506 P0 Validate vendor methods remain in adapters.
- [ ] T1507 P0 Validate every write family has risk/recovery/evidence policy.
- [ ] T1508 P0 Validate traceability covers functional families.
