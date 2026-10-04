from __future__ import annotations
import json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
src=(root/"includes/class-mad4b-scp-production-certification.php").read_text(encoding="utf-8")
main=(root/"mad4b-site-control-plane.php").read_text(encoding="utf-8")
servers=(root/"includes/class-mad4b-scp-servers.php").read_text(encoding="utf-8")
plan=json.loads((root/"config/production-certification-plan.json").read_text(encoding="utf-8"))

required=[
    "mad4b.production-certification-runtime.v1",
    "mad4b/production-certification-readonly-evidence",
    "mad4b.production-live-gate-evidence.v1",
    "mad4b.multi-authority-live-canary.v1",
    "mad4b.production-policy-probe.v1",
    "MAD4B_SCP_MCP_Peer_Governance::status()",
    "MAD4B_SCP_Multi_Authority_Registry::snapshot()",
    "MAD4B_SCP_Identity_Context::current()",
    "MAD4B_SCP_Policy_Resolution::resolve",
    "MAD4B_SCP_Operator_Doctor::doctor",
    "'mutation_performed' => false",
    "'production_mutation' => false",
    "'authorizing' => false",
]
for token in required:
    if token not in src:
        raise SystemExit("PRODUCTION_CERTIFICATION_RUNTIME_MARKER_MISSING:"+token)
if "class-mad4b-scp-production-certification.php" not in main:
    raise SystemExit("PRODUCTION_CERTIFICATION_RUNTIME_NOT_LOADED")
read_start=servers.index("'mad4b-read' =>")
chatgpt_start=servers.index("'mad4b-chatgpt' =>")
enrollment_start=servers.index("'mad4b-enrollment' =>")
read_block=servers[read_start:chatgpt_start]
chatgpt_block=servers[chatgpt_start:enrollment_start]
ability="'mad4b/production-certification-readonly-evidence'"
if read_block.count(ability) != 1:
    raise SystemExit("PRODUCTION_CERTIFICATION_READ_SURFACE_BINDING_INVALID")
if chatgpt_block.count(ability) != 1:
    raise SystemExit("PRODUCTION_CERTIFICATION_CHATGPT_SURFACE_BINDING_INVALID")

expected={
    "provider_side_channel_inventory":(
        "mad4b/production-certification-readonly-evidence#mcp-peer-governance",
        "mad4b.mcp-peer-governance.v2",
    ),
    "multi_authority_canary":(
        "mad4b/production-certification-readonly-evidence#multi-authority",
        "mad4b.multi-authority-live-canary.v1",
    ),
    "policy_resolution_canary":(
        "mad4b/production-certification-readonly-evidence#policy-probe",
        "mad4b.production-policy-probe.v1",
    ),
    "operator_doctor":(
        "mad4b/production-certification-readonly-evidence#operator-doctor",
        "mad4b.operator-doctor.v1",
    ),
}
by_id={str(x.get("id") or ""):x for x in plan.get("stages") or []}
for sid,(producer,contract_name) in expected.items():
    row=by_id.get(sid)
    if not row:
        raise SystemExit("PRODUCTION_CERTIFICATION_STAGE_MISSING:"+sid)
    if row.get("producer")!=producer or row.get("evidence_contract")!=contract_name:
        raise SystemExit("PRODUCTION_CERTIFICATION_STAGE_PRODUCER_DRIFT:"+sid)
    if row.get("mutation_class")!="read_only" or row.get("rollback_required") is not False:
        raise SystemExit("PRODUCTION_CERTIFICATION_READONLY_SCOPE_DRIFT:"+sid)

print("mad4b.production-certification-runtime.contract.v1: PASS")
