from __future__ import annotations
import argparse, json
from pathlib import Path

root=Path(__file__).resolve().parents[1]
repo=root.parents[2]
policy=json.loads((root/"config/production-readiness-policy.json").read_text(encoding="utf-8"))
closure=json.loads((repo/"specs/007-content-intelligence-workflow-platform/implementation-closure.json").read_text(encoding="utf-8"))
capability=json.loads((repo/"specs/007-content-intelligence-workflow-platform/post-merge-capability-fabric-closure.json").read_text(encoding="utf-8"))
manifest=json.loads((repo/"specs/007-content-intelligence-workflow-platform/production-readiness.json").read_text(encoding="utf-8"))
runtime_release=json.loads((root/"config/runtime-release-policy.json").read_text(encoding="utf-8"))

ap=argparse.ArgumentParser()
ap.add_argument("--enforce-ready",action="store_true")
ap.add_argument("--output",default="")
args=ap.parse_args()

def fail(code):
    raise SystemExit(code)

if policy.get("contract")!="mad4b.production-readiness-policy.v1" or policy.get("authorizing") is not False:
    fail("PRODUCTION_READINESS_POLICY_INVALID")
if policy.get("ready_is_not_authorized") is not True or policy.get("production_auto_apply") is not False:
    fail("PRODUCTION_READINESS_AUTHORITY_SEPARATION_INVALID")
if manifest.get("contract")!="mad4b.feature007-production-readiness.v1":
    fail("PRODUCTION_READINESS_MANIFEST_INVALID")
if manifest.get("promotion_semantics",{}).get("production_authorized_by_this_document") is not False:
    fail("PRODUCTION_READINESS_DOCUMENT_MUST_NOT_AUTHORIZE")
if closure.get("production_authorized") is not False:
    fail("IMPLEMENTATION_CLOSURE_PRODUCTION_AUTHORITY_WIDENED")
for key in ("production_authorized","breakglass_widened","generic_shell_authorized","generic_raw_sql_authorized"):
    if capability.get(key) is not False:
        fail("CAPABILITY_CLOSURE_AUTHORITY_WIDENED:"+key)
authority=runtime_release.get("authority") or {}
if authority.get("production_auto_apply") is not False or authority.get("staging_only") is not True:
    fail("RUNTIME_RELEASE_POLICY_PRODUCTION_AUTO_APPLY_WIDENED")

release=(repo/".github/workflows/mad4b-release-verdict.yml").read_text(encoding="utf-8")
etg=(repo/".github/workflows/mad4b-etg-deployment-readiness.yml").read_text(encoding="utf-8")
rollback=(repo/".github/workflows/mad4b-rollback-retention-receipt.yml").read_text(encoding="utf-8")
recovery=(repo/"tools/mad4b_recovery_plane.py").read_text(encoding="utf-8")
for marker in ("Verify exact-head owner attestation","'production_authorized':False","Production and Breakglass/raw-SQL authority are not granted"):
    if marker not in release:
        fail("RELEASE_VERDICT_PRODUCTION_SAFETY_MARKER_MISSING:"+marker)
for marker in ("build-provenance:source_commit_sha_mismatch","runtime_identity_match","'mutation_performed': False"):
    if marker not in etg:
        fail("ETG_READINESS_EXACT_IDENTITY_MARKER_MISSING:"+marker)
for marker in ("mad4b.rollback-retention-receipt.v1","github_actions_artifact_api"):
    if marker not in rollback:
        fail("ROLLBACK_RETENTION_MARKER_MISSING:"+marker)
for marker in ('SUPPORTED_ENVIRONMENTS = {"staging"}',"mad4b.protected-backup-receipt.v1","mad4b.protected-backup-restore-receipt.v1"):
    if marker not in recovery:
        fail("RECOVERY_PLANE_PRODUCTION_BOUNDARY_MISSING:"+marker)

blocking_priorities=set(policy.get("required_blocking_priorities") or [])
workstreams=closure.get("workstreams") or []
if not isinstance(workstreams,list):
    fail("IMPLEMENTATION_CLOSURE_WORKSTREAMS_INVALID")
blockers=[]
gates=set()
for row in workstreams:
    if not isinstance(row,dict):
        continue
    gate=str(row.get("gate") or "")
    if gate:
        gates.add(gate)
    if str(row.get("priority") or "") in blocking_priorities and str(row.get("status") or "")!="DONE":
        blockers.append({
            "id":str(row.get("id") or ""),
            "gate":gate,
            "priority":str(row.get("priority") or ""),
            "status":str(row.get("status") or ""),
        })

for required in policy.get("required_live_evidence") or []:
    gate=str(required.get("gate") or "")
    if not gate or gate not in gates:
        fail("PRODUCTION_READINESS_LIVE_GATE_UNBOUND:"+gate)

report={
    "contract":"mad4b.production-readiness-contract-result.v1",
    "repository_contract_ready":True,
    "live_blocker_count":len(blockers),
    "live_blockers":blockers,
    "production_ready":len(blockers)==0,
    "production_authorized":False,
    "promotion_required":True,
    "authorizing":False,
}
if args.output:
    out=Path(args.output)
    out.parent.mkdir(parents=True,exist_ok=True)
    out.write_text(json.dumps(report,indent=2,sort_keys=True)+"\n",encoding="utf-8")
print(json.dumps(report,sort_keys=True))
if args.enforce_ready and blockers:
    fail("PRODUCTION_READINESS_LIVE_EVIDENCE_INCOMPLETE:"+",".join(x["id"] for x in blockers))
print("mad4b.production-readiness-policy.v1: PASS")
