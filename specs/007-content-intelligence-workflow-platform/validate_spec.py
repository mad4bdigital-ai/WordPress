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
    "implementation-closure.md","implementation-closure.json",
    "task-ledger-overrides.json","task-ledger.generated.json","reconcile_task_ledger.py",
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
    "contracts/host-connector.md","contracts/governed-tool-execution.md","contracts/cli-host-runner.md",
    "contracts/cron-and-growth.md",
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
    "contracts/architecture-freeze.md","contracts/capability-fabric-post-merge-completeness.md",
    "references/host-provider-validation-profile.md",
    "contracts/bulk-runtime-closure-hardening.md","bulk-closure-hardening.json",
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
        "required_phase_count": 38,
    }
    for k, v in expected.items():
        if data.get(k) != v:
            errors.append(f"feature_field:{k}:expected={v!r}:got={data.get(k)!r}")
    capfab_expected = {
        "post_merge_capability_fabric_closure_ledger": "post-merge-capability-fabric-closure.json",
        "post_merge_capability_fabric_contract": "mad4b.capability-fabric-post-merge-completeness.v1",
        "post_merge_capability_fabric_contract_document": "contracts/capability-fabric-post-merge-completeness.md",
        "post_merge_capability_fabric_completeness_claim": "NOT_YET_PROVEN",
    }
    for k, v in capfab_expected.items():
        if data.get(k) != v:
            errors.append(f"feature_capability_fabric_field:{k}:expected={v!r}:got={data.get(k)!r}")
    for key in ["baseline_head_at_creation","last_reviewed_master_parent_sha"]:
        val=data.get(key)
        if not isinstance(val,str) or not re.fullmatch(r"[0-9a-f]{40}", val):
            errors.append(f"feature_sha_invalid:{key}:{val!r}")
    if data.get("baseline_model") != "mad4b.feature007-reviewed-parent-and-runtime-release.v1":
        errors.append("feature_baseline_model_mismatch")
    release=data.get("runtime_release_identity")
    if not isinstance(release,dict):
        errors.append("feature_runtime_release_identity_missing")
    else:
        for key in ["source_commit_sha","build_fingerprint","package_manifest_digest","control_plane_archive_sha256"]:
            val=release.get(key)
            size=40 if key=="source_commit_sha" else 64
            if not isinstance(val,str) or not re.fullmatch(rf"[0-9a-f]{{{size}}}",val):
                errors.append(f"runtime_release_identity_invalid:{key}:{val!r}")
        if release.get("state") not in {"CURRENT_FOR_DEPLOYMENT","RECAPTURE_REQUIRED"}:
            errors.append("runtime_release_identity_invalid_state")
    branch_policy=data.get("implementation_branch_policy")
    expected_branch_policy={
        "contract":"mad4b.feature-implementation-branch-policy.v2",
        "implementation_prefixes":["feat/007-","fix/007-"],
        "specification_maintenance_prefixes":["spec/007-"],
        "requires_exact_pr_base_ancestry":True,
        "mutable_metadata_cannot_widen_policy":True,
    }
    if branch_policy != expected_branch_policy:
        errors.append("implementation_branch_policy_mismatch")
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
        "architecture_freeze_critical_kernel",
        "governed_tool_operation_registry","cli_mcp_shared_service_parity",
        "host_executor_authority_separation","host_runner_durable_execution","host_runner_bootstrap_enrollment",
        "recovery_runner_wordpress_independence","no_generic_shell_surface",
        "tool_path_network_secret_governance","tool_execution_receipt_evidence",
        "host_provider_channel_certification","cross_executor_semantic_conformance",
        "wordpress_host_bridge_exact_plan_enqueue","host_runner_execution_location_truthfulness",
        "tool_executor_supply_chain_provenance","host_execution_kill_switches",
        "runner_offline_authorization_revalidation","host_runner_fencing",
        "recovery_runner_independent_root_trust","tool_executor_runtime_profile_compatibility",
        "repository_governance_external_enforcement","execution_ledger_reconciliation",
        "protected_backup_recovery_readiness","bitflows_1_29_exact_runtime_certification",
        "repository_governance_bootstrap_retirement","etg_exact_runtime_release_deployment","runtime_root_trust_readback",
        "unified_implementation_closure","critical_kernel_vertical_slice_verified"
    }
    missing = mandatory_gates.difference(gates)
    if missing:
        errors.append("missing_gates:" + ",".join(sorted(missing)))

closure_path = require_file("implementation-closure.json")
if closure_path.exists() and feature_path.exists():
    closure = json.loads(closure_path.read_text(encoding="utf-8"))
    if closure.get("contract") != "mad4b.feature007-implementation-closure.v2":
        errors.append("closure:contract_mismatch")
    if closure.get("baseline_branch") != "master":
        errors.append("closure:baseline_branch_mismatch")
    repo_base=closure.get("repository_baseline")
    if not isinstance(repo_base,dict) or repo_base.get("last_reviewed_parent_sha") != data.get("last_reviewed_master_parent_sha"):
        errors.append("closure:repository_baseline_mismatch")
    runtime_release=closure.get("runtime_release_identity")
    if not isinstance(runtime_release,dict) or runtime_release.get("source_commit_sha") != (data.get("runtime_release_identity") or {}).get("source_commit_sha"):
        errors.append("closure:runtime_release_identity_mismatch")
    if closure.get("terminal_gate") != "critical_kernel_vertical_slice_verified":
        errors.append("closure:terminal_gate_mismatch")
    if closure.get("production_authorized") is not False:
        errors.append("closure:production_must_remain_false")
    streams = closure.get("workstreams", [])
    stream_ids = [row.get("id") for row in streams if isinstance(row, dict)]
    if len(stream_ids) != len(set(stream_ids)) or any(not x for x in stream_ids):
        errors.append("closure:workstream_ids_invalid")
    required_streams = {
        "repository_governance","repository_governance_bootstrap_retirement","execution_ledger_reconciliation","latest_runtime_release_root_trust",
        "governed_tool_execution","protected_backup_recovery","etg_exact_runtime_release_deployment","runtime_root_trust_readback","recovery_live_drill","bitflows_1_29_exact_certification",
        "provider_side_channel","capability_traits","site_bootstrap","intent_registry",
        "content_job_domain","artifact_registry_store","context_writer","research_competitive",
        "blueprint_draft_qa","governed_wp_draft","semantic_publication_verification",
        "authoritative_consistency_fencing","security_ai_eval_faults","operator_doctor_dlq",
        "formal_state_proof","etg_vertical_slice"
    }
    missing_streams = required_streams.difference(stream_ids)
    if missing_streams:
        errors.append("closure:missing_workstreams:" + ",".join(sorted(missing_streams)))
    allowed_status=set(closure.get("status_vocabulary", []))
    allowed_priority=set(closure.get("priority_classes", []))
    for row in streams:
        if not isinstance(row, dict):
            errors.append("closure:workstream_not_object")
            continue
        if row.get("status") not in allowed_status:
            errors.append(f"closure:invalid_status:{row.get('id')}:{row.get('status')}")
        if row.get("priority") not in allowed_priority:
            errors.append(f"closure:invalid_priority:{row.get('id')}:{row.get('priority')}")
        if row.get("priority") in {"KERNEL_BLOCKER","LIVE_PRECONDITION"} and not row.get("gate"):
            errors.append(f"closure:missing_gate:{row.get('id')}")
        if row.get("status") == "DONE" and not row.get("evidence"):
            errors.append(f"closure:done_without_evidence:{row.get('id')}")
        if row.get("status") == "PARTIAL":
            if not row.get("evidence"):
                errors.append(f"closure:partial_without_evidence:{row.get('id')}")
            remainder = row.get("remainder")
            if not isinstance(remainder, list) or not remainder:
                errors.append(f"closure:partial_without_remainder:{row.get('id')}")

if closure_path.exists():
    observed = closure.get("observed_live_etg_state")
    if observed is not None:
        if not isinstance(observed, dict):
            errors.append("closure:observed_live_state_not_object")
        else:
            semantics = observed.get("observation_semantics")
            if not isinstance(semantics, dict):
                errors.append("closure:observed_live_state_semantics_missing")
            else:
                if semantics.get("authoritative_for_gate") is not False:
                    errors.append("closure:observed_live_state_must_be_non_authoritative")
                if semantics.get("must_refresh_before_gate_decision") is not True:
                    errors.append("closure:observed_live_state_refresh_required")
                if semantics.get("unknown_or_stale_behavior") != "UNKNOWN":
                    errors.append("closure:observed_live_state_unknown_behavior")
                if semantics.get("authoritative_for_gate") is True:
                    if not semantics.get("observed_at") or not semantics.get("evidence_refs"):
                        errors.append("closure:authoritative_live_state_requires_time_and_evidence")

closure_md_path = require_file("implementation-closure.md")
if closure_md_path.exists() and feature_path.exists():
    closure_md = closure_md_path.read_text(encoding="utf-8")
    reviewed_parent = data.get("last_reviewed_master_parent_sha")
    runtime_source = (data.get("runtime_release_identity") or {}).get("source_commit_sha")
    if isinstance(reviewed_parent, str) and reviewed_parent not in closure_md:
        errors.append("closure:markdown_reviewed_parent_mismatch")
    if isinstance(runtime_source, str) and runtime_source not in closure_md:
        errors.append("closure:markdown_runtime_release_mismatch")

ledger_path = require_file("task-ledger.generated.json")
overrides_path = require_file("task-ledger-overrides.json")
if ledger_path.exists() and overrides_path.exists():
    ledger=json.loads(ledger_path.read_text(encoding="utf-8"))
    if ledger.get("contract") != "mad4b.feature007-task-ledger.v1":
        errors.append("task_ledger:contract_mismatch")
    rows=ledger.get("tasks",[])
    if ledger.get("task_count") != len(rows):
        errors.append("task_ledger:count_mismatch")
    ids=[row.get("task_id") for row in rows if isinstance(row,dict)]
    if len(ids)!=len(set(ids)) or any(not x for x in ids):
        errors.append("task_ledger:ids_invalid")
    allowed={"DONE","PARTIAL","OPEN","DEFERRED"}
    for row in rows:
        if row.get("status") not in allowed:
            errors.append(f"task_ledger:invalid_status:{row.get('task_id')}")
        if row.get("status") in {"DONE","PARTIAL","DEFERRED"} and not row.get("evidence_refs"):
            errors.append(f"task_ledger:non_open_without_evidence:{row.get('task_id')}")
    overrides=json.loads(overrides_path.read_text(encoding="utf-8")).get("overrides",{})
    non_open={row.get("task_id") for row in rows if row.get("status")!="OPEN"}
    if non_open != set(overrides):
        errors.append("task_ledger:override_non_open_mismatch")

tasks_path = require_file("tasks.md")
if tasks_path.exists():
    txt = tasks_path.read_text(encoding="utf-8")
    ids = re.findall(r"\bT(\d{4})\b", txt)
    dupes = sorted({x for x in ids if ids.count(x) > 1})
    if dupes:
        errors.append("duplicate_task_ids:" + ",".join(dupes))
    for phase in range(0, 38):
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
        "FORMAL","FREEZE","TOOL","CLI","RUNNER","HOSTPROF","REPOGOV","BACKUP","CLOSURE","CAPFAB"
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
    if graph.get("contract") != "mad4b.feature007-critical-gate-graph.v4":
        errors.append("gate_graph:contract_mismatch")
    nodes=graph.get("gates",[])
    ids=[n.get("id") for n in nodes]
    if None in ids or len(ids) != len(set(ids)):
        errors.append("gate_graph:ids_invalid_or_duplicate")
    idset=set(ids)
    if closure_path.exists():
        for row in closure.get("workstreams", []):
            if isinstance(row, dict) and row.get("gate") and row.get("gate") not in idset:
                errors.append(f"closure:unknown_gate:{row.get('id')}:{row.get('gate')}")
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
    terminals=set(graph.get("terminal_states",[]))
    for terminal in terminals:
        if terminal not in idset:
            errors.append(f"gate_graph:unknown_terminal:{terminal}")
        elif terminal not in reachable:
            errors.append(f"gate_graph:terminal_unreachable:{terminal}")

    # Every blocking/live-precondition gate must itself have a forward closure path
    # to a terminal state. Root-to-terminal reachability alone can hide orphan blockers.
    if closure_path.exists():
        def reaches_terminal(start):
            q=deque([start])
            seen=set()
            while q:
                current=q.popleft()
                if current in terminals:
                    return True
                if current in seen:
                    continue
                seen.add(current)
                q.extend(reverse.get(current,[]))
            return False
        for row in closure.get("workstreams",[]):
            if not isinstance(row,dict):
                continue
            if row.get("priority") not in {"KERNEL_BLOCKER","LIVE_PRECONDITION"}:
                continue
            gate=row.get("gate")
            if gate in idset and not reaches_terminal(gate):
                errors.append(f"gate_graph:blocker_cannot_reach_terminal:{row.get('id')}:{gate}")

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

bulk_hardening_path=require_file("bulk-closure-hardening.json")
if bulk_hardening_path.exists():
    hardening=json.loads(bulk_hardening_path.read_text(encoding="utf-8"))
    if hardening.get("contract") != "mad4b.feature007-bulk-closure-hardening.v1":
        errors.append("bulk_hardening:contract_mismatch")
    if hardening.get("production_authorized") is not False:
        errors.append("bulk_hardening:production_must_remain_false")
    if hardening.get("architecture_freeze") is not True:
        errors.append("bulk_hardening:architecture_freeze_required")
    if hardening.get("terminal_gate") != "critical_kernel_vertical_slice_verified":
        errors.append("bulk_hardening:terminal_gate_mismatch")
    domains=hardening.get("required_domains",[])
    fixtures=hardening.get("required_fault_fixtures",[])
    if len(domains) < 13 or len(domains) != len(set(domains)):
        errors.append("bulk_hardening:required_domains_incomplete_or_duplicate")
    if len(fixtures) < 16 or len(fixtures) != len(set(fixtures)):
        errors.append("bulk_hardening:fault_fixtures_incomplete_or_duplicate")
    if hardening.get("mutation_uncertainty_state") != "MUTATED_BUT_EVIDENCE_UNCERTAIN":
        errors.append("bulk_hardening:mutation_uncertainty_state_missing")
    rules=hardening.get("closure_rules",{})
    for key in [
        "backup_exists_is_not_ready","runtime_evidence_required_for_live_gate",
        "docs_only_cannot_close_runtime_gate","ordinary_generic_shell_prohibited",
        "raw_sql_breakglass_separate","production_authority_not_implied",
    ]:
        if rules.get(key) is not True:
            errors.append(f"bulk_hardening:closure_rule_missing:{key}")

    evidence_model=hardening.get("evidence_model",{})
    if evidence_model.get("contract") != "mad4b.feature007-bulk-fixture-evidence.v1":
        errors.append("bulk_hardening:evidence_model_contract_mismatch")
    repo_allowed=set(evidence_model.get("repository_status_vocabulary",[]))
    live_allowed=set(evidence_model.get("live_status_vocabulary",[]))
    fixture_evidence=hardening.get("fixture_evidence",{})
    if set(fixture_evidence) != set(fixtures):
        errors.append("bulk_hardening:fixture_evidence_key_mismatch")
    for fixture in fixtures:
        row=fixture_evidence.get(fixture,{})
        repo_status=row.get("repository_status")
        live_status=row.get("live_status")
        refs=row.get("evidence_refs")
        remainder=row.get("remaining",[])
        if repo_status not in repo_allowed:
            errors.append(f"bulk_hardening:fixture_repository_status_invalid:{fixture}:{repo_status}")
        if live_status not in live_allowed:
            errors.append(f"bulk_hardening:fixture_live_status_invalid:{fixture}:{live_status}")
        if not isinstance(refs,list) or not refs or any(not isinstance(x,str) or not x.strip() for x in refs):
            errors.append(f"bulk_hardening:fixture_evidence_refs_missing:{fixture}")
        if repo_status in {"PARTIAL","PENDING"} and (not isinstance(remainder,list) or not remainder):
            errors.append(f"bulk_hardening:fixture_remainder_missing:{fixture}")
        if live_status == "PROVEN":
            # Live proof must carry at least one explicit non-repository evidence
            # reference; repository paths alone can never self-close a live gate.
            if all(str(x).startswith(("specs/",".github/","tools/","wp-content/")) for x in refs):
                errors.append(f"bulk_hardening:live_proven_without_external_evidence:{fixture}")

audit_path=require_file("post-merge-capability-fabric-audit.md")
if audit_path.exists():
    audit_txt=audit_path.read_text(encoding="utf-8")
    for phrase in [
        "Canonical capability semantics","Provider postcondition reconciliation","Resource constraint compiler",
        "Durable multisite/network orchestration","Dedicated immutable catalog table","Distributed tracing",
        "Impact-bound approval","Unified execution receipt","Authorization decision graph","Semantic intent routing",
        "Cryptographic agility","Clock skew","Unicode canonicalization","Rate limiting and complexity budgets",
        "Maintainability and change architecture","CAPABILITY_FABRIC_NO_AUTHORITY_WIDENING",
    ]:
        if phrase not in audit_txt:
            errors.append(f"capability_fabric_audit:missing:{phrase}")
    for task_id in ["T3701","T3712","T3719","T3725","T3736","T3741","T3747","T3750","T3755","T3763","T3768","T3770"]:
        if task_id not in audit_txt:
            errors.append(f"capability_fabric_audit:task_missing:{task_id}")

capfab_contract_path=require_file("contracts/capability-fabric-post-merge-completeness.md")
if capfab_contract_path.exists():
    contract_txt=capfab_contract_path.read_text(encoding="utf-8")
    for phrase in ["mad4b.capability-fabric-post-merge-completeness.v1","Every Phase 37 task belongs to exactly one closure workstream","Production activation","Restore/time-travel protection","T3795","T3799"]:
        if phrase not in contract_txt:
            errors.append(f"capability_fabric_contract:missing:{phrase}")

capfab_path=require_file("post-merge-capability-fabric-closure.json")
if capfab_path.exists() and tasks_path.exists():
    capfab=json.loads(capfab_path.read_text(encoding="utf-8"))
    if capfab.get("contract") != "mad4b.capability-fabric-post-merge-closure.v1": errors.append("capability_fabric_closure:contract_mismatch")
    if capfab.get("phase") != 37: errors.append("capability_fabric_closure:phase_mismatch")
    for key in ["production_authorized","breakglass_widened","generic_shell_authorized","generic_raw_sql_authorized"]:
        if capfab.get(key) is not False: errors.append(f"capability_fabric_closure:must_remain_false:{key}")
    if capfab.get("critical_kernel_terminal_gate_unchanged") is not True or capfab.get("critical_kernel_terminal_gate") != "critical_kernel_vertical_slice_verified": errors.append("capability_fabric_closure:critical_kernel_gate_changed")
    if capfab.get("completeness_claim") != "NOT_YET_PROVEN": errors.append("capability_fabric_closure:premature_completeness_claim")
    phase37_ids=set(re.findall(r"^- \[[ x]\] (T37\d{2}) P\d ", task_txt, flags=re.MULTILINE))
    rows=capfab.get("workstreams",[])
    workstream_ids=[r.get("id") for r in rows if isinstance(r,dict)]
    if not rows or None in workstream_ids or len(workstream_ids) != len(set(workstream_ids)): errors.append("capability_fabric_closure:workstream_ids_invalid")
    mapped=[]
    task_priorities={m.group(1):m.group(2) for m in re.finditer(r"^- \\[\[ x\]\\] (T37\\d{2}) (P[0-2])\\b", task_txt, flags=re.MULTILINE)}
    expected_status={"OPEN","PARTIAL","DONE","DEFERRED"}
    allowed_status=set(capfab.get("status_vocabulary",[]))
    if allowed_status != expected_status:
        errors.append("capability_fabric_closure:status_vocabulary_mismatch")
    expected_quality={
        "QCORRECTNESS","QRESILIENCE","QSECURITY","QSUPPLYCHAIN","QDATA","QPERF","QEVAL","QRECOVERY",
        "QCOMPAT","QGOVERNANCE","QCONTENTSTATE","QRIGHTS","QOPERABILITY","QPORTABILITY","QROOTTRUST",
        "QEXECUTIONMODEL","QLIVENESS","QSEMANTICS","QTEST"
    }
    if set(capfab.get("quality_family_vocabulary",[])) != expected_quality:
        errors.append("capability_fabric_closure:quality_vocabulary_mismatch")
    required_terminal={"ownership_closure","cancel_transport_cross_fault","canonical_db_storage"}
    if set(capfab.get("completion_requires_workstreams",[])) != required_terminal:
        errors.append("capability_fabric_closure:completion_workstreams_mismatch")
    if capfab.get("dimension_ownership_contract") != "mad4b.capability-fabric-dimension-ownership.v1":
        errors.append("capability_fabric_closure:dimension_ownership_contract_missing")
    if capfab.get("open_priority_policy") != "P0_P1_REQUIRE_EXPLICIT_DEPENDENCIES_FAIL_CLOSED_INTERIM_AND_EVIDENCE_PATH":
        errors.append("capability_fabric_closure:open_priority_policy_missing")
    if capfab.get("traceability_family") != "CAPFAB":
        errors.append("capability_fabric_closure:traceability_family_missing")
    traceability_path=require_file("traceability.md")
    if traceability_path.exists() and "| CAPFAB |" not in traceability_path.read_text(encoding="utf-8"):
        errors.append("capability_fabric_closure:traceability_capfab_mapping_missing")
    rules=capfab.get("closure_rules",{})
    for key in ["exact_phase37_task_coverage","unique_task_ownership","dependency_graph_acyclic","unknown_quality_family_forbidden","docs_only_cannot_close_runtime_or_live_work","non_authorizing"]:
        if rules.get(key) is not True:
            errors.append(f"capability_fabric_closure:closure_rule_missing:{key}")
    for row in rows:
        if not isinstance(row,dict):
            errors.append("capability_fabric_closure:workstream_not_object"); continue
        tids=row.get("task_ids",[])
        if not isinstance(tids,list) or not tids:
            errors.append(f"capability_fabric_closure:task_ids_missing:{row.get('id')}"); continue
        mapped.extend(tids)
        if row.get("status") not in allowed_status: errors.append(f"capability_fabric_closure:invalid_status:{row.get('id')}:{row.get('status')}")
        priorities=[task_priorities.get(t) for t in tids if task_priorities.get(t)]
        expected_priority=sorted(priorities,key=lambda p:int(p[1:]))[0] if priorities else None
        if row.get("priority") not in {"P0","P1","P2"}:
            errors.append(f"capability_fabric_closure:priority_missing_or_invalid:{row.get('id')}")
        elif expected_priority and row.get("priority") != expected_priority:
            errors.append(f"capability_fabric_closure:priority_drift:{row.get('id')}:{row.get('priority')}:{expected_priority}")
        for field in ["owner","contract_or_non_goal","test_strategy","traceability_family"]:
            if not isinstance(row.get(field),str) or not row.get(field).strip():
                errors.append(f"capability_fabric_closure:{field}_missing:{row.get('id')}")
        if row.get("traceability_family") != "CAPFAB":
            errors.append(f"capability_fabric_closure:traceability_family_invalid:{row.get('id')}")
        if row.get("authorizing") is not False:
            errors.append(f"capability_fabric_closure:workstream_must_be_non_authorizing:{row.get('id')}")
        sources=row.get("evidence_sources",[])
        if not isinstance(sources,list) or not sources or any(not isinstance(x,str) or not x.strip() for x in sources):
            errors.append(f"capability_fabric_closure:evidence_sources_missing:{row.get('id')}")
        if row.get("status") in {"OPEN","PARTIAL"} and row.get("priority") in {"P0","P1"}:
            if not isinstance(row.get("dependencies",[]),list):
                errors.append(f"capability_fabric_closure:open_priority_dependencies_invalid:{row.get('id')}")
            if not isinstance(row.get("interim_behavior"),str) or not row.get("interim_behavior").strip():
                errors.append(f"capability_fabric_closure:open_priority_interim_missing:{row.get('id')}")
            if not sources:
                errors.append(f"capability_fabric_closure:open_priority_evidence_path_missing:{row.get('id')}")
        if not isinstance(row.get("quality_families",[]),list) or not row.get("quality_families"):
            errors.append(f"capability_fabric_closure:quality_family_missing:{row.get('id')}")
        else:
            unknown_quality=set(row.get("quality_families",[]))-expected_quality
            if unknown_quality:
                errors.append(f"capability_fabric_closure:unknown_quality_family:{row.get('id')}:{','.join(sorted(unknown_quality))}")
        if row.get("status") in {"DONE","PARTIAL"}:
            refs=row.get("evidence_refs",[])
            if not isinstance(refs,list) or not refs:
                errors.append(f"capability_fabric_closure:closed_or_partial_without_evidence:{row.get('id')}")
        for field in ["interim_behavior","evidence_strategy"]:
            if not isinstance(row.get(field),str) or not row.get(field).strip(): errors.append(f"capability_fabric_closure:{field}_missing:{row.get('id')}")
        if not isinstance(row.get("dependencies",[]),list): errors.append(f"capability_fabric_closure:dependencies_invalid:{row.get('id')}")
    if len(mapped) != len(set(mapped)): errors.append("capability_fabric_closure:task_mapped_more_than_once")
    if set(mapped) != phase37_ids: errors.append("capability_fabric_closure:phase37_task_coverage_mismatch:" + ",".join(sorted(set(mapped) ^ phase37_ids)))
    known_workstreams=set(workstream_ids)
    dep_graph={}
    for row in rows:
        if isinstance(row,dict):
            deps=set(row.get("dependencies",[]))
            dep_graph[row.get("id")]=deps
            unknown=deps-known_workstreams
            if unknown: errors.append(f"capability_fabric_closure:unknown_dependency:{row.get('id')}:{','.join(sorted(unknown))}")
    visiting=set()
    visited=set()
    def capfab_dfs(node):
        if node in visiting:
            errors.append(f"capability_fabric_closure:dependency_cycle:{node}")
            return
        if node in visited: return
        visiting.add(node)
        for dep in dep_graph.get(node,set()):
            capfab_dfs(dep)
        visiting.remove(node)
        visited.add(node)
    for node in known_workstreams:
        capfab_dfs(node)
    if closure_path.exists() and capfab.get("baseline_merge_commit") != (closure.get("repository_baseline") or {}).get("last_reviewed_parent_sha"):
        errors.append("capability_fabric_closure:baseline_mismatch")

quality_path=require_file("quality-model.md")
if quality_path.exists() and "Post-merge Capability Fabric completeness overlay" not in quality_path.read_text(encoding="utf-8"): errors.append("capability_fabric_closure:quality_overlay_missing")
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
print(f"required_phases={data.get('required_phase_count') if feature_path.exists() else 'unknown'}")
print("gate_graph=acyclic_terminal_reachable_and_all_blockers_closable")
print("architecture_freeze=true")
print("merge_authorized=false")
print("production_activation_authorized=false")
