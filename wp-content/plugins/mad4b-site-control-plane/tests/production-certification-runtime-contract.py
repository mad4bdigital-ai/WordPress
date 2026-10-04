from __future__ import annotations
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
src=(root/"includes/class-mad4b-scp-production-certification.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
servers=(root/"includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
evaluator=(root/"includes/class-mad4b-scp-production-readiness-evaluator.php").read_text(encoding="utf-8")
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))

for token in ("mad4b.production-certification-runtime.v1","mad4b/production-certification-readonly-evidence","mad4b.production-live-gate-evidence.v1","MAD4B_SCP_MCP_Peer_Governance::status()","MAD4B_SCP_Multi_Authority_Registry::snapshot()","MAD4B_SCP_Identity_Context::current()","MAD4B_SCP_Policy_Resolution::resolve","MAD4B_SCP_Operator_Doctor::doctor","'mutation_performed' => false","'production_mutation' => false","'authorizing' => false"):
    if token not in src: raise SystemExit("PRODUCTION_CERTIFICATION_RUNTIME_MARKER_MISSING:"+token)
if "class-mad4b-scp-production-certification.php" not in main or "MAD4B_SCP_Production_Certification::boot();" not in main: raise SystemExit("PRODUCTION_CERTIFICATION_RUNTIME_NOT_BOOTED")
if "class-mad4b-scp-production-readiness-evaluator.php" not in main or "MAD4B_SCP_Production_Readiness_Evaluator::boot();" not in main: raise SystemExit("PRODUCTION_READINESS_EVALUATOR_NOT_BOOTED")

for marker in ("mad4b.production-readiness-evaluator.v1","mad4b/production-readiness-evaluate","mad4b.production-evidence-trust.v1","mad4b.production-evidence-attestation.v1","production_evidence","runtime_recompute","signed_attestation","evidence_trust_verify","evidence_trust_runtime_recompute","evidence_trust_signed_attestation","seal_verified_evidence","verify_digest_for_purpose","sign_digest_for_purpose","exact_producer_contract_mismatch","outer_evidence_flag_mismatch","evidence_attestation_stale","'production_authorized' => false","'promotion_required' => true","'production_mutation' => false"):
    if marker not in evaluator: raise SystemExit("PRODUCTION_READINESS_EVIDENCE_TRUST_MARKER_MISSING:"+marker)

read_start=servers.index("'mad4b-read' =>"); chatgpt_start=servers.index("'mad4b-chatgpt' =>"); enrollment_start=servers.index("'mad4b-enrollment' =>")
read_block=servers[read_start:chatgpt_start]; chatgpt_block=servers[chatgpt_start:enrollment_start]
ability="'mad4b/production-certification-readonly-evidence'"; status_ability="'mad4b/production-certification-status'"; verdict="'mad4b/production-readiness-evaluate'"
if read_block.count(ability)!=1 or chatgpt_block.count(ability)!=1: raise SystemExit("PRODUCTION_CERTIFICATION_SURFACE_BINDING_INVALID")
if read_block.count(verdict)!=1 or chatgpt_block.count(verdict)!=1: raise SystemExit("PRODUCTION_READINESS_EVALUATOR_SURFACE_BINDING_INVALID")
if read_block.count(status_ability)!=1 or chatgpt_block.count(status_ability)!=1: raise SystemExit("PRODUCTION_CERTIFICATION_STATUS_SURFACE_BINDING_INVALID")

by_id={str(x.get("id") or ""):x for x in plan.get("stages") or []}
local={"provider_side_channel_inventory","multi_authority_canary","policy_resolution_canary","security_fault_canary","operator_doctor"}
for sid,row in by_id.items():
    if not row.get("producer_contract") or not row.get("attestation_method"): raise SystemExit("PRODUCTION_READINESS_STAGE_TRUST_BINDING_MISSING:"+sid)
    if sid in local:
        if row.get("trust_mode")!="runtime_recompute" or row.get("attestation_method")!="runtime_recompute": raise SystemExit("PRODUCTION_READINESS_LOCAL_STAGE_NOT_RECOMPUTED:"+sid)
    elif row.get("trust_mode")!="signed_attestation": raise SystemExit("PRODUCTION_READINESS_EXTERNAL_STAGE_NOT_ATTESTED:"+sid)

if by_id.get("rollback_retention",{}).get("attestation_method")!="rollback_retention_verifier": raise SystemExit("PRODUCTION_CERTIFICATION_ROLLBACK_RETENTION_TRUST_INVALID")
if by_id.get("consistency_fault_canary",{}).get("producer_contract")!="mad4b.execution-receipt.v1": raise SystemExit("PRODUCTION_CERTIFICATION_EXECUTION_RECEIPT_BINDING_INVALID")
if by_id.get("formal_state_repository_proof",{}).get("producer_contract")!="mad4b.github-workflow-run-evidence.v1": raise SystemExit("PRODUCTION_CERTIFICATION_REPOSITORY_WORKFLOW_CONTRACT_INVALID")

print("mad4b.production-certification-runtime.contract.v1: PASS")
