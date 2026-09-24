#!/usr/bin/env python3
import json, pathlib, re, sys

ROOT = pathlib.Path("specs/007-content-intelligence-workflow-platform")
errors = []

def require_file(rel):
    p = ROOT / rel
    if not p.is_file() or p.stat().st_size == 0:
        errors.append(f"missing_or_empty:{rel}")
    return p

required = [
    "feature.json","README.md","constitution.md","spec.md","research.md","data-model.md",
    "data-model-authority-certification.md","data-model-operations-lifecycle.md","plan.md","tasks.md","quickstart.md","runbook.md",
    "traceability.md","coverage-audit.md","quality-model.md","quality-scorecard.md",
    "checklists/requirements.md",
    "contracts/architecture-boundaries.md","contracts/release-lineage.md",
    "contracts/multi-authority-registry.md","contracts/subject-mapping.md",
    "contracts/multi-authority-live-certification.md","contracts/oauth-key-lifecycle.md",
    "contracts/mcp-protocol-evolution.md","contracts/workflow-provider.md",
    "contracts/workflow-provider-diagnostic.md","contracts/provider-certification.md",
    "contracts/dynamic-provider-certification.md","contracts/behavioral-probe-catalog.md",
    "contracts/provider-resolver.md","contracts/signed-workflow-bridge.md",
    "contracts/release-rings.md","contracts/content-job.md","contracts/context-dispatch.md",
    "contracts/research-provider.md","contracts/artifact-and-quality-gates.md",
    "contracts/skills-orchestration.md","contracts/publishing.md","contracts/plugin-onboarding.md",
    "contracts/host-connector.md","contracts/cron-and-growth.md",
    "contracts/observability-and-evidence.md","contracts/generalization-rules.md",
    "contracts/spec-isolation.md","contracts/correctness-consistency-idempotency.md",
    "contracts/durable-execution-resilience.md","contracts/schema-contract-evolution.md",
    "contracts/security-threat-model.md","contracts/supply-chain-secrets.md",
    "contracts/source-trust-ai-evaluation.md","contracts/tenant-privacy-retention.md",
    "contracts/performance-capacity-cost.md","contracts/disaster-recovery-operability.md",
    "contracts/verification-testing-strategy.md","contracts/policy-drift-kill-switches.md",
    "contracts/policy-resolution-separation-of-duties.md",
    "contracts/evidence-attestation-trust.md",
    "contracts/existing-site-bootstrap-content-inventory.md",
    "contracts/artifact-storage-retrieval.md",
    "contracts/incremental-recompute.md",
    "contracts/publication-verification.md",
    "contracts/content-rights-licensing.md",
    "contracts/ai-data-processing-residency.md",
    "contracts/operator-control-doctor-deadletter.md",
    "contracts/provider-conformance-contract-lifecycle.md",
    "contracts/fair-scheduling-local-autonomy.md",
    "contracts/localization-accessibility-linkgraph.md",
    "contracts/eval-registry-alerting.md",
    "contracts/experimentation-attribution.md",
    "contracts/usage-ledger-chargeback.md",
    "contracts/decommission-portability.md",
]
for rel in required:
    require_file(rel)

feature_path = require_file("feature.json")
if feature_path.exists():
    data = json.loads(feature_path.read_text(encoding="utf-8"))
    expected = {
        "feature_id": "007",
        "feature_name": "content-intelligence-workflow-platform",
        "merge_authorized": False,
        "production_activation_authorized": False,
    }
    for k, v in expected.items():
        if data.get(k) != v:
            errors.append(f"feature_field:{k}:expected={v!r}:got={data.get(k)!r}")
    if data.get("required_phase_count") != 31:
        errors.append(f"feature_field:required_phase_count:expected=31:got={data.get('required_phase_count')!r}")
    gates = data.get("required_gates", [])
    if not isinstance(gates, list) or len(gates) != len(set(gates)):
        errors.append("required_gates:not_unique_list")
    mandatory_gates = {
        "rc59_pr45_pr47_semantic_reconciliation","multi_authority_live_certification",
        "workflow_provider_capability_certification","capability_fingerprint_certification",
        "atomic_state_and_idempotency","durable_execution_and_backpressure",
        "security_threat_model","source_trust_prompt_injection_resistance",
        "tenant_privacy_isolation","disaster_recovery_restore_rehearsal",
        "cross_feature_spec_isolation",
        "policy_resolution_precedence","separation_of_duties_approval_governance",
        "signed_evidence_attestation_trust","existing_site_bootstrap_inventory",
        "content_intent_registry","artifact_store_integrity",
        "incremental_recompute_minimality","publication_verification_plane",
        "content_rights_licensing","ai_data_processing_residency",
        "operator_control_doctor_dlq","provider_conformance_contract_lifecycle",
        "fair_scheduling_noisy_neighbor","central_site_autonomy",
        "localization_accessibility_link_graph","eval_registry_error_budget_alerting",
        "experimentation_governance","usage_ledger_chargeback","decommission_portability"
    }
    missing = mandatory_gates.difference(gates)
    if missing:
        errors.append("missing_gates:" + ",".join(sorted(missing)))

tasks_path = require_file("tasks.md")
if tasks_path.exists():
    txt = tasks_path.read_text(encoding="utf-8")
    ids = re.findall(r"\bT(\d{4})\b", txt)
    dupes = sorted({x for x in ids if ids.count(x) > 1})
    if dupes:
        errors.append("duplicate_task_ids:" + ",".join(dupes))
    for phase in range(0, 31):
        # Current Feature 007 execution plan expects phases 0..30.
        if f"Phase {phase} " not in txt and f"Phase {phase} —" not in txt:
            errors.append(f"missing_task_phase:{phase}")

trace = require_file("traceability.md")
if trace.exists():
    t = trace.read_text(encoding="utf-8")
    for family in ["AUTH","DPC","CJ","ART","QCORR","QRES","QSEC","QAI","QTENANT","QPERF","QDR","QSPEC","POLICY","ATTEST","BOOT","INTENT","STORE","RECOMP","PVERIFY","RIGHTS","AIDATA","OPS","CONF","FAIR","LOC","EVALREG","EXP","USAGE","PORT"]:
        if f"| {family} |" not in t:
            errors.append(f"traceability_missing:{family}")

quality = require_file("quality-model.md")
if quality.exists():
    q = quality.read_text(encoding="utf-8")
    for gate in ["QCORRECTNESS","QRESILIENCE","QSECURITY","QSUPPLYCHAIN","QDATA","QPERF","QEVAL","QRECOVERY","QCOMPAT","QGOVERNANCE","QCONTENTSTATE","QRIGHTS","QOPERABILITY","QPORTABILITY"]:
        if gate not in q:
            errors.append(f"quality_gate_missing:{gate}")

for rel in ["spec.md","plan.md","coverage-audit.md"]:
    p=require_file(rel)
    if p.exists():
        text=p.read_text(encoding="utf-8")
        if "Production" not in text:
            errors.append(f"production_boundary_not_documented:{rel}")

if errors:
    print("FEATURE_007_SPEC_VALIDATION: FAIL")
    for e in errors:
        print(" -", e)
    sys.exit(1)

print("FEATURE_007_SPEC_VALIDATION: PASS")
print(f"required_files={len(required)}")
print("merge_authorized=false")
print("production_activation_authorized=false")
