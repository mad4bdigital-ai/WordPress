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

## Phase 11 — Governed Tool Execution + Host Connector
- [x] T1101 P0 Define ToolOperationDefinition registry and operation fingerprints. Evidence: `tools/mad4b_host_runner.py` fixed operation registry/fingerprints + `host-runner-kernel-contract.py`.
- [ ] T1102 P0 Define ToolExecutorProfile and non-authorizing executor resolver.
- [x] T1103 P0 Define HostTarget + canonical target/root identity. Evidence: Host Runner profile/target fingerprint + bootstrap target/root binding contracts.
- [ ] T1104 P0 Define Host Read/Write/Execution/Breakglass/Recovery/Production authority classes.
- [ ] T1105 P0 GATE Prove WordPress grants, Developer and Full Staging Authority do not imply Host Execution.
- [ ] T1106 P0 Implement first read-only semantic operations: schema/runtime/package/filesystem/log/php/db/cron diagnostics.
- [x] T1107 P0 Implement `wp mad4b` namespace over shared application services. Evidence: `class-mad4b-scp-cli.php` + `wp-cli-readonly-contract.php`.
- [x] T1108 P0 Add machine-readable CLI discovery and normalized JSON output. Evidence: `wp mad4b discover/status` JSON contracts and normalized runtime status output.
- [ ] T1109 P0 GATE Prove MCP and WP-CLI equivalent normalized diagnostic result.
- [ ] T1110 P0 Implement Host Runner job envelope, queue, lease and fencing.
- [ ] T1111 P0 Implement Host Runner idempotency/retry/timeout/output/resource budgets.
- [ ] T1112 P0 Implement ToolExecutionReceipt + bounded stdout/stderr evidence.
- [x] T1113 P0 Implement named filesystem zones and realpath/symlink/zip-slip/TOCTOU defenses. Evidence: named runner zones, realpath/symlink/TOCTOU defenses + negative contract suite.
- [ ] T1114 P0 Implement process policy: fixed executable + structured argv, no caller shell strings.
- [ ] T1115 P0 Implement opaque secret handles/environment allowlist/redaction.
- [ ] T1116 P0 Implement network destination/SSRF policy for tool executors.
- [x] T1117 P0 Add CLI/Runner/Tool Doctor findings and RepairPlan integration. Evidence: Host Runner Doctor/reconciliation + Host Bridge repair-plan/requeue/doctor.
- [x] T1118 P0 GATE Shell/flag/path injection negative suite. Evidence: Host Runner/Host Bridge shell-command/path/symlink injection negative suites.
- [ ] T1119 P0 GATE Runner crash/zombie-worker/lease-fencing suite.
- [x] T1120 P0 Define minimal WordPress-independent Recovery Runner. Evidence: `tools/mad4b_recovery_plane.py` WordPress-independent Recovery Plane.
- [x] T1121 P0 GATE Recovery Runner reads package/runtime health while WordPress/plugin boot is broken. Evidence: Recovery Plane status/disable/restore contracts execute without WordPress boot/database dependency.
- [x] T1122 P0 GATE Recovery Runner restores an attested known-good package without content publication authority. Evidence: attested known-good exact-plan restore contract with Production=false and no publication authority.
- [ ] T1123 P1 Discover first Hostinger validation channels independently.
- [ ] T1124 P1 Certify provider API adapter mappings where supported.
- [ ] T1125 P1 Certify provider CLI adapter mappings where supported.
- [x] T1126 P1 Certify account-local WP-CLI/Host Runner mappings. Evidence: WP-CLI ↔ Host Runner account-local runtime.status mapping contract.
- [x] T1127 P1 GATE Prove one semantic operation across two executor adapters without Skill/domain changes. Evidence: `cross-executor-semantic-contract.py` proves `runtime.status.read` across WP-CLI and Host Runner without domain change.
- [ ] T1128 P1 Implement deterministic same-authority executor fallback with evidence.
- [ ] T1129 P1 Add provider-channel drift/quarantine handling.
- [ ] T1130 P1 Add reversible plugin.package stage/apply/rollback operation.
- [ ] T1131 P1 Add bounded host.files.patch with atomic swap/readback.
- [ ] T1132 P1 Add governed cron schedule/unschedule and cache-purge mappings.
- [x] T1133 P1 Add backup.create/restore contracts + restore rehearsal. Evidence: protected backup create/verify/restore + corruption/interruption rollback contract.
- [ ] T1134 P1 Add bounded database migration/repair operations; keep raw SQL separate.
- [x] T1135 P1 Add Host Runner DLQ/replay safety. Evidence: Host Bridge dead-letter, repair-plan, fresh requeue, replay conflict and recovery-required semantics.
- [ ] T1136 P1 Add runner/provider credential rotation/revocation.
- [ ] T1137 P1 Add executor/runner decommission and portability flow.
- [x] T1138 P1 GATE No generic shell, arbitrary `wp eval`, arbitrary PHP or generic raw SQL in ordinary catalog. Evidence: ordinary catalog security scan + Host Runner/Bridge contracts prove no generic shell, arbitrary `wp eval`, PHP or raw SQL surface.
- [ ] T1139 P2 Add standalone `mad4b` CLI when an operation must not require WordPress bootstrap.
- [ ] T1140 P2 Add bounded SSH executor only where provider/API/runner cannot satisfy certified requirement.
- [x] T1141 P2 GATE Production Host Authority remains distinct and denied by default. Evidence: Host Runner/Bootstrap/Recovery contracts keep Production authority false and separate.
- [x] T1142 P0 Define WordPress Host Bridge abilities: capabilities/plan/apply/status/cancel/receipt/doctor. Evidence: Host Bridge capabilities/plan/apply/status/cancel/receipt/doctor/repair/requeue abilities.
- [x] T1143 P0 Implement exact-plan enqueue path; WordPress request process MUST NOT become generic shell executor. Evidence: exact-plan Host Bridge spool submission; WordPress process never executes host commands.
- [ ] T1144 P0 Define durable queue backend profile and Recovery Plane independence requirement.
- [x] T1145 P0 GATE Apply submission cannot mutate reviewed operation/target/executor/argv/authority. Evidence: immutable plan digest/target/executor/authority validation before runner execution.
- [ ] T1146 P1 Certify first Hostinger WordPress-plugin/API direct mapping and Host Runner fallback without semantic contract change.
- [ ] T1147 P0 Certify runner/CLI executable provenance, resolved path and package hash.
- [ ] T1148 P0 Implement independent Host Execution/Runner/provider-channel kill switches.
- [ ] T1149 P0 Enforce offline authorization windows and fresh commit-guard validation for queued host writes.
- [ ] T1150 P0 GATE Zombie/expired runner cannot commit after fencing loss.
- [x] T1151 P0 GATE Recovery Runner trust does not depend on the broken package it may replace. Evidence: Recovery Plane verifies/restores independently from the plugin package it may replace.
- [x] T1152 P1 Add artifact-input hash/attestation verification before host package staging. Evidence: Runner bootstrap and Recovery Plane verify exact package/artifact digest/attestation before staging.
- [ ] T1153 P1 Add executor runtime-profile compatibility evidence (OS/PHP/WP-CLI/provider CLI/filesystem/process semantics).
- [x] T1154 P0 GATE Prove submission_location and authoritative execution_location are truthful and exact-plan bound across direct and queued host execution. Evidence: Host Bridge→Runner contract binds `submission_location=wordpress_request`, `execution_location=host_runner`, plan and authority.
- [x] T1155 P0 Define RunnerPackage attestation/manifest/runtime-profile contract. Evidence: `mad4b_host_runner_bootstrap.py` RunnerPackage manifest/runtime-profile contract.
- [x] T1156 P0 Define bounded runner bootstrap BootstrapTransition and authority requirements. Evidence: bounded Staging-only bootstrap transition with no standing authority creation.
- [x] T1157 P0 Implement one-time target-bound RunnerEnrollment identity handshake. Evidence: target-bound single-use RunnerEnrollment handshake contract.
- [ ] T1158 P0 Implement provider-neutral bootstrap channel resolver with no privilege-widening fallback.
- [ ] T1159 P0 Implement exact cron/service wake-up registration + readback/removal profile.
- [x] T1160 P0 GATE Reject replayed enrollment, wrong target/root, untrusted package and unexpected scheduling command. Evidence: bootstrap negative suite rejects enrollment replay, wrong target/root, untrusted package and unexpected scheduler command.
- [ ] T1161 P0 GATE Prove one supported hosting profile installs/enrolls Host Runner without interactive terminal.
- [ ] T1162 P1 Implement governed runner update/rollback/decommission lifecycle.

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


## Phase 36 — Unified implementation closure
- [x] T3601 P0 GATE Apply and independently read back the reviewed master repository ruleset with no bypass actors and pinned Release Verdict source. Evidence: ruleset `23968498`; master governance run `36072550595`; PR #64.
- [x] T3602 P0 Separate reviewed repository parent identity from deployable runtime release identity and bind the selected runtime release to external Root Trust evidence. Evidence: reviewed parent `b1f7e837efc69aa220385d84761e46f64c2442b1`; runtime release `540d5db4be521297de673c8a4d14974c23b67a6a`; package run `36072550999`; artifact `10838403565`; receipt `10838323685`.
- [x] T3603 P0 Reconcile the legacy task ledger into DONE/PARTIAL/OPEN/DEFERRED with exact commit/artifact/runtime evidence references; no bulk completion. Evidence: `reconcile_task_ledger.py`, `task-ledger-overrides.json`, `task-ledger.generated.json`.
- [ ] T3604 P0 Prepare protected backup root and prove exists/writable/ready plus current-runtime backup receipt.
- [ ] T3605 P0 Prove known-good restore preconditions and run the bounded Recovery Plane live drill without Production/Breakglass authority.
- [ ] T3606 P0 Capture and certify exact installed Bit Flows 1.29.0 artifact/tree/critical hashes/runtime semantics.
- [ ] T3607 P0 Prove or enforce Bit Flows privileged-side-channel disposition and bind semantic CapabilityProfile traits into plans.
- [ ] T3608 P0 Close Multi-Authority live subject/resource mapping and exact live certification.
- [ ] T3609 P0 Implement Policy Resolution Engine, gate DAG parser/liveness and truthful operating-mode blocker sets.
- [ ] T3610 P0 Implement existing-site bootstrap normalized content/SEO/canonical/media/link inventory.
- [ ] T3611 P0 Implement many-to-many Intent Registry ownership/collision/cannibalization decisions.
- [x] T3612 P0 Implement ContentJob domain create/transition/cancel/read services over the durable substrate. Evidence: ContentJob service + contract/runtime/concurrent CAS tests; Critical Kernel run `36095311341` MariaDB job PASS.
- [ ] T3613 P0 Implement immutable Artifact Registry/Store/edges/lineage/invalidation with tenant-safe addressing.
- [ ] T3614 P0 Implement aggregate/event/artifact consistency checker and BLOCKED_FOR_RECONCILIATION path.
- [ ] T3615 P0 Complete fencing failure model: stale/zombie writers, crash-after-success and duplicate/reordered callbacks.
- [ ] T3616 P0 Implement Knowledge Dispatcher, bounded ContextPack and dependency invalidation.
- [ ] T3617 P0 Implement immutable WriterProfile versions and exact profile/hash job binding.
- [ ] T3618 P0 Implement normalized research provider platform and evidence/error/freshness/cost contracts.
- [ ] T3619 P0 Implement competitive-intelligence artifacts and InformationGainPlan.
- [ ] T3620 P0 Implement ContentBlueprint, ArticleDraft, FactLedger and Editorial/SEO/Final QA hard gates.
- [ ] T3621 P0 Execute governed WordPress draft canary with exact PublishManifest, target fingerprint, read-after-write, audit and rollback.
- [ ] T3622 P0 Implement semantic publication verification for content/SEO/structure/link/media and origin/public propagation.
- [ ] T3623 P0 Close admitted-kernel security negatives, AI evaluation, SLO/fault/restore and provider-compromise evidence.
- [ ] T3624 P0 Implement Operator Control, Doctor, RepairPlan, DLQ/replay/quarantine and stuck/orphan diagnostics for the kernel.
- [ ] T3625 P0 Complete formal authority/approval/commit/fencing/provider state models and liveness proofs.
- [ ] T3626 P1 Complete Provider Resolver, signed workflow bridge, dynamic certification lifecycle and release-ring maturity.
- [ ] T3627 P1 Complete rights/AI-data processing, cron/provider backlog and schedule/public-publish maturity without widening kernel authority.
- [ ] T3628 P2 Complete growth/fairness/localization/accessibility/link/eval-ops/experimentation/usage maturity lanes.
- [ ] T3629 P1 Complete decommission/portability quiesce/export/import/remap/revoke/final-authority proof.
- [x] T3631 P0 Apply/read back the external master ruleset, then remove or permanently disable the initial governance bootstrap exception and prove ordinary PRs fail closed if the ruleset is absent. Evidence: PR #64 exact head `0dd96ad9a67dd7c31415b847de2019cdb6438555`, merge `540d5db4be521297de673c8a4d14974c23b67a6a`, bootstrap-retirement contract PASS.
- [ ] T3632 P0 After protected backup readiness, deploy the exact selected runtime release artifact to ETG Staging; repository HEAD equality is not required when the repository delta is proven non-runtime.
- [ ] T3633 P0 Read back runtime-release source SHA, build fingerprint, package manifest digest, archive SHA and all seven Root Trust/provenance files from the deployed runtime; UNKNOWN filesystem evidence cannot satisfy the gate.
- [ ] T3634 P0 Re-run runtime/schema/authority/fail-closed diagnostics on the exact deployed candidate before Bit Flows or content canaries.
- [x] T3635 P0 Enforce exact-head OWNER_ATTEST_SINGLE_OWNER as a repository merge-gate input, including stale-on-descendant behavior and authorized-owner identity. Evidence: verifier + repository policy + Release Verdict gate; run `36075527449` fail-closed on missing exact-head attestation.
- [x] T3636 P0 Replace the stale fixed implementation branch with workflow-owned Feature 007 branch-prefix policy. Evidence: `.github/workflows/feature-007-spec-ci.yml` + `feature.json` branch-policy mirror.
- [x] T3637 P0 Enforce reviewed-parent versus runtime-release identity separation and runtime-path drift detection in CI. Evidence: baseline-sync v2 + workflow-owned runtime identity paths + `RECAPTURE_REQUIRED` on this runtime-changing PR.
- [x] T3638 P0 Complete the minimal out-of-band Recovery Runner read/disable/known-good-restore surface and contract tests. Evidence: `tools/mad4b_recovery_plane.py`, recovery contract PASS in Critical Kernel run `36075527334`.
- [x] T3639 P0 Enforce the bulk runtime closure hardening matrix as machine-readable CI input; documentation-only evidence cannot close live gates. Evidence: `bulk-closure-hardening.json`, executable hardening contract, Feature 007 Spec Quality CI PASS on `f654e3bf77bfefc7456b661d09f2c61420d17d14`.
- [x] T3640 P0 Implement durable mutation intent/journal + atomic receipt persistence and normalize side-effect-without-evidence as MUTATED_BUT_EVIDENCE_UNCERTAIN. Evidence: Recovery Plane + Host Runner mutation journals, atomic/fsync receipts, Critical Kernel run `36078194633`.
- [x] T3641 P0 Prove reconciliation of MUTATED_BUT_EVIDENCE_UNCERTAIN without blind write retry. Evidence: Host Runner `mad4b.host-runner-reconciliation.v1` + Recovery Plane reconciliation; Critical Kernel run `36078596096` PASS.
- [ ] T3642 P0 Execute runner crash-after-lease, lease-loss-before-commit, zombie writer and duplicate/replay fixtures.
- [ ] T3643 P0 Execute crash-after-side-effect-before-receipt and provider-timeout-after-possible-side-effect fixtures.
- [ ] T3644 P0 Execute symlink/reparse swap, traversal, zip-slip, shell metacharacter and executable injection denial fixtures.
- [ ] T3645 P0 Prove timeout/output/disk/file/network/database resource budgets fail closed with stable reason codes.
- [ ] T3646 P0 Prove bootstrap executor can install/enroll only the exact attested runner package and exact scheduler entry; no standing Host authority is created.
- [x] T3647 P0 Prove WordPress-unbootable Recovery Runner health/read/known-good restore path. Evidence: standalone Recovery Plane requires neither WordPress boot nor database; recovery contract PASS in Critical Kernel run `36078194633`.
- [ ] T3648 P0 Prove protected backup integrity/capacity/exact-runtime binding and interrupted/corrupt restore behavior.
- [x] T3649 P0 Prove one semantic operation across two executor adapters with normalized-result and authority parity. Evidence: `runtime.status.read` parity across WP-CLI and Host Runner including read-only/non-authorizing authority class; Critical Kernel run `36095311341` PASS.
- [ ] T3650 P0 Prove submission/execution/commit location truthfulness and reapproval on material executor-location change.
- [x] T3651 P0 Extend gate-liveness proof so every blocking gate has a path to a terminal state, not only terminal reachability. Evidence: `validate_spec.py` reverse-DAG `reaches_terminal()` check for every KERNEL_BLOCKER/LIVE_PRECONDITION; Feature 007 Spec Quality CI PASS.
- [x] T3652 P0 Prove Doctor/DLQ/reconciliation behavior for stuck lease, orphan job, uncertain mutation and recovery-required states. Evidence: real MariaDB `operator-doctor-runtime.php` plus Host Runner journals; Critical Kernel run `36095311341` PASS.
- [ ] T3653 P0 Recertify exact installed Bit Flows 1.29.0 against permanent behavioral/security probes before execution eligibility.
- [ ] T3654 P0 Run the complete request→plan→approval→execution→readback→durable receipt→rollback vertical slice and link all evidence to the terminal gate.
- [ ] T3630 P0 GATE Execute exact ETG Staging linked evidence chain and emit CRITICAL_KERNEL_VERTICAL_SLICE_VERIFIED only when every hard dependency is satisfied.
## Phase 37 — Post-merge Capability Fabric completeness

### 37A — Canonical capability semantics and drift elimination
- [ ] T3701 P0 Build a post-merge Capability Fabric dimension matrix mapping every reviewed dimension to an owning contract, task, gate, evidence source and explicit status; no P0/P1 dimension may remain unowned.
- [ ] T3702 P0 Bind Operation Registry entries to the canonical Capability Descriptor identity instead of independently re-deriving execution/classification facts.
- [ ] T3703 P0 Bind Capability Traits profiles to the canonical descriptor/classification generation roots and prove trait metadata cannot override execution truth.
- [ ] T3704 P0 Bind Servers/catalog/direct projection rows to descriptor identity and generation roots; presentation layers remain consumers only.
- [ ] T3705 P0 Bind Authorization admission/readback to canonical descriptor identity while preserving live grant/approval/policy revalidation and no cached authority grant.
- [ ] T3706 P0 GATE Add cross-consumer drift/property/mutation tests proving Inspector, Descriptor, Operation Registry, Traits, Servers and Authorization fail closed on any schema/lane/classification/generation disagreement.

### 37B — Semantic content fields and least-privilege resource constraints
- [ ] T3707 P0 Define provider-specific semantic content-field contracts for posts, SEO, media, WooCommerce, Elementor, JetEngine and future plugins; generic string heuristics become fallback evidence only.
- [ ] T3708 P0 Unknown or newly introduced brand-bearing provider fields require explicit classification/review and cannot silently inherit mutation eligibility.
- [ ] T3709 P0 Define and compile a provider-neutral resource-constraint DSL for post IDs/types, taxonomies, filesystem zones/paths, DB tables/columns, provider object IDs, mutation counts and byte/value limits.
- [ ] T3710 P0 Bind the compiled resource-set digest to plan, preparation evidence, approval, execution guard and readback evidence.
- [ ] T3711 P0 GATE Mutation/property tests must reject resource widening, alternate identifiers, wildcard expansion, path aliasing and provider-side object substitution after approval.

### 37C — Provider postconditions, reconciliation and execution-state truth
- [ ] T3712 P0 Define a ProviderPostconditionProfile per mutation family with authoritative committed/no-effect/unknown readers and exact evidence freshness requirements.
- [ ] T3713 P0 Durable retry/reclaim eligibility requires a certified postcondition reader for that mutation family; unsupported families remain reconciliation-required and blind-retry denied.
- [ ] T3714 P0 Add timeout-after-possible-side-effect, lost-response, duplicate-callback and provider-commit/readback-delay fixtures for every certified mutation family.
- [ ] T3715 P0 Formalize Execution State View precedence across Operation Journal, Durable Execution and Connector Resilience, including a complete contradictory-evidence matrix.
- [ ] T3716 P0 GATE Prove Execution State View is fully recomputable, owns no durable state, and can never promote unknown/contradictory evidence to COMMITTED or retryable.
- [ ] T3717 P0 Define a canonical identifier-policy registry for operation/job/approval/receipt/provider IDs with UUIDv4 or explicitly versioned opaque formats, normalization and length/entropy requirements.
- [ ] T3718 P1 Provide compatibility/migration semantics for legacy non-canonical operation identifiers without rewriting historical journal identity.

### 37D — Catalog backend, cache coherence and horizontal concurrency
- [ ] T3719 P1 Design a dedicated immutable catalog table backend with content-addressed rows, generation directory, indexed expiry/GC and explicit capacity accounting.
- [ ] T3720 P1 Implement shadow-read parity and bounded cutover from options storage with one authoritative publisher at a time; no dual-authority catalog state.
- [ ] T3721 P1 Add storage-level publication fencing token/CAS semantics so lost/reused DB connections cannot publish a stale generation.
- [ ] T3722 P1 Certify persistent object-cache/Redis behavior: stale option/object cache cannot resurrect retired objects, bypass CAS, or hide a newer catalog directory.
- [ ] T3723 P1 Run multi-PHP-worker and multi-host concurrency tests proving process-local caches/mutexes are never authoritative and DB-scoped locks remain isolated.
- [ ] T3724 P1 GATE Prove backend rollback/retirement preserves projection/grants/approvals/audit/site profile and leaves no orphan capacity accounting.

### 37E — Durable multisite/network orchestration
- [ ] T3725 P0 Define a durable NetworkOperation journal with per-site target identity, authority scope, plan/preparation digest, state and evidence refs.
- [ ] T3726 P0 Define partial-completion, pause, resume and reconciliation semantics; completed sites are never falsely rolled back because another site failed.
- [ ] T3727 P0 Add network idempotency/deduplication keys and duplicate/reordered site-dispatch tests across independent workers.
- [ ] T3728 P0 Prove credentials, catalog ownership, receipts, approvals, context and authority remain site-bound during concurrent fan-out and cannot cross sites.
- [ ] T3729 P0 GATE Crash mid-fan-out and reconnect/resume must reconstruct exact completed/pending/reconciling site sets from durable evidence without replaying committed mutations.

### 37F — Projection isolation and MCP protocol evolution
- [ ] T3730 P1 Decide and codify direct hot-set isolation semantics (site-global versus client/session scoped), including contention behavior and explicit statement that fixed dispatch correctness is independent.
- [ ] T3731 P2 Add telemetry-driven adaptive hot-set recommendations with bounded quotas; recommendation/ranking can never grant authority or auto-project privileged capabilities.
- [ ] T3732 P1 Define exact tools/list refresh/reconnect/listChanged behavior per certified MCP client/protocol profile, including stale projection detection.
- [ ] T3733 P1 Add exact protocol capability negotiation and fail-closed behavior for unknown/newer protocol features rather than opportunistic assumptions.
- [ ] T3734 P1 Add dual-protocol regression for tool schemas, pagination/chunk transport, refresh semantics, notifications and error normalization when a successor Adapter is certified.
- [ ] T3735 P1 GATE Concurrent projection changes or stale clients cannot alter fixed-dispatch schema/lane/classification/authority validation or execute a removed hot-set item by visibility alone.

### 37G — Durable provider resilience and WordPress lifecycle
- [ ] T3736 P0 Implement a durable provider/site/certification-generation circuit breaker with CLOSED/OPEN/HALF_OPEN states, bounded timers and persisted transition evidence.
- [ ] T3737 P0 Breaker probe success may restore transport eligibility only; it never grants provider certification, write authority, approval or Production eligibility.
- [ ] T3738 P0 Define deterministic precedence among breaker state, provider quarantine, release ring, kill switch, certification and authority; strongest deny wins.
- [ ] T3739 P1 Define a WordPress-native lifecycle migration profile and parity certification before replacing provenance wrappers/Reflection-based compatibility paths.
- [ ] T3740 P1 Add activate/deactivate/update/uninstall/network-activate lifecycle tests proving governance retention, bounded catalog cleanup, multisite isolation and rollback compatibility.

### 37H — Distributed tracing, SLOs and observability failure semantics
- [ ] T3741 P1 Propagate a standards-compatible trace context across discovery, preparation, policy, approval, commit guard, provider call, readback, reconciliation and network fan-out.
- [ ] T3742 P1 Define tenant-safe trace/span identity, sampling, cardinality ceilings and recursive payload redaction; raw inputs/tokens/secrets/provider payloads are excluded by default.
- [ ] T3743 P1 Measure stage-level P50/P95/P99 for discovery, prepare, authorization, provider execution, readback, reconciliation and catalog rebuild, not only hard budgets.
- [ ] T3744 P1 Bind stage SLOs to error-budget/burn-rate policies and operator evidence without converting telemetry into authority.
- [ ] T3745 P1 Define observability-backend outage semantics: optional telemetry failure cannot block safe reads, while mandatory audit/evidence persistence for governed writes remains fail closed.
- [ ] T3746 P1 GATE Cross-provider/network traces must preserve causal continuity without cross-tenant correlation leakage or unbounded high-cardinality labels.

### 37I — Impact-bound approvals, explainability, execution receipts and semantic routing
- [ ] T3747 P0 Extend approval identity with exact input digest, constrained resource set, dependency generation and blast-radius/impact digest.
- [ ] T3748 P0 Invalidate approval when the resource set, dependency generation, impact graph or target fingerprint materially changes before commit.
- [ ] T3749 P1 Implement an authorization decision graph exposing PASS/FAIL/NOT_EVALUATED steps, stable reason codes, policy/evidence versions and redacted evidence refs without revealing secrets.
- [ ] T3750 P0 Define mad4b.execution-receipt.v1 linking preparation, descriptor, policy decision, approval, idempotency claim, operation journal, provider evidence, readback/reconciliation and terminal outcome.
- [ ] T3751 P0 Hash/sign the unified execution receipt, support independent verification/export and prove no missing stage can be represented as terminal success.
- [ ] T3752 P1 Implement provider-neutral semantic intent routing: user intent → required traits → provider candidates → exact capability, with confidence/evidence/ambiguity/human-review semantics.
- [ ] T3753 P1 GATE Semantic routing remains non-authorizing and cannot choose a capability outside current certification, authority, environment, resource constraints or risk policy.

### 37J — Cryptography, time, replay, canonicalization and abuse resistance
- [ ] T3754 P0 Define recursive structural redaction/classification for provider/plugin errors, callback return data, metadata and nested arrays/objects; add adversarial fuzz cases for unexpected secret field names.
- [ ] T3755 P0 Introduce versioned cryptographic profiles/key IDs for preparation/context/execution receipts with rotation, overlap, revocation and algorithm-agility rules.
- [ ] T3756 P0 Define clock-skew and monotonic-time policy for receipt TTL, lease deadlines, breaker windows, offline authorization and reconciliation observation windows.
- [ ] T3757 P0 Define replay semantics by operation risk class, including when preparation evidence is reusable versus single-use and how replay prevention composes with idempotency.
- [ ] T3758 P0 Define Unicode/canonicalization/confusable policy for ability names, semantic operation IDs, resource identifiers, URLs, paths, headers and canonical JSON/hash inputs.
- [ ] T3759 P0 Add per-principal/site/client rate limits and complexity budgets for discovery/prepare/execute, deep nesting, schema bombs, pathological regex/search terms and oversized metadata.
- [ ] T3760 P1 Extend egress policy beyond SSRF with TLS verification, proxy trust, redirect revalidation, DNS-answer changes and certificate/hostname failure semantics.
- [ ] T3761 P1 Define backward-compatible migration/deprecation telemetry for legacy unprepared dispatcher callers, versioned error contracts and explicit sunset gates.
- [ ] T3762 P1 Make critical CI hermetic/deterministic with injectable clocks, bounded deterministic test randomness, stable fixtures and no flaky-check bypass for release gates.

### 37K — Maintainability, change architecture and configuration governance
- [ ] T3763 P1 Split oversized responsibility concentrations into domain services behind stable public contracts, with dependency-cycle/size budgets and behavior-parity tests before deletion of legacy façades.
- [ ] T3764 P1 Define change-slice policy for large features: reviewable semantic units, bisect/revert boundaries, exact-head certification per slice and early split gates that prevent another mega-PR review surface.
- [ ] T3765 P1 Generate an architecture dependency graph and enforce forbidden dependency directions so presentation/adapters cannot become sources of authority or contract truth.
- [ ] T3766 P1 Define a stable machine-readable error/reason-code registry and response-schema evolution policy so clients can upgrade without parsing human messages.
- [ ] T3767 P1 Bind material configuration/feature-flag generations into diagnostics/plans where they affect behavior; config flags cannot widen authority or bypass certification.

### 37L — Completeness closure
- [ ] T3768 P0 GATE Execute the post-merge Capability Fabric completeness audit: every dimension has an owner, task, contract or explicit non-goal, test strategy, evidence source and current status.
- [ ] T3769 P0 GATE No P0/P1 dimension may remain untriaged; any OPEN item must have explicit priority, dependencies and fail-closed interim behavior.
- [ ] T3770 P0 GATE Phase 37 must map into existing quality/traceability families and must not authorize Production, Breakglass, generic shell/raw SQL, or any new authority by documentation alone.

### 37M — Request scope, database consistency and extension interference
- [x] T3771 P0 Define a RequestScopeContract for static/runtime caches across REST, MCP, WP-CLI, cron, blog switches, user switches and authoritative profile/policy/projection mutations; every cache must declare lifetime and invalidation owner.
- [x] T3772 P0 Add long-lived-worker fixtures with sequential requests for different sites/users/environments proving no static cache, identity, authority, descriptor, policy or projection state leaks across request boundaries.
- [x] T3773 P0 Define an authoritative database-topology profile for governance writes/claims/journals/commit guards/readback; governed mutation state must use read-your-writes semantics and must detect or deny unsafe read-replica routing.
- [x] T3774 P0 Define deadlock, lock-wait-timeout, connection-loss and transaction-abort semantics for approval claims, operation journal appends and durable execution; ambiguous persistence becomes reconciliation-required and never blind-retried.
- [x] T3775 P0 GATE Prove final execution admission cannot be bypassed by WordPress hook/filter priority, registration order or a later/same-priority extension mutating projected tool metadata or call arguments.
- [x] T3776 P0 Add reentrancy/recursive-dispatch protection so nested Ability calls cannot reuse/rebind approval, preparation, context, idempotency or execution evidence from a parent call without an explicit governed child operation.
- [x] T3777 P1 Certify a compatibility/conflict matrix for persistent object cache, HyperDB/read replicas, security/firewall plugins, maintenance mode, WP-CLI and cron so unsupported infrastructure fails closed with stable reason codes.

### 37N — Clone, restore time-travel and subject lifecycle
- [x] T3778 P0 Add database/site clone fixtures proving copied Site Profile, OAuth state, approvals, grants, projections and durable execution evidence are quarantined on foreign origin/environment and require explicit re-enrollment/rebinding.
- [x] T3779 P0 Define same-origin backup-restore time-travel detection: restoring an older database snapshot must not silently resurrect consumed approvals, revoked credentials, stale grants, completed idempotency claims or pre-restore projection authority assumptions.
- [x] T3780 P0 Define a monotonic RestoreEpoch/AuthorityEpoch anchored outside rollback-prone application state, or an equivalent independently verifiable mechanism, and bind it to security-sensitive persisted evidence that could otherwise replay after restore.
- [x] T3781 P0 GATE Rehearse restore of a snapshot containing previously valid but now consumed/revoked approval/token/idempotency records and prove post-restore replay is denied until governed reconciliation/re-enrollment completes.
- [x] T3782 P0 Define subject lifecycle invalidation for user deletion, role/capability demotion, Site Profile unenrollment, ChatGPT App remapping and authority/key revocation; active sessions/tokens/receipts remain non-authorizing and next admission/commit revalidates live subject state.
- [x] T3783 P0 GATE Approve an operation, then demote/delete the subject or alter its enrolled mapping before provider entry/commit; execution must fail closed without consuming a successful terminal receipt.

### 37O — Persisted contract evolution and mixed-runtime safety
- [x] T3784 P0 Define persisted contract/schema version compatibility for profiles, approvals, receipts, journal rows, catalog objects and execution records: unknown/newer versions fail closed and downgrade never silently reinterprets newer security fields.
- [x] T3785 P0 Add rolling-deploy N/N-1 worker compatibility tests across PHP workers for schema, receipts, policy/config generations, catalog state and durable records; mixed workers cannot widen authority or corrupt evidence.
- [x] T3786 P0 Define upgrade rollback/downgrade semantics with explicit durable-identity preservation or invalidation; rollback may not resurrect an older interpretation of a newer approval/receipt/authority record.
- [x] T3787 P1 Prove stale long-lived workers/processes loaded before plugin/package update cannot commit after runtime/code generation changes; they must observe a generation fence or terminate/reload before governed mutation.

### 37P — Evidence commit ordering and infrastructure exhaustion
- [x] T3788 P0 Define a mutation crash-point table covering intent persistence, approval claim, provider entry, provider return, readback, audit/journal append, durable receipt and terminal state; every boundary maps to exactly one conservative recovery state.
- [ ] T3789 P0 GATE Inject audit/journal/receipt database failure, read-only filesystem, disk-full/quota exhaustion and evidence-store unavailability before/after provider side effects; terminal success is forbidden unless required durable evidence is committed.
- [ ] T3790 P1 Preserve evidence hash-chain/trust references through archival, tiering, export/import, retention and legal-hold workflows; moving evidence cannot weaken verification or recreate authority.
- [x] T3791 P0 Define bounded evidence truncation semantics: oversized provider/error metadata may be summarized/redacted, but mandatory reason codes, digests, target identity and reconciliation pointers can never be silently dropped.
- [ ] T3792 P0 Add fatal-error/OOM/process-kill fixtures around provider entry and evidence persistence; recovery must distinguish not-started, possible-side-effect and committed-with-missing-receipt without inferring success.

### 37Q — Cancellation, transport integrity and cross-fault closure
- [ ] T3793 P0 Define cancellation propagation across queue/worker/provider boundaries; cancellation after possible side effect becomes RECONCILING/UNKNOWN until postcondition proof, never a simple cancelled terminal state.
- [ ] T3794 P1 Certify chunked/schema/catalog transport reassembly against missing, duplicate, reordered and mixed-generation chunks with content digests, bounded decompression and payload/ratio limits.
- [ ] T3795 P0 GATE Execute a cross-fault matrix covering long-lived worker cache leakage, read-replica lag, DB deadlock/loss, hook-order interference, clone/restore time-travel, subject revocation, evidence-store exhaustion, fatal interruption and post-side-effect cancellation before Capability Fabric completeness can be declared.

### 37R — Canonical identity and transactional database storage
- [x] T3796 P0 Eliminate alternate PHP serialization fallback from security-sensitive capability/schema/classification fingerprints; unsupported canonical input must become unavailable/fail-closed rather than acquire a second hash interpretation.
- [x] T3797 P0 Define database identity collation semantics for UUIDs, SHA digests, operation/idempotency/provider keys and authority fingerprints; canonical normalization plus binary/case-exact comparison must prevent collation aliases.
- [x] T3798 P0 Certify transactional storage-engine/runtime capabilities for approval, idempotency, operation-journal, audit and execution tables; nontransactional engines or unsupported implicit-commit behavior must block governed mutation.
- [x] T3799 P0 GATE Prove transaction ownership/nesting behavior (including savepoint or explicit nesting denial), canonical-hash single interpretation, storage-engine requirements and collation identity fixtures; a MAD4B transaction must never accidentally commit/rollback an unrelated caller transaction.
