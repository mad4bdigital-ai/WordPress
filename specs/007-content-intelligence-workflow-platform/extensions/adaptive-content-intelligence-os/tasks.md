# Tasks — ACI01 proposed backlog

Contract: `mad4b.aci-os.tasks.v1`. All tasks `OPEN` (documentation does not close runtime implementation). Owner/assignment and real acceptance receipts to be bound only in implementation PRs. Parent frozen release and CE01 task counts unchanged.

## ACI-G0 — spec-governance

- [ ] ACI-T0001 [OPEN] Pin parent Feature 007/CE01 reuse matrix — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: none; exit: ACI-G0 exact acceptance and denial fixtures.
- [ ] ACI-T0002 [OPEN] Freeze ACI spec invariants and contract boundary — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0001; exit: ACI-G0 exact acceptance and denial fixtures.
- [ ] ACI-T0003 [OPEN] Implement spec integrity and denial validator — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0002; exit: ACI-G0 exact acceptance and denial fixtures.
- [ ] ACI-T0004 [OPEN] Record changed-path owner slice and CI job — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0003; exit: ACI-G0 exact acceptance and denial fixtures.
- [ ] ACI-T0005 [OPEN] Review non-authorizing architecture/decision crosswalk — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0004; exit: ACI-G0 exact acceptance and denial fixtures.
- [ ] ACI-T0006 [OPEN] Certify exact-head documentation-only delivery — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0005; exit: ACI-G0 exact acceptance and denial fixtures.

## ACI-G1 — business-context

- [ ] ACI-T0007 [OPEN] Inventory native content with edit ownership — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0001; exit: ACI-G1 exact acceptance and denial fixtures.
- [ ] ACI-T0008 [OPEN] Map semantic content profiles and site identity — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0007; exit: ACI-G1 exact acceptance and denial fixtures.
- [ ] ACI-T0009 [OPEN] Detect missing brand core and language context — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0008; exit: ACI-G1 exact acceptance and denial fixtures.
- [ ] ACI-T0010 [OPEN] Build bounded versioned ContextPack — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0009; exit: ACI-G1 exact acceptance and denial fixtures.
- [ ] ACI-T0011 [OPEN] Distill WriterProfile from reviewed author assets — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0010; exit: ACI-G1 exact acceptance and denial fixtures.
- [ ] ACI-T0012 [OPEN] Certify context tenant/expiry/privacy negative fixtures — requirements: ACI-001, ACI-002, ACI-003, ACI-004, ACI-005; dependencies: ACI-T0011; exit: ACI-G1 exact acceptance and denial fixtures.

## ACI-G2 — research-evidence

- [ ] ACI-T0013 [OPEN] Define provider neutral keyword and SERP schemas — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0007; exit: ACI-G2 exact acceptance and denial fixtures.
- [ ] ACI-T0014 [OPEN] Add provider account cost reservation dry run — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0013; exit: ACI-G2 exact acceptance and denial fixtures.
- [ ] ACI-T0015 [OPEN] Normalize scraping rights/robots and provenance — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0014; exit: ACI-G2 exact acceptance and denial fixtures.
- [ ] ACI-T0016 [OPEN] Implement source dedupe and contradiction registry — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0015; exit: ACI-G2 exact acceptance and denial fixtures.
- [ ] ACI-T0017 [OPEN] Build immutable EvidencePack with exclusions — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0016; exit: ACI-G2 exact acceptance and denial fixtures.
- [ ] ACI-T0018 [OPEN] Certify uncertain external effects and spend denial — requirements: ACI-006, ACI-007, ACI-008, ACI-009, ACI-010, ACI-011, ACI-012; dependencies: ACI-T0017; exit: ACI-G2 exact acceptance and denial fixtures.

## ACI-G3 — opportunity-intelligence

- [ ] ACI-T0019 [OPEN] Inventory search intent and existing canonical assets — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0013; exit: ACI-G3 exact acceptance and denial fixtures.
- [ ] ACI-T0020 [OPEN] Compute semantic question/entity coverage matrix — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0019; exit: ACI-G3 exact acceptance and denial fixtures.
- [ ] ACI-T0021 [OPEN] Compile information-gain and competitor gaps — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0020; exit: ACI-G3 exact acceptance and denial fixtures.
- [ ] ACI-T0022 [OPEN] Rank opportunities with explainable economics — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0021; exit: ACI-G3 exact acceptance and denial fixtures.
- [ ] ACI-T0023 [OPEN] Preserve alternatives and noncausal uncertainty — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0022; exit: ACI-G3 exact acceptance and denial fixtures.
- [ ] ACI-T0024 [OPEN] Certify baseline/market/locale conflict denials — requirements: ACI-007, ACI-011, ACI-013, ACI-014; dependencies: ACI-T0023; exit: ACI-G3 exact acceptance and denial fixtures.

## ACI-G4 — native-relation-wpml

- [ ] ACI-T0025 [OPEN] Identify field owner and serialized meta schema — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0019; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0026 [OPEN] Inspect WPML Meta ID Mapper code/settings policy — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0025; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0027 [OPEN] Resolve post/term namespace and translation groups — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0026; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0028 [OPEN] Create semantic identity evidence crosswalk — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0027; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0029 [OPEN] Classify copied IDs/shared/missing translations — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0028; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0030 [OPEN] Prove bounded full-scan coverage and freshness — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0029; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0031 [OPEN] Design reversible field-owned CAS repair proposal — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0030; exit: ACI-G4 exact acceptance and denial fixtures.
- [ ] ACI-T0032 [OPEN] Run disposable WPML and read-only Staging tests — requirements: ACI-005, ACI-021, ACI-022, ACI-023, ACI-024, ACI-025, ACI-026, ACI-027; dependencies: ACI-T0031; exit: ACI-G4 exact acceptance and denial fixtures.

## ACI-G5 — blueprint-author

- [ ] ACI-T0033 [OPEN] Resolve content intent and existing asset ownership — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0025; exit: ACI-G5 exact acceptance and denial fixtures.
- [ ] ACI-T0034 [OPEN] Build cited per-locale ContentBlueprint — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0033; exit: ACI-G5 exact acceptance and denial fixtures.
- [ ] ACI-T0035 [OPEN] Version author voice and editorial profiles — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0034; exit: ACI-G5 exact acceptance and denial fixtures.
- [ ] ACI-T0036 [OPEN] Preview commercial CTA and native internal links — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0035; exit: ACI-G5 exact acceptance and denial fixtures.
- [ ] ACI-T0037 [OPEN] Preserve human edits in three-way diff — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0036; exit: ACI-G5 exact acceptance and denial fixtures.
- [ ] ACI-T0038 [OPEN] Certify translation/editorial/rights denials — requirements: ACI-013, ACI-014, ACI-015, ACI-016, ACI-017, ACI-020; dependencies: ACI-T0037; exit: ACI-G5 exact acceptance and denial fixtures.

## ACI-G6 — writer-quality

- [ ] ACI-T0039 [OPEN] Generate bounded citation-aware draft artifacts — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0033; exit: ACI-G6 exact acceptance and denial fixtures.
- [ ] ACI-T0040 [OPEN] Implement fact QA independent of writer — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0039; exit: ACI-G6 exact acceptance and denial fixtures.
- [ ] ACI-T0041 [OPEN] Implement SEO/editorial/accessibility checks — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0040; exit: ACI-G6 exact acceptance and denial fixtures.
- [ ] ACI-T0042 [OPEN] Implement rights/media/translation validators — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0041; exit: ACI-G6 exact acceptance and denial fixtures.
- [ ] ACI-T0043 [OPEN] Run adversarial prompt injection and cross-site denial — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0042; exit: ACI-G6 exact acceptance and denial fixtures.
- [ ] ACI-T0044 [OPEN] Certify quality verdict separate from publication — requirements: ACI-017, ACI-018, ACI-019, ACI-020, ACI-031; dependencies: ACI-T0043; exit: ACI-G6 exact acceptance and denial fixtures.

## ACI-G7 — durable-orchestration

- [ ] ACI-T0045 [OPEN] Compile typed stage DAG and dependency guards — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0039; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0046 [OPEN] Bind journal/CAS/lease/external effect state — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0045; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0047 [OPEN] Add account/site/job reservation ledger — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0046; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0048 [OPEN] Implement pause/cancel/reconcile/kill-switch — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0047; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0049 [OPEN] Build Arabic RTL Action Center states — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0048; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0050 [OPEN] Verify replay/crash/restore/cost concurrency tests — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0049; exit: ACI-G7 exact acceptance and denial fixtures.
- [ ] ACI-T0051 [OPEN] Prove WorkflowProvider cannot widen authority — requirements: ACI-028, ACI-029, ACI-030, ACI-037, ACI-039, ACI-040, ACI-041, ACI-043; dependencies: ACI-T0050; exit: ACI-G7 exact acceptance and denial fixtures.

## ACI-G8 — governed-publication

- [ ] ACI-T0052 [OPEN] Freeze native PublishManifest and reviewer roles — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0045; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0053 [OPEN] Preview WordPress media/SEO/translated relationships — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0052; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0054 [OPEN] Implement exact postmeta/content CAS apply — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0053; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0055 [OPEN] Verify native and rendered multilingual readback — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0054; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0056 [OPEN] Certify Undo and partial effect reconciliation — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0055; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0057 [OPEN] Prove browser/SEO/hreflang/AJAX accessibility — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0056; exit: ACI-G8 exact acceptance and denial fixtures.
- [ ] ACI-T0058 [OPEN] Certify independent publication and Production gates — requirements: ACI-019, ACI-026, ACI-027, ACI-031, ACI-032, ACI-033, ACI-039, ACI-042; dependencies: ACI-T0057; exit: ACI-G8 exact acceptance and denial fixtures.

## ACI-G9 — growth-learning

- [ ] ACI-T0059 [OPEN] Bind GSC/GA4 properties and comparative windows — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0052; exit: ACI-G9 exact acceptance and denial fixtures.
- [ ] ACI-T0060 [OPEN] Normalize currency/device/attribution by market — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0059; exit: ACI-G9 exact acceptance and denial fixtures.
- [ ] ACI-T0061 [OPEN] Analyze SEO and conversion impact uncertainty — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0060; exit: ACI-G9 exact acceptance and denial fixtures.
- [ ] ACI-T0062 [OPEN] Generate optimization proposal only — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0061; exit: ACI-G9 exact acceptance and denial fixtures.
- [ ] ACI-T0063 [OPEN] Run seasonality/confounding/vanity negative fixtures — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0062; exit: ACI-G9 exact acceptance and denial fixtures.
- [ ] ACI-T0064 [OPEN] Measure actual cost-to-value without causal overclaim — requirements: ACI-006, ACI-007, ACI-034, ACI-035, ACI-036; dependencies: ACI-T0063; exit: ACI-G9 exact acceptance and denial fixtures.

## ACI-G10 — release-operability

- [ ] ACI-T0065 [OPEN] Reconcile exact Staging runtime/grants/Skills — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0059; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0066 [OPEN] Certify provider/site and host sandbox boundaries — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0065; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0067 [OPEN] Run native MySQL/MariaDB/WPML compatibility — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0066; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0068 [OPEN] Run real browser/RTL/accessibility journeys — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0067; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0069 [OPEN] Benchmark workload and failure budgets — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0068; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0070 [OPEN] Certify restore epoch, rollback and idempotency — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0069; exit: ACI-G10 exact acceptance and denial fixtures.
- [ ] ACI-T0071 [OPEN] Record separate owner release/promotion decision — requirements: ACI-028, ACI-030, ACI-033, ACI-037, ACI-038, ACI-039, ACI-040, ACI-041, ACI-042, ACI-043; dependencies: ACI-T0070; exit: ACI-G10 exact acceptance and denial fixtures.
