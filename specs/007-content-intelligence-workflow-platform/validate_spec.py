#!/usr/bin/env python3
import json, pathlib, re, sys
from collections import defaultdict, deque

ROOT = pathlib.Path("specs/007-content-intelligence-workflow-platform")
errors = []

def require_file(rel):
    p = ROOT / rel
    if not p.is_file() or p.stat().st_size == 0:
        errors.append(f"missing_or_empty:{rel}")
    return p

required = [
    "feature.json","README.md","constitution.md","spec.md","research.md","data-model.md",
    "data-model-authority-certification.md","data-model-operations-lifecycle.md","data-model-critical-kernel.md",
    "critical-kernel.md","gate-graph.json","supported-runtime-profiles.json",
    "plan.md","tasks.md","quickstart.md","runbook.md","traceability.md","coverage-audit.md",
    "quality-model.md","quality-scorecard.md","checklists/requirements.md",
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
    "contracts/policy-resolution-separation-of-duties.md","contracts/evidence-attestation-trust.md",
    "contracts/existing-site-bootstrap-content-inventory.md","contracts/artifact-storage-retrieval.md",
    "contracts/incremental-recompute.md","contracts/publication-verification.md",
    "contracts/content-rights-licensing.md","contracts/ai-data-processing-residency.md",
    "contracts/operator-control-doctor-deadletter.md","contracts/provider-conformance-contract-lifecycle.md",
    "contracts/fair-scheduling-local-autonomy.md","contracts/localization-accessibility-linkgraph.md",
    "contracts/eval-registry-alerting.md","contracts/experimentation-attribution.md",
    "contracts/usage-ledger-chargeback.md","contracts/decommission-portability.md",
    "contracts/baseline-synchronization.md","contracts/authoritative-state-projections.md",
    "contracts/execution-plane-fencing.md","contracts/execution-commit-guard.md",
    "contracts/gate-dag-bootstrap-liveness.md","contracts/root-trust-recovery-plane.md",
    "contracts/capability-traits.md","contracts/intent-ownership.md",
    "contracts/privacy-safe-content-addressing.md","contracts/publication-semantic-fingerprint.md",
    "contracts/ai-eval-integrity.md","contracts/runtime-profile-compatibility.md",
    "contracts/offline-authorization-window.md","contracts/audit-telemetry-retention.md",
    "contracts/data-flow-policy.md","contracts/formal-model-critical-state.md",
    "contracts/architecture-freeze.md",
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
        "architecture_freeze": True,
        "required_phase_count": 36,
    }
    for k, v in expected.items():
        if data.get(k) != v:
            errors.append(f"feature_field:{k}:expected={v!r}:got={data.get(k)!r}")
    for key in ["baseline_head_at_creation","current_baseline_head","baseline_sync_commit"]:
        val=data.get(key)
        if not isinstance(val,str) or not re.fullmatch(r"[0-9a-f]{40}", val):
            errors.append(f"feature_sha_invalid:{key}:{val!r}")
    gates = data.get("required_gates", [])
    if not isinstance(gates, list) or len(gates) != len(set(gates)):
        errors.append("required_gates:not_unique_list")
    mandatory_gates = {
        "rc59_pr45_pr47_semantic_reconciliation","multi_authority_live_certification",
        "workflow_provider_capability_certification","capability_fingerprint_certification",
        "atomic_state_and_idempotency","durable_execution_and_backpressure",
        "security_threat_model","source_trust_prompt_injection_resistance",
        "tenant_privacy_isolation","disaster_recovery_restore_rehearsal",
        "cross_feature_spec_isolation","policy_resolution_precedence",
        "separation_of_duties_approval_governance","signed_evidence_attestation_trust",
        "existing_site_bootstrap_inventory","content_intent_registry","artifact_store_integrity",
        "incremental_recompute_minimality","publication_verification_plane",
        "content_rights_licensing","ai_data_processing_residency",
        "operator_control_doctor_dlq","provider_conformance_contract_lifecycle",
        "fair_scheduling_noisy_neighbor","central_site_autonomy",
        "localization_accessibility_link_graph","eval_registry_error_budget_alerting",
        "experimentation_governance","usage_ledger_chargeback","decommission_portability",
        "baseline_current_ancestor","root_release_provenance","out_of_band_recovery_plane",
        "authoritative_state_model","execution_plane_separation","lease_fencing_tokens",
        "execution_commit_guard","approval_dependency_invalidation","gate_dag_acyclic",
        "gate_liveness_reachability","bootstrap_transition_governance",
        "single_owner_operating_mode_truthfulness","capability_traits_semantics",
        "intent_many_to_many_semantics","privacy_safe_content_addressing",
        "semantic_publication_fingerprint","ai_provenance_reproducibility",
        "eval_holdout_integrity","supported_runtime_profiles","offline_authorization_windows",
        "audit_telemetry_separation","data_flow_policy","formal_critical_state_models",
        "architecture_freeze_critical_kernel"
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
    for phase in range(0, 36):
        if f"Phase {phase} " not in txt and f"Phase {phase} —" not in txt:
            errors.append(f"missing_task_phase:{phase}")

plan_path = require_file("plan.md")
if tasks_path.exists() and plan_path.exists():
    task_txt = tasks_path.read_text(encoding="utf-8")
    plan_txt = plan_path.read_text(encoding="utf-8")
    task_phases = {int(x) for x in re.findall(r"^## Phase (\d+)\b", task_txt, flags=re.MULTILINE)}
    plan_phases = {int(x) for x in re.findall(r"^## Phase (\d+)\b", plan_txt, flags=re.MULTILINE)}
    expected_count = data.get("required_phase_count") if feature_path.exists() else None
    if isinstance(expected_count, int):
        expected_phases = set(range(expected_count))
        if task_phases != expected_phases:
            errors.append("plan_task_phase_parity:tasks_vs_feature:" + ",".join(map(str, sorted(task_phases ^ expected_phases))))
        if plan_phases != expected_phases:
            errors.append("plan_task_phase_parity:plan_vs_feature:" + ",".join(map(str, sorted(plan_phases ^ expected_phases))))
    if plan_phases != task_phases:
        errors.append("plan_task_phase_parity:plan_vs_tasks:" + ",".join(map(str, sorted(plan_phases ^ task_phases))))

trace = require_file("traceability.md")
if trace.exists():
    t = trace.read_text(encoding="utf-8")
    families = [
        "AUTH","DPC","CJ","ART","QCORR","QRES","QSEC","QAI","QTENANT","QPERF","QDR","QSPEC",
        "POLICY","ATTEST","BOOT","INTENT","STORE","RECOMP","PVERIFY","RIGHTS","AIDATA","OPS","CONF",
        "FAIR","LOC","EVALREG","EXP","USAGE","PORT","BASESYNC","ROOT","STATE","FENCE","COMMIT",
        "LIVENESS","TRAIT","PRIVHASH","PUBFP","AIINT","PROFILE","OFFLINE","AUDITSEP","DATAFLOW",
        "FORMAL","FREEZE"
    ]
    for family in families:
        if f"| {family} |" not in t:
            errors.append(f"traceability_missing:{family}")

quality = require_file("quality-model.md")
if quality.exists():
    q = quality.read_text(encoding="utf-8")
    qgates = [
        "QCORRECTNESS","QRESILIENCE","QSECURITY","QSUPPLYCHAIN","QDATA","QPERF","QEVAL",
        "QRECOVERY","QCOMPAT","QGOVERNANCE","QCONTENTSTATE","QRIGHTS","QOPERABILITY",
        "QPORTABILITY","QROOTTRUST","QEXECUTIONMODEL","QLIVENESS","QSEMANTICS"
    ]
    for gate in qgates:
        if gate not in q:
            errors.append(f"quality_gate_missing:{gate}")

# Critical gate graph: IDs, dependencies, acyclicity and reachability.
graph_path=require_file("gate-graph.json")
if graph_path.exists():
    graph=json.loads(graph_path.read_text(encoding="utf-8"))
    nodes=graph.get("gates",[])
    ids=[n.get("id") for n in nodes]
    if None in ids or len(ids) != len(set(ids)):
        errors.append("gate_graph:ids_invalid_or_duplicate")
    idset=set(ids)
    deps={}
    for n in nodes:
        nid=n.get("id")
        ndeps=n.get("depends_on",[])
        deps[nid]=ndeps
        unknown=set(ndeps)-idset
        if unknown:
            errors.append(f"gate_graph:unknown_dependency:{nid}:{','.join(sorted(unknown))}")
    visiting=set()
    visited=set()
    def dfs(n):
        if n in visiting:
            errors.append(f"gate_graph:cycle:{n}")
            return
        if n in visited:
            return
        visiting.add(n)
        for d in deps.get(n,[]):
            dfs(d)
        visiting.remove(n)
        visited.add(n)
    for n in ids:
        dfs(n)
    reverse=defaultdict(list)
    for n, ds in deps.items():
        for d in ds:
            reverse[d].append(n)
    roots=graph.get("roots",[])
    for r in roots:
        if r not in idset:
            errors.append(f"gate_graph:unknown_root:{r}")
    reachable=set()
    queue=deque([r for r in roots if r in idset])
    while queue:
        n=queue.popleft()
        if n in reachable:
            continue
        reachable.add(n)
        queue.extend(reverse.get(n,[]))
    for terminal in graph.get("terminal_states",[]):
        if terminal not in idset:
            errors.append(f"gate_graph:unknown_terminal:{terminal}")
        elif terminal not in reachable:
            errors.append(f"gate_graph:terminal_unreachable:{terminal}")
    bt=graph.get("bootstrap_transitions",[])
    tids=[x.get("id") for x in bt]
    if None in tids or len(tids)!=len(set(tids)):
        errors.append("gate_graph:bootstrap_transition_ids_invalid_or_duplicate")

profiles_path=require_file("supported-runtime-profiles.json")
if profiles_path.exists():
    profiles=json.loads(profiles_path.read_text(encoding="utf-8"))
    plist=profiles.get("profiles",[])
    pids=[p.get("id") for p in plist]
    if not plist or None in pids or len(pids)!=len(set(pids)):
        errors.append("runtime_profiles:missing_or_duplicate_ids")
    if profiles.get("strategy",{}).get("unknown") != "unsupported until certified":
        errors.append("runtime_profiles:unknown_must_fail_closed")

critical=require_file("critical-kernel.md")
if critical.exists():
    txt=critical.read_text(encoding="utf-8")
    for phrase in ["Architecture Freeze","Recovery Plane","Bit Flows","vertical-slice"]:
        if phrase not in txt:
            errors.append(f"critical_kernel:missing:{phrase}")

for rel in ["spec.md","plan.md","coverage-audit.md"]:
    p=require_file(rel)
    if p.exists() and "Production" not in p.read_text(encoding="utf-8"):
        errors.append(f"production_boundary_not_documented:{rel}")

if errors:
    print("FEATURE_007_SPEC_VALIDATION: FAIL")
    for e in errors:
        print(" -", e)
    sys.exit(1)

print("FEATURE_007_SPEC_VALIDATION: PASS")
print(f"required_files={len(required)}")
print("required_phases=36")
print("gate_graph=acyclic_and_terminal_reachable")
print("architecture_freeze=true")
print("merge_authorized=false")
print("production_activation_authorized=false")
