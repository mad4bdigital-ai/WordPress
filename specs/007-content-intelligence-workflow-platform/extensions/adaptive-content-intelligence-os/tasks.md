# ACI01 Dynamic Task Backlog

Contract `mad4b.aci-os.task-registry.v2`. Task dependencies are materialized input prerequisites, not gate certificates. Site, content recipe and exact current evidence decide applicability; no task grants authority. Normative registry: `task-registry.json`.

## ACI-G0 — spec_integrity

- [ ] ACI-T0001 [OPEN] Pin parent Feature 007/CE01 reuse matrix — requirements: ACI-001, ACI-028, ACI-042; dependencies: none; exit: ACI-G0 / mad4b.aci.task.0001.v1.
- [ ] ACI-T0002 [OPEN] Freeze ACI spec invariants and contract boundary — requirements: ACI-001, ACI-033, ACI-043; dependencies: ACI-T0001; exit: ACI-G0 / mad4b.aci.task.0002.v1.
- [ ] ACI-T0003 [OPEN] Implement spec integrity and denial validator — requirements: ACI-042; dependencies: ACI-T0002; exit: ACI-G0 / mad4b.aci.task.0003.v1.
- [ ] ACI-T0004 [OPEN] Record changed-path owner slice and CI job — requirements: ACI-042; dependencies: ACI-T0003; exit: ACI-G0 / mad4b.aci.task.0004.v1.
- [ ] ACI-T0005 [OPEN] Review non-authorizing architecture/decision crosswalk — requirements: ACI-005, ACI-028; dependencies: ACI-T0001; exit: ACI-G0 / mad4b.aci.task.0005.v1.
- [ ] ACI-T0006 [OPEN] Certify exact-head documentation-only delivery — requirements: ACI-042; dependencies: ACI-T0003, ACI-T0004, ACI-T0005; exit: ACI-G0 / mad4b.aci.task.0006.v1.

## ACI-G1 — business_context

- [ ] ACI-T0007 [OPEN] Inventory native content with edit ownership — requirements: ACI-002, ACI-020; dependencies: ACI-T0001; exit: ACI-G1 / mad4b.aci.task.0007.v1.
- [ ] ACI-T0008 [OPEN] Map semantic content profiles and site identity — requirements: ACI-001, ACI-005; dependencies: ACI-T0007; exit: ACI-G1 / mad4b.aci.task.0008.v1.
- [ ] ACI-T0009 [OPEN] Detect missing brand core and language context — requirements: ACI-003; dependencies: ACI-T0001; exit: ACI-G1 / mad4b.aci.task.0009.v1.
- [ ] ACI-T0010 [OPEN] Build bounded versioned ContextPack — requirements: ACI-004; dependencies: ACI-T0009; exit: ACI-G1 / mad4b.aci.task.0010.v1.
- [ ] ACI-T0011 [OPEN] Distill WriterProfile from reviewed author assets — requirements: ACI-004, ACI-016; dependencies: ACI-T0010; exit: ACI-G1 / mad4b.aci.task.0011.v1.
- [ ] ACI-T0012 [OPEN] Certify context tenant/expiry/privacy negative fixtures — requirements: ACI-001, ACI-004; dependencies: ACI-T0008, ACI-T0010, ACI-T0011; exit: ACI-G1 / mad4b.aci.task.0012.v1.

## ACI-G2 — research_evidence

- [ ] ACI-T0013 [OPEN] Define provider neutral keyword and SERP schemas — requirements: ACI-006, ACI-008; dependencies: ACI-T0001; exit: ACI-G2 / mad4b.aci.task.0013.v1.
- [ ] ACI-T0014 [OPEN] Add provider account cost reservation dry run — requirements: ACI-008, ACI-030; dependencies: ACI-T0013, ACI-T0047; exit: ACI-G2 / mad4b.aci.task.0014.v1.
- [ ] ACI-T0015 [OPEN] Normalize scraping rights/robots and provenance — requirements: ACI-008, ACI-010; dependencies: ACI-T0013, ACI-T0014; exit: ACI-G2 / mad4b.aci.task.0015.v1.
- [ ] ACI-T0016 [OPEN] Implement source dedupe and contradiction registry — requirements: ACI-009, ACI-010; dependencies: ACI-T0015; exit: ACI-G2 / mad4b.aci.task.0016.v1.
- [ ] ACI-T0017 [OPEN] Build immutable EvidencePack with exclusions — requirements: ACI-009, ACI-012; dependencies: ACI-T0016; exit: ACI-G2 / mad4b.aci.task.0017.v1.
- [ ] ACI-T0018 [OPEN] Certify uncertain external effects and spend denial — requirements: ACI-008, ACI-030; dependencies: ACI-T0014, ACI-T0047; exit: ACI-G2 / mad4b.aci.task.0018.v1.

## ACI-G3 — opportunity_decisions

- [ ] ACI-T0019 [OPEN] Inventory search intent and existing canonical assets — requirements: ACI-007, ACI-013; dependencies: ACI-T0007, ACI-T0013; exit: ACI-G3 / mad4b.aci.task.0019.v1.
- [ ] ACI-T0020 [OPEN] Compute semantic question/entity coverage matrix — requirements: ACI-007, ACI-014; dependencies: ACI-T0019, ACI-T0016; exit: ACI-G3 / mad4b.aci.task.0020.v1.
- [ ] ACI-T0021 [OPEN] Compile information-gain and competitor gaps — requirements: ACI-014; dependencies: ACI-T0020; exit: ACI-G3 / mad4b.aci.task.0021.v1.
- [ ] ACI-T0022 [OPEN] Rank opportunities with explainable economics — requirements: ACI-011; dependencies: ACI-T0021, ACI-T0014; exit: ACI-G3 / mad4b.aci.task.0022.v1.
- [ ] ACI-T0023 [OPEN] Preserve alternatives and noncausal uncertainty — requirements: ACI-010, ACI-012; dependencies: ACI-T0022; exit: ACI-G3 / mad4b.aci.task.0023.v1.
- [ ] ACI-T0024 [OPEN] Certify baseline/market/locale conflict denials — requirements: ACI-006, ACI-012; dependencies: ACI-T0023, ACI-T0017; exit: ACI-G3 / mad4b.aci.task.0024.v1.

## ACI-G4 — native_relations

- [ ] ACI-T0025 [OPEN] Identify field owner and serialized meta schema — requirements: ACI-021; dependencies: ACI-T0007; exit: ACI-G4 / mad4b.aci.task.0025.v1.
- [ ] ACI-T0026 [OPEN] Inspect WPML Meta ID Mapper code/settings policy — requirements: ACI-024; dependencies: ACI-T0025; exit: ACI-G4 / mad4b.aci.task.0026.v1.
- [ ] ACI-T0027 [OPEN] Resolve post/term namespace and translation groups — requirements: ACI-021, ACI-023; dependencies: ACI-T0025; exit: ACI-G4 / mad4b.aci.task.0027.v1.
- [ ] ACI-T0028 [OPEN] Create semantic identity evidence crosswalk — requirements: ACI-022, ACI-023; dependencies: ACI-T0027, ACI-T0026; exit: ACI-G4 / mad4b.aci.task.0028.v1.
- [ ] ACI-T0029 [OPEN] Classify copied IDs/shared/missing translations — requirements: ACI-024, ACI-025; dependencies: ACI-T0028; exit: ACI-G4 / mad4b.aci.task.0029.v1.
- [ ] ACI-T0030 [OPEN] Prove bounded full-scan coverage and freshness — requirements: ACI-022, ACI-025; dependencies: ACI-T0027; exit: ACI-G4 / mad4b.aci.task.0030.v1.
- [ ] ACI-T0031 [OPEN] Design reversible field-owned CAS repair proposal — requirements: ACI-026; dependencies: ACI-T0028, ACI-T0029, ACI-T0046; exit: ACI-G4 / mad4b.aci.task.0031.v1.
- [ ] ACI-T0032 [OPEN] Run disposable WPML and read-only Staging tests — requirements: ACI-027, ACI-042; dependencies: ACI-T0029, ACI-T0030; exit: ACI-G4 / mad4b.aci.task.0032.v1.

## ACI-G5 — blueprint_authors

- [ ] ACI-T0033 [OPEN] Resolve content intent and existing asset ownership — requirements: ACI-013, ACI-020; dependencies: ACI-T0019, ACI-T0008; exit: ACI-G5 / mad4b.aci.task.0033.v1.
- [ ] ACI-T0034 [OPEN] Build cited per-locale ContentBlueprint — requirements: ACI-015; dependencies: ACI-T0021, ACI-T0017, ACI-T0010; exit: ACI-G5 / mad4b.aci.task.0034.v1.
- [ ] ACI-T0035 [OPEN] Version author voice and editorial profiles — requirements: ACI-016; dependencies: ACI-T0011; exit: ACI-G5 / mad4b.aci.task.0035.v1.
- [ ] ACI-T0036 [OPEN] Preview commercial CTA and native internal links — requirements: ACI-015, ACI-019; dependencies: ACI-T0034; exit: ACI-G5 / mad4b.aci.task.0036.v1.
- [ ] ACI-T0037 [OPEN] Preserve human edits in three-way diff — requirements: ACI-020; dependencies: ACI-T0033; exit: ACI-G5 / mad4b.aci.task.0037.v1.
- [ ] ACI-T0038 [OPEN] Certify translation/editorial/rights denials — requirements: ACI-018, ACI-020; dependencies: ACI-T0034, ACI-T0035, ACI-T0037; exit: ACI-G5 / mad4b.aci.task.0038.v1.

## ACI-G6 — writing_quality

- [ ] ACI-T0039 [OPEN] Generate bounded citation-aware draft artifacts — requirements: ACI-017; dependencies: ACI-T0034, ACI-T0035, ACI-T0017; exit: ACI-G6 / mad4b.aci.task.0039.v1.
- [ ] ACI-T0040 [OPEN] Implement fact QA independent of writer — requirements: ACI-018; dependencies: ACI-T0039; exit: ACI-G6 / mad4b.aci.task.0040.v1.
- [ ] ACI-T0041 [OPEN] Implement SEO/editorial/accessibility checks — requirements: ACI-018; dependencies: ACI-T0039; exit: ACI-G6 / mad4b.aci.task.0041.v1.
- [ ] ACI-T0042 [OPEN] Implement rights/media/translation validators — requirements: ACI-018, ACI-019; dependencies: ACI-T0039; exit: ACI-G6 / mad4b.aci.task.0042.v1.
- [ ] ACI-T0043 [OPEN] Run adversarial prompt injection and cross-site denial — requirements: ACI-018, ACI-020; dependencies: ACI-T0039; exit: ACI-G6 / mad4b.aci.task.0043.v1.
- [ ] ACI-T0044 [OPEN] Certify quality verdict separate from publication — requirements: ACI-018, ACI-031; dependencies: ACI-T0040, ACI-T0041, ACI-T0042, ACI-T0043; exit: ACI-G6 / mad4b.aci.task.0044.v1.

## ACI-G7 — orchestration

- [ ] ACI-T0045 [OPEN] Compile typed stage DAG and dependency guards — requirements: ACI-028, ACI-029; dependencies: ACI-T0001; exit: ACI-G7 / mad4b.aci.task.0045.v1.
- [ ] ACI-T0046 [OPEN] Bind journal/CAS/lease/external effect state — requirements: ACI-028, ACI-030, ACI-041; dependencies: ACI-T0045; exit: ACI-G7 / mad4b.aci.task.0046.v1.
- [ ] ACI-T0047 [OPEN] Add account/site/job reservation ledger — requirements: ACI-030, ACI-036; dependencies: ACI-T0046; exit: ACI-G7 / mad4b.aci.task.0047.v1.
- [ ] ACI-T0048 [OPEN] Implement pause/cancel/reconcile/kill-switch — requirements: ACI-030, ACI-041; dependencies: ACI-T0046; exit: ACI-G7 / mad4b.aci.task.0048.v1.
- [ ] ACI-T0049 [OPEN] Build Arabic RTL Action Center states — requirements: ACI-037, ACI-038, ACI-040; dependencies: ACI-T0045; exit: ACI-G7 / mad4b.aci.task.0049.v1.
- [ ] ACI-T0050 [OPEN] Verify replay/crash/restore/cost concurrency tests — requirements: ACI-028, ACI-041; dependencies: ACI-T0046, ACI-T0047, ACI-T0048; exit: ACI-G7 / mad4b.aci.task.0050.v1.
- [ ] ACI-T0051 [OPEN] Prove WorkflowProvider cannot widen authority — requirements: ACI-029, ACI-033, ACI-043; dependencies: ACI-T0045, ACI-T0046; exit: ACI-G7 / mad4b.aci.task.0051.v1.

## ACI-G8 — publication

- [ ] ACI-T0052 [OPEN] Freeze native PublishManifest and reviewer roles — requirements: ACI-031, ACI-039; dependencies: ACI-T0044, ACI-T0046, ACI-T0037; exit: ACI-G8 / mad4b.aci.task.0052.v1.
- [ ] ACI-T0053 [OPEN] Preview WordPress media/SEO/translated relationships — requirements: ACI-019, ACI-031; dependencies: ACI-T0052; exit: ACI-G8 / mad4b.aci.task.0053.v1.
- [ ] ACI-T0054 [OPEN] Implement exact postmeta/content CAS apply — requirements: ACI-032; dependencies: ACI-T0053, ACI-T0051, ACI-T0065; exit: ACI-G8 / mad4b.aci.task.0054.v1.
- [ ] ACI-T0055 [OPEN] Verify native and rendered multilingual readback — requirements: ACI-027, ACI-032; dependencies: ACI-T0054; exit: ACI-G8 / mad4b.aci.task.0055.v1.
- [ ] ACI-T0056 [OPEN] Certify Undo and partial effect reconciliation — requirements: ACI-027, ACI-032; dependencies: ACI-T0054; exit: ACI-G8 / mad4b.aci.task.0056.v1.
- [ ] ACI-T0057 [OPEN] Prove browser/SEO/hreflang/AJAX accessibility — requirements: ACI-018, ACI-032, ACI-042; dependencies: ACI-T0055; exit: ACI-G8 / mad4b.aci.task.0057.v1.
- [ ] ACI-T0058 [OPEN] Certify independent publication and Production gates — requirements: ACI-033, ACI-042, ACI-043; dependencies: ACI-T0055, ACI-T0056, ACI-T0057, ACI-T0065, ACI-T0066; exit: ACI-G8 / mad4b.aci.task.0058.v1.

## ACI-G9 — growth

- [ ] ACI-T0059 [OPEN] Bind GSC/GA4 properties and comparative windows — requirements: ACI-034; dependencies: ACI-T0013, ACI-T0014; exit: ACI-G9 / mad4b.aci.task.0059.v1.
- [ ] ACI-T0060 [OPEN] Normalize currency/device/attribution by market — requirements: ACI-034; dependencies: ACI-T0059; exit: ACI-G9 / mad4b.aci.task.0060.v1.
- [ ] ACI-T0061 [OPEN] Analyze SEO and conversion impact uncertainty — requirements: ACI-035; dependencies: ACI-T0060; exit: ACI-G9 / mad4b.aci.task.0061.v1.
- [ ] ACI-T0062 [OPEN] Generate optimization proposal only — requirements: ACI-035, ACI-036; dependencies: ACI-T0061; exit: ACI-G9 / mad4b.aci.task.0062.v1.
- [ ] ACI-T0063 [OPEN] Run seasonality/confounding/vanity negative fixtures — requirements: ACI-035, ACI-036; dependencies: ACI-T0061; exit: ACI-G9 / mad4b.aci.task.0063.v1.
- [ ] ACI-T0064 [OPEN] Measure actual cost-to-value without causal overclaim — requirements: ACI-036; dependencies: ACI-T0062, ACI-T0063; exit: ACI-G9 / mad4b.aci.task.0064.v1.

## ACI-G10 — release

- [ ] ACI-T0065 [OPEN] Reconcile exact Staging runtime/grants/Skills — requirements: ACI-033, ACI-042; dependencies: ACI-T0006, ACI-T0046; exit: ACI-G10 / mad4b.aci.task.0065.v1.
- [ ] ACI-T0066 [OPEN] Certify provider/site and host sandbox boundaries — requirements: ACI-040, ACI-042; dependencies: ACI-T0045, ACI-T0065; exit: ACI-G10 / mad4b.aci.task.0066.v1.
- [ ] ACI-T0067 [OPEN] Run native MySQL/MariaDB/WPML compatibility — requirements: ACI-021, ACI-042; dependencies: ACI-T0066; exit: ACI-G10 / mad4b.aci.task.0067.v1.
- [ ] ACI-T0068 [OPEN] Run real browser/RTL/accessibility journeys — requirements: ACI-038, ACI-042; dependencies: ACI-T0055, ACI-T0066; exit: ACI-G10 / mad4b.aci.task.0068.v1.
- [ ] ACI-T0069 [OPEN] Benchmark workload and failure budgets — requirements: ACI-030, ACI-041, ACI-042; dependencies: ACI-T0050, ACI-T0066; exit: ACI-G10 / mad4b.aci.task.0069.v1.
- [ ] ACI-T0070 [OPEN] Certify restore epoch, rollback and idempotency — requirements: ACI-027, ACI-041; dependencies: ACI-T0056, ACI-T0046; exit: ACI-G10 / mad4b.aci.task.0070.v1.
- [ ] ACI-T0071 [OPEN] Record separate owner release/promotion decision — requirements: ACI-033, ACI-042, ACI-043; dependencies: ACI-T0058, ACI-T0064, ACI-T0067, ACI-T0068, ACI-T0069, ACI-T0070; exit: ACI-G10 / mad4b.aci.task.0071.v1.
