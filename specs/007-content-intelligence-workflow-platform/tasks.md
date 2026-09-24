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


## Phase 15 — Multi-Authority OAuth hardening
- [ ] T1509 P0 Model AuthorityDescriptor trust/advertisement/resource/subject/live dimensions.
- [ ] T1510 P0 Split trusted_authorities from advertised_authorities.
- [ ] T1511 P0 Implement authority_resource_policy.
- [ ] T1512 P0 Implement issuer+external_sub+site subject mapping.
- [ ] T1513 P0 Add mapping governance/audit/revision.
- [ ] T1514 P0 GATE Full REST filter-chain Local subject E2E.
- [ ] T1515 P0 GATE Full REST filter-chain External subject E2E.
- [ ] T1516 P0 Cross-authority subject/JWK isolation negatives.
- [ ] T1517 P0 External live readiness evidence and truthful status.
- [ ] T1518 P0 Authority outage/no-unintended-fallback test.
- [ ] T1519 P0 Restrict Local default resource policy from Developer/Breakglass.
- [ ] T1520 P1 Add Local keyring current/next/previous.
- [ ] T1521 P1 Add overlap rotation state machine.
- [ ] T1522 P1 Restart persistence/kid stability test.
- [ ] T1523 P1 Unknown-kid bounded refresh/deny test.
- [ ] T1524 P1 Refresh replay/family poisoning regression.
- [ ] T1525 P1 Key rotation overlap test.
- [ ] T1526 P1 Add MCP Adapter exact-package protocol profile.
- [ ] T1527 P1 Add successor official release certification workflow.
- [ ] T1528 P1 Dual-protocol regression when successor supports newer MCP.
- [ ] T1529 P0 GATE Multi-Authority Live Certification contract implementation.
- [ ] T1530 P0 Exact deployment/package/resource metadata probes.
- [ ] T1531 P0 Local AS live canary.
- [ ] T1532 P0 External AS live canary where configured.
- [ ] T1533 P0 Real ChatGPT OAuth→MCP acceptance.
- [ ] T1534 P0 Same-identity MCP tools discovery/call.
- [ ] T1535 P0 Production isolation negative.
- [ ] T1536 P0 GATE Require MULTI_AUTHORITY_LIVE=PASS before removing Draft trust gate.

## Phase 16 — Dynamic provider certification
- [ ] T1601 P0 Implement workflow-provider-diagnostic.v1.
- [ ] T1602 P0 Artifact tree/critical-file/schema identity.
- [ ] T1603 P0 Structural capability discovery.
- [ ] T1604 P0 Security surface discovery.
- [ ] T1605 P0 Compute per-capability fingerprint.
- [ ] T1606 P0 Migrate certification lifecycle to extended states.
- [ ] T1607 P0 Implement QUARANTINED state.
- [ ] T1608 P0 Implement Artifact Diff Classifier.
- [ ] T1609 P0 Map changed dependencies to affected capabilities.
- [ ] T1610 P0 Build CertificationEvidence dependency graph.
- [ ] T1611 P0 Explicit evidence reuse with lightweight smoke.
- [ ] T1612 P0 Central exact-artifact Certification Registry.
- [ ] T1613 P0 SiteRuntimeCompatibility probes.
- [ ] T1614 P0 Verify global cert does not auto-grant site eligibility.
- [ ] T1615 P0 Generic deterministic execution probe.
- [ ] T1616 P0 Controlled failure/timeout/retry probes.
- [ ] T1617 P0 Duplicate execution/trigger probes.
- [ ] T1618 P0 Bit Flows trigger_isolation_test.
- [ ] T1619 P0 Bit Flows paused_execution_state_test.
- [ ] T1620 P0 Bit Flows native MCP privilege test.
- [ ] T1621 P0 Bit Flows Run Code ordinary-capability denial test.
- [ ] T1622 P0 Outbound/private-network policy probe.
- [ ] T1623 P0 Webhook authentication/binding probe.
- [ ] T1624 P0 Promote exact installed Bit Flows capabilities individually.
- [ ] T1625 P1 Test newer Bit Flows artifact in disposable ring without changing ETG first.
- [ ] T1626 P0 GATE Prove version is metadata/risk signal, not direct authority.

## Phase 17 — Provider Resolver and workflow bridge
- [ ] T1701 P0 Define RequiredCapabilitySet.
- [ ] T1702 P0 Build provider feature/capability matrix.
- [ ] T1703 P0 Implement ProviderResolutionDecision.
- [ ] T1704 P0 Consider certification/environment/risk/cost/locality/performance.
- [ ] T1705 P0 Ensure Resolver is non-authorizing.
- [ ] T1706 P0 Ensure Skills do not hardcode Bit Flows.
- [ ] T1707 P0 Define signed workflow-execution-request.v1.
- [ ] T1708 P0 Bind request to site/workflow SHA/plan SHA/expiry/nonce.
- [ ] T1709 P0 Replay denial.
- [ ] T1710 P0 Bound callback/result contract.
- [ ] T1711 P0 GATE Resolve equivalent fixture across two provider adapters without domain-schema change.

## Phase 18 — Provider release rings
- [ ] T1801 P1 Implement R0 disposable state.
- [ ] T1802 P1 Implement R1 canary Staging.
- [ ] T1803 P1 Implement R2 selected Staging.
- [ ] T1804 P1 Implement R3 general Staging eligibility.
- [ ] T1805 P1 Implement R4 Production eligibility.
- [ ] T1806 P1 Define per-ring evidence requirements.
- [ ] T1807 P1 Conditional autopromotion policy.
- [ ] T1808 P1 Demotion/quarantine.
- [ ] T1809 P1 Verify Production eligibility != authorization.
- [ ] T1810 P1 GATE Demonstrate selective capability promotion/demotion from dependency evidence.

## Phase 19 — Spec isolation and compatibility
- [ ] T1901 P0 Keep Feature 007 metadata local to specs/007-content-intelligence-workflow-platform/feature.json.
- [ ] T1902 P0 GATE Preserve existing repository-global .specify/feature.json semantics for Feature 001.
- [ ] T1903 P0 Add cross-feature spec isolation contract/test.
- [ ] T1904 P0 Verify Feature 001 CI stays green for spec-only Feature 007 changes.
- [ ] T1905 P0 Verify Feature 007 validation does not depend on mutating another feature's metadata.


## Phase 20 — Correctness, concurrency and durability
- [ ] T2001 P0 Define canonical serialization/hash version.
- [ ] T2002 P0 Define ContentJob/Event/Artifact transaction boundaries.
- [ ] T2003 P0 Implement optimistic revisions and stale-write conflicts.
- [ ] T2004 P0 Implement write idempotency key registry.
- [ ] T2005 P0 Implement durable provider outbox.
- [ ] T2006 P0 Implement callback/event inbox deduplication.
- [ ] T2007 P0 Define at-least-once delivery + effect-once guarantees.
- [ ] T2008 P0 Worker lease/heartbeat/reclaim semantics.
- [ ] T2009 P0 Retry error taxonomy.
- [ ] T2010 P0 Retry attempt/time/cost budgets + backoff/jitter.
- [ ] T2011 P0 Provider timeouts/bulkheads/circuit breakers.
- [ ] T2012 P0 Queue/backpressure/saturation behavior.
- [ ] T2013 P0 Cancellation vs execution race handling.
- [ ] T2014 P0 Multi-step saga/compensation declarations.
- [ ] T2015 P0 Concurrency test: two writers same job revision.
- [ ] T2016 P0 Timeout-after-provider-success duplicate prevention.
- [ ] T2017 P0 Duplicate/reordered callback tests.
- [ ] T2018 P0 Crash/lease-expiry resume test.
- [ ] T2019 P0 GATE QCORRECTNESS + QRESILIENCE pass.

## Phase 21 — Security, data and supply chain
- [ ] T2101 P0 Enumerate trust zones and threat model.
- [ ] T2102 P0 SSRF/private/link-local/metadata endpoint policy.
- [ ] T2103 P0 Redirect/DNS rebinding revalidation.
- [ ] T2104 P0 Prompt/source injection isolation.
- [ ] T2105 P0 HTML/XSS sanitization and output context tests.
- [ ] T2106 P0 SQL/command/path traversal negative tests.
- [ ] T2107 P0 Zip-slip/symlink package extraction tests.
- [ ] T2108 P0 Confused-deputy capability escalation tests.
- [ ] T2109 P0 Provider compromise quarantine/kill-switch test.
- [ ] T2110 P0 Exact package provenance/dependency inventory.
- [ ] T2111 P0 Unexpected update-source/digest drift blocker.
- [ ] T2112 P0 Secret binding/storage/redaction/rotation contract.
- [ ] T2113 P0 Tenant/site DB/query/cache isolation.
- [ ] T2114 P0 Wrong-site webhook/provider callback negatives.
- [ ] T2115 P0 Data classification/minimization.
- [ ] T2116 P1 Retention/export/erasure/tombstone workflow.
- [ ] T2117 P0 Policy desired-vs-observed drift status.
- [ ] T2118 P0 High-risk kill-switch precedence.
- [ ] T2119 P0 GATE QSECURITY + QSUPPLYCHAIN + QDATA pass.

## Phase 22 — AI evaluation, performance, compatibility and recovery
- [ ] T2201 P0 Record model/prompt/Skill/input fingerprints on AI artifacts.
- [ ] T2202 P0 Structured-output schema validation + bounded repair.
- [ ] T2203 P0 Fact grounding/evidence coverage metrics.
- [ ] T2204 P0 Versioned multilingual/content-type evaluation fixtures.
- [ ] T2205 P0 Model/prompt/Skill regression policy.
- [ ] T2206 P0 Fallback-model equivalence gate.
- [ ] T2207 P0 Define environment/use-case SLO profiles.
- [ ] T2208 P0 Queue/latency/error/payload/DB/memory/concurrency metrics.
- [ ] T2209 P0 Per-job/stage/provider/model cost budgets.
- [ ] T2210 P0 Capacity and burst load tests.
- [ ] T2211 P0 Schema migration fresh/upgrade/repeat/partial-failure tests.
- [ ] T2212 P0 Mixed-version compatibility policy.
- [ ] T2213 P0 PHP/WP/DB/MCP/provider compatibility matrix.
- [ ] T2214 P0 Property tests for state/idempotency/hash/scope.
- [ ] T2215 P0 Bounded fuzz tests for external envelopes/URLs/paths.
- [ ] T2216 P0 Fault injection for timeout/duplicate/reorder/DB crash/JWKS outage.
- [ ] T2217 P1 Mutation testing for critical deny-policy code.
- [ ] T2218 P0 Define RPO/RTO profiles.
- [ ] T2219 P0 Database/artifact restore rehearsal.
- [ ] T2220 P0 OAuth key/provider compromise recovery rehearsal.
- [ ] T2221 P0 Failed publish/rollback/forward-fix rehearsal.
- [ ] T2222 P0 GATE QEVAL + QPERF + QCOMPAT + QRECOVERY pass.


## Phase 23 — Policy resolution, approvals and evidence trust
- [ ] T2301 P0 Implement Policy Resolution Engine input normalization.
- [ ] T2302 P0 Define deny/kill-switch/environment/certification/approval/grant precedence.
- [ ] T2303 P0 Emit explainable PolicyDecision with versions/reasons/SHA.
- [ ] T2304 P0 Implement ApprovalPolicy requester/approver separation.
- [ ] T2305 P0 Implement quorum and distinct-principal enforcement.
- [ ] T2306 P0 Implement bounded delegation + revocation.
- [ ] T2307 P0 Implement emergency approval TTL/incident/post-review.
- [ ] T2308 P0 Deny Breakglass self-approval by default.
- [ ] T2309 P0 Add evidence attestation signing profile.
- [ ] T2310 P0 Add attestation trust roots/key roles.
- [ ] T2311 P0 Add evidence/key revocation.
- [ ] T2312 P0 Add bounded offline trust-cache TTL.
- [ ] T2313 P0 Tampered local certification flag negative test.
- [ ] T2314 P0 Expired/revoked attestation negative tests.
- [ ] T2315 P0 GATE QGOVERNANCE pass.

## Phase 24 — Existing-site bootstrap and intent registry
- [ ] T2401 P0 Implement read-only SiteBootstrapSnapshot.
- [ ] T2402 P0 Normalize posts/pages/CPTs/taxonomies/languages.
- [ ] T2403 P0 Normalize URLs/canonicals/SEO/indexability.
- [ ] T2404 P0 Normalize media and internal/outbound links.
- [ ] T2405 P0 Backfill observed existing-content artifacts.
- [ ] T2406 P0 Implement ContentInventoryItem registry.
- [ ] T2407 P0 Implement Intent Registry.
- [ ] T2408 P0 Define CREATE_NEW/UPDATE/CONSOLIDATE/SUPPORT/HUMAN_REVIEW outcomes.
- [ ] T2409 P0 Content Planner intent-collision gate.
- [ ] T2410 P0 Incremental inventory refresh.
- [ ] T2411 P0 Periodic inventory reconciliation.
- [ ] T2412 P0 Multilingual canonical-owner collision test.
- [ ] T2413 P0 GATE Bootstrap performs no mutation.
- [ ] T2414 P0 GATE First job requires reconciled site inventory where policy mandates.

## Phase 25 — Artifact storage and incremental recomputation
- [ ] T2501 P0 Define ArtifactStore interface.
- [ ] T2502 P0 Implement immutable content-addressed blob identity.
- [ ] T2503 P0 Separate logical Artifact ID from blob ID.
- [ ] T2504 P0 Integrity verification on blob reads.
- [ ] T2505 P0 Storage classes/tiering.
- [ ] T2506 P0 Tenant/site storage quotas.
- [ ] T2507 P0 Dedup accounting.
- [ ] T2508 P0 Retention/legal-hold-aware garbage collection.
- [ ] T2509 P1 Define rebuildable RetrievalIndex abstraction.
- [ ] T2510 P0 Type ArtifactEdge invalidation semantics.
- [ ] T2511 P0 Implement RecomputePlanner.
- [ ] T2512 P0 Preserve unaffected artifacts with rationale.
- [ ] T2513 P0 Add recompute cost/fan-out estimate.
- [ ] T2514 P0 Fan-out review threshold.
- [ ] T2515 P0 Change coalescing/debounce.
- [ ] T2516 P0 Dependency cycle/loop prevention.
- [ ] T2517 P0 Freshness-only gate invalidation.
- [ ] T2518 P0 GATE Minimal recomputation property tests.

## Phase 26 — Publication verification, rights and AI data processing
- [ ] T2601 P0 PublicationEvidence schema.
- [ ] T2602 P0 Origin object + HTTP verification.
- [ ] T2603 P0 Public/edge fetch and rendered-content fingerprint.
- [ ] T2604 P0 Canonical/robots/indexability verification.
- [ ] T2605 P0 Structured-data verification.
- [ ] T2606 P0 Hreflang/language verification.
- [ ] T2607 P0 Media/sitemap verification.
- [ ] T2608 P0 Cache/CDN propagation state and timeout.
- [ ] T2609 P0 Governed cache purge capability where provider supports it.
- [ ] T2610 P0 Publication containment/rollback policy.
- [ ] T2611 P0 RightsRecord registry.
- [ ] T2612 P0 Research-vs-republication rights policy.
- [ ] T2613 P0 Media rights/attribution verification.
- [ ] T2614 P1 Similarity/near-duplicate policy.
- [ ] T2615 P0 Takedown invalidation workflow.
- [ ] T2616 P0 AI DataProcessingProfile registry.
- [ ] T2617 P0 Classification→provider processing decision.
- [ ] T2618 P0 Redaction/minimization path.
- [ ] T2619 P0 Data residency/local-only policy.
- [ ] T2620 P0 Fallback-model processing equivalence.
- [ ] T2621 P0 GATE Public publish requires required verification/rights/data gates.

## Phase 27 — Operator control, Doctor, DLQ and provider lifecycle
- [ ] T2701 P1 Operator Control Center read model.
- [ ] T2702 P1 Queue/blocker/approval/provider/gate/budget views.
- [ ] T2703 P1 Governed retry/cancel/pause/resume actions.
- [ ] T2704 P1 Bounded bulk-action preview/blast-radius policy.
- [ ] T2705 P0 Read-only Doctor findings.
- [ ] T2706 P0 RepairPlan contract.
- [ ] T2707 P0 Stuck lease/orphan artifact/stale gate diagnostics.
- [ ] T2708 P0 Provider/policy/publication drift diagnostics.
- [ ] T2709 P0 Dead-Letter Queue schema/policy.
- [ ] T2710 P0 DLQ replay with current readback/idempotency.
- [ ] T2711 P0 Poison-work retention/quarantine.
- [ ] T2712 P0 Shared provider semantic conformance suite.
- [ ] T2713 P0 Golden cross-provider fixtures.
- [ ] T2714 P0 Contract/capability/Skill lifecycle metadata.
- [ ] T2715 P0 Deprecation usage inventory.
- [ ] T2716 P0 Sunset migration/removal gate.
- [ ] T2717 P0 GATE Common repair/replay path works without raw DB surgery.

## Phase 28 — Fairness, autonomy, localization, accessibility and links
- [ ] T2801 P1 Tenant/site/provider scheduling classes.
- [ ] T2802 P1 Weighted fair scheduling and anti-starvation.
- [ ] T2803 P1 Per-scope quotas/concurrency.
- [ ] T2804 P1 Reserved incident/recovery capacity.
- [ ] T2805 P0 Define central dependency outage matrix.
- [ ] T2806 P0 Define cached evidence/authority TTL by dependency.
- [ ] T2807 P0 Deny new privilege during central outage by default.
- [ ] T2808 P0 Reconnection trust/revocation/drift reconciliation.
- [ ] T2809 P0 LocalizationCluster.
- [ ] T2810 P0 Translation/transcreation lineage.
- [ ] T2811 P0 Locale formatting and RTL/LTR rules.
- [ ] T2812 P0 Canonical/hreflang locale policy.
- [ ] T2813 P1 Accessibility QA profile.
- [ ] T2814 P1 Heading/link/alt/language/direction checks.
- [ ] T2815 P0 Internal Link Graph.
- [ ] T2816 P0 Link recommendation policy.
- [ ] T2817 P0 Orphan/anchor-diversity/indexability rules.
- [ ] T2818 P0 Publication verification for links/hreflang/canonical.
- [ ] T2819 P0 GATE Noisy-neighbor + central-outage + multilingual fixtures pass.

## Phase 29 — Eval operations, alerts, experimentation and usage ledger
- [ ] T2901 P1 EvalSuite registry/ownership/versioning.
- [ ] T2902 P1 Gold fixture provenance/contamination policy.
- [ ] T2903 P1 Threshold calibration/versioning.
- [ ] T2904 P1 Human reviewer agreement metadata where applicable.
- [ ] T2905 P1 EvalRun baseline comparison.
- [ ] T2906 P1 SLO error-budget state.
- [ ] T2907 P1 Burn-rate alerts.
- [ ] T2908 P1 Alert owner/runbook/escalation/dedup.
- [ ] T2909 P1 Maintenance-window policy.
- [ ] T2910 P2 Experiment identity/hypothesis/assignment.
- [ ] T2911 P2 Immutable variant artifacts.
- [ ] T2912 P2 SEO-safe experiment constraints.
- [ ] T2913 P2 Guardrails/stopping/interaction policy.
- [ ] T2914 P2 Winner promotion through normal publish gates.
- [ ] T2915 P1 UsageEvent append-only ledger.
- [ ] T2916 P1 Job/stage/site/tenant/provider budgets.
- [ ] T2917 P2 Rate-card versioning/internal chargeback.
- [ ] T2918 P2 Provider invoice reconciliation/adjustments.
- [ ] T2919 P1 GATE Usage/alert/eval evidence is payload-minimized and tenant-scoped.

## Phase 30 — Decommission and portability
- [ ] T3001 P1 Define decommission scopes.
- [ ] T3002 P1 Inventory active jobs/DLQ/artifacts/providers/credentials/cron/webhooks.
- [ ] T3003 P1 Quiesce and late-callback treatment.
- [ ] T3004 P1 ExportBundle manifest.
- [ ] T3005 P1 Artifact/blob checksum export.
- [ ] T3006 P1 Exclude secrets/private keys by default.
- [ ] T3007 P1 Provider resolver removal + credential revoke.
- [ ] T3008 P1 Webhook/MCP side-channel cleanup.
- [ ] T3009 P1 Site/tenant local+central cleanup plan.
- [ ] T3010 P1 Import compatibility/remapping/collision validation.
- [ ] T3011 P1 Prove import does not recreate grants/Production authorization.
- [ ] T3012 P1 Final no-in-flight/no-active-secret verification.
- [ ] T3013 P1 GATE Produce decommission completion report.


## Phase 31 — Baseline, root trust and recovery
- [ ] T3101 P0 CI: current PR base SHA must be ancestor of Feature HEAD.
- [ ] T3102 P0 Emit BASELINE_STALE with ahead/behind evidence.
- [ ] T3103 P0 Review baseline deltas in OAuth/authority/MCP/certification/runtime surfaces.
- [ ] T3104 P0 Update current_baseline_head after each governed synchronization.
- [ ] T3105 P0 Define externally established Control Plane release attestation.
- [ ] T3106 P0 Separate OAuth/evidence/release/recovery trust roles.
- [ ] T3107 P0 Define release signer/root rotation and revocation.
- [ ] T3108 P0 Define minimal Recovery Plane transport/identity/grants.
- [ ] T3109 P0 Recovery read health/log/package identity.
- [ ] T3110 P0 Recovery disable known-bad package.
- [ ] T3111 P0 Recovery restore attested known-good package.
- [ ] T3112 P0 Recovery connector/service restoration path.
- [ ] T3113 P0 GATE prove Recovery Plane cannot publish/content-mutate.
- [ ] T3114 P0 GATE recover deliberately unavailable normal Control Plane path.
- [ ] T3115 P0 GATE QROOTTRUST pass.

## Phase 32 — Authoritative state and fenced execution
- [ ] T3201 P0 Declare aggregate-authoritative state per mutable aggregate.
- [ ] T3202 P0 Classify events as history/audit rather than current-state authority.
- [ ] T3203 P0 Mark indexes/dashboards as rebuildable projections.
- [ ] T3204 P0 Implement aggregate/event/artifact consistency checker.
- [ ] T3205 P0 Add BLOCKED_FOR_RECONCILIATION uncertain-state path.
- [ ] T3206 P0 Define Control Plane vs Execution Worker API boundary.
- [ ] T3207 P0 Add monotonic lease_epoch fencing token.
- [ ] T3208 P0 Require fencing epoch on worker authoritative writes.
- [ ] T3209 P0 Reject zombie worker write after newer lease.
- [ ] T3210 P0 Heartbeat cannot refresh approval/grant/certification.
- [ ] T3211 P0 Reconcile external provider execution before ambiguous retry.
- [ ] T3212 P0 Crash/reclaim test after external-success/network-timeout.
- [ ] T3213 P0 Duplicate callback/effect-once test with fencing.
- [ ] T3214 P0 GATE QEXECUTIONMODEL pass.

## Phase 33 — Commit guard, gate liveness and operating modes
- [ ] T3301 P0 Define material ExecutionGuard dependency set.
- [ ] T3302 P0 Bind approval to dependency revisions/fingerprints.
- [ ] T3303 P0 Implement pre-commit revalidation.
- [ ] T3304 P0 Kill-switch-after-approval race test.
- [ ] T3305 P0 Provider-quarantine-after-plan race test.
- [ ] T3306 P0 Target-edit-after-approval race test.
- [ ] T3307 P0 Rights/data-policy-change approval invalidation test.
- [ ] T3308 P0 Parse/validate gate-graph.json.
- [ ] T3309 P0 Reject unknown dependencies and cycles.
- [ ] T3310 P0 Verify root-to-terminal reachability.
- [ ] T3311 P0 Register bounded BootstrapTransition objects.
- [ ] T3312 P0 Prohibit hidden first-run allow branches.
- [ ] T3313 P0 Emit decisive/secondary/minimal blocker sets.
- [ ] T3314 P0 Implement ENTERPRISE_MULTI_OPERATOR policy.
- [ ] T3315 P0 Implement truthful SINGLE_OWNER_HARDENED compensating controls.
- [ ] T3316 P0 Implement EMERGENCY_RECOVERY policy.
- [ ] T3317 P0 GATE QLIVENESS pass.

## Phase 34 — Semantic/provider/privacy/data-flow hardening
- [ ] T3401 P0 CapabilityProfile trait schema.
- [ ] T3402 P0 Bit Flows workflow.execute semantic trait profile.
- [ ] T3403 P0 Bind CapabilityProfile fingerprint into plans.
- [ ] T3404 P0 Provider Resolver trait constraint matching.
- [ ] T3405 P0 IntentRelation many-to-many schema.
- [ ] T3406 P0 Role/confidence/evidence-based cannibalization analysis.
- [ ] T3407 P0 Configure ArtifactStore dedup scope by data class.
- [ ] T3408 P0 Prevent cross-tenant global hash existence oracle.
- [ ] T3409 P0 Verify hash knowledge does not authorize blob read.
- [ ] T3410 P0 Define PublicationFingerprintSet normalizers.
- [ ] T3411 P0 Explicit volatile DOM/attribute allowlist.
- [ ] T3412 P0 CONTENT/SEO/STRUCTURE/LINK/MEDIA match dimensions.
- [ ] T3413 P0 Record provenance reproducibility vs regeneration semantics.
- [ ] T3414 P0 Partition eval sets into development/regression/holdout/adversarial/human.
- [ ] T3415 P0 Track threshold/fixture exposure and evaluator identity.
- [ ] T3416 P0 Validate SupportedRuntimeProfiles.
- [ ] T3417 P0 Add pairwise/risk-based compatibility selection.
- [ ] T3418 P0 Configure OfflineAuthorizationWindow by risk class.
- [ ] T3419 P0 Record cached-evidence age used by offline action.
- [ ] T3420 P0 Separate audit/domain-event/telemetry persistence classes.
- [ ] T3421 P0 Define hot/cold audit and telemetry retention.
- [ ] T3422 P0 General DataFlowPolicy for storage/backups/index/telemetry/workflow/research/host/AI.
- [ ] T3423 P0 Derived embedding/index sensitivity inheritance.
- [ ] T3424 P0 GATE QSEMANTICS pass.

## Phase 35 — Formal state proof and architecture freeze
- [ ] T3501 P0 Model authority/grant/approval/commit-guard state machine.
- [ ] T3502 P0 Prove revoked/stale/kill-switched decisions cannot commit.
- [ ] T3503 P0 Model lease/fencing/retry state machine.
- [ ] T3504 P0 Prove older fencing epoch cannot commit.
- [ ] T3505 P0 Model provider certification/release-ring/quarantine state machine.
- [ ] T3506 P0 Prove quarantine blocks execution and promotion cannot skip evidence.
- [ ] T3507 P0 Add liveness/reachability properties.
- [ ] T3508 P0 Add ArchitectureAdmissionDecision workflow.
- [ ] T3509 P0 Freeze Critical Kernel document.
- [ ] T3510 P0 Defer non-qualified new CORE contracts to ADR/backlog/extension.
- [ ] T3511 P0 Execute exact ETG Staging Critical Kernel vertical slice.
- [ ] T3512 P0 Capture runtime-discovered redesigns.
- [ ] T3513 P0 GATE CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED.
