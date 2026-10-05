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
ap.add_argument("--profile",default="")
ap.add_argument("--enforce-ready",action="store_true")
ap.add_argument("--output",default="")
args=ap.parse_args()

def fail(code):
    raise SystemExit(code)

if policy.get("contract")!="mad4b.production-readiness-policy.v2" or policy.get("authorizing") is not False:
    fail("PRODUCTION_READINESS_POLICY_INVALID")
if policy.get("ready_is_not_authorized") is not True or policy.get("production_auto_apply") is not False:
    fail("PRODUCTION_READINESS_AUTHORITY_SEPARATION_INVALID")
if manifest.get("contract")!="mad4b.feature007-production-readiness.v2":
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
for rel in policy.get("required_fail_closed_repository_evidence") or []:
    if not (repo/rel).is_file():
        fail("PRODUCTION_FAIL_CLOSED_EVIDENCE_MISSING:"+str(rel))

promotion=(root/"includes/class-mad4b-scp-production-promotion-attestation.php").read_text(encoding="utf-8")
readiness_evaluator=(root/"includes/class-mad4b-scp-production-readiness-evaluator.php").read_text(encoding="utf-8")
site_profile=(root/"includes/class-mad4b-scp-site-profile.php").read_text(encoding="utf-8")
write_enable=(root/"includes/class-mad4b-scp-site-profile-write-enablement.php").read_text(encoding="utf-8")
operator_control=(root/"includes/class-mad4b-scp-operator-control-center.php").read_text(encoding="utf-8")
for marker in ("mad4b.production-promotion-attestation.v1","mad4b.production-live-evidence-verdict.v1","mad4b.external-staging-readiness-observer.v1","mad4b.staging-readiness-promotion-proof.v1","production_promotion_staging_readiness","validate_staging_promotion_proof","verify_deployment_binding_proof","site_profile_revision_digest_transition","Production_Unchanged_Attestation::evaluate_receipt","External_Handshake_Evidence::status"):
    if marker not in promotion:
        fail("PRODUCTION_PROMOTION_ATTESTATION_MARKER_MISSING:"+marker)
for marker in ("production_promotion_attestation","MAD4B_SCP_Production_Promotion_Attestation::validate_for_activation","mad4b_production_write_activation_already_enabled","production_readiness_verified"):
    if marker not in write_enable:
        fail("PRODUCTION_ACTIVATION_READINESS_FENCE_MISSING:"+marker)
for marker in ("mad4b.staging-readiness-promotion-proof.v1","production_promotion_staging_readiness","staging_promotion_proof","deployment_binding_proof"):
    if marker not in readiness_evaluator:
        fail("PRODUCTION_STAGING_PROOF_ISSUER_MISSING:"+marker)
for marker in ("deployment_binding_proof","verify_deployment_binding_proof","mad4b-deployment-proof-v1"):
    if marker not in site_profile:
        fail("PRODUCTION_DEPLOYMENT_TRUST_PRIMITIVE_MISSING:"+marker)
for marker in ("'database_recovery' => array(", "'scope' => 'external_provider_disaster_recovery'", "'control_plane_core_blocking' => false", "'host_runner_database_access' => false", "'generic_raw_sql_allowed' => false", "'state' => 'external_provider_certification_required'", "'next_action' => 'certify_external_database_recovery_provider_and_rehearse_restore'"):
    if marker not in operator_control:
        fail("DATABASE_RECOVERY_SCOPE_SEPARATION_MISSING:"+marker)
if policy.get("promotion",{}).get("promotion_attestation_contract")!="mad4b.production-promotion-attestation.v1":
    fail("PRODUCTION_PROMOTION_POLICY_CONTRACT_MISSING")
if policy.get("promotion",{}).get("one_time_binding")!="site_profile_revision_digest_transition":
    fail("PRODUCTION_PROMOTION_ONE_TIME_BINDING_INVALID")

workstreams=closure.get("workstreams") or []
if not isinstance(workstreams,list):
    fail("IMPLEMENTATION_CLOSURE_WORKSTREAMS_INVALID")
by_id={str(row.get("id") or ""):row for row in workstreams if isinstance(row,dict) and row.get("id")}
gates={str(row.get("gate") or "") for row in workstreams if isinstance(row,dict) and row.get("gate")}
for required in policy.get("required_live_evidence") or []:
    gate=str(required.get("gate") or "")
    if not gate or gate not in gates:
        fail("PRODUCTION_READINESS_LIVE_GATE_UNBOUND:"+gate)

profiles=policy.get("profiles") or {}
profile_name=args.profile or str(policy.get("default_profile") or "")
profile=profiles.get(profile_name)
if not isinstance(profile,dict) or profile.get("contract")!="mad4b.production-readiness-profile.v1":
    fail("PRODUCTION_READINESS_PROFILE_INVALID:"+profile_name)

required_ids=[]
if isinstance(profile.get("required_workstream_ids"),list):
    required_ids=[str(x) for x in profile["required_workstream_ids"]]
else:
    priorities=set(str(x) for x in (profile.get("required_blocking_priorities") or policy.get("required_blocking_priorities") or []))
    required_ids=[str(row.get("id") or "") for row in workstreams if isinstance(row,dict) and str(row.get("priority") or "") in priorities]
required_ids=list(dict.fromkeys(required_ids))
missing=[x for x in required_ids if x not in by_id]
if missing:
    fail("PRODUCTION_READINESS_PROFILE_WORKSTREAM_MISSING:"+",".join(missing))

optional_ids=[str(x) for x in (profile.get("optional_workstream_ids") or [])]
overlap=sorted(set(required_ids).intersection(optional_ids))
if overlap:
    fail("PRODUCTION_READINESS_PROFILE_SCOPE_OVERLAP:"+",".join(overlap))
if profile_name=="control_plane_core":
    if profile.get("optional_capability_policy")!="disabled_and_fail_closed_until_live_certified":
        fail("PRODUCTION_READINESS_OPTIONAL_CAPABILITY_POLICY_INVALID")
    if profile.get("full_feature_complete_claim") is not False:
        fail("CORE_PROFILE_MUST_NOT_CLAIM_FULL_FEATURE_COMPLETENESS")

blockers=[]
for wid in required_ids:
    row=by_id[wid]
    if str(row.get("status") or "")!="DONE":
        blockers.append({
            "id":wid,
            "gate":str(row.get("gate") or ""),
            "priority":str(row.get("priority") or ""),
            "status":str(row.get("status") or ""),
        })

optional_pending=[]
for wid in optional_ids:
    row=by_id.get(wid)
    if row and str(row.get("status") or "")!="DONE":
        optional_pending.append({
            "id":wid,
            "gate":str(row.get("gate") or ""),
            "status":str(row.get("status") or ""),
            "production_activation":"DENIED_UNTIL_LIVE_CERTIFIED",
        })

report={
    "contract":"mad4b.production-readiness-contract-result.v2",
    "profile":profile_name,
    "repository_contract_ready":True,
    "live_blocker_count":len(blockers),
    "live_blockers":blockers,
    "optional_pending_count":len(optional_pending),
    "optional_pending":optional_pending,
    "production_ready":len(blockers)==0,
    "full_feature007_complete":bool(profile.get("full_feature_complete_claim")) and len(blockers)==0,
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
print("mad4b.production-readiness-policy.v2: PASS")
