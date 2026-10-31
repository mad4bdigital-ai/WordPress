"""Source-native central Control Plane readback for the Universal Resolver.

Only observes live WP-CLI source provenance and canonical authority drift.
No new grants, identity rebinding, package promotion or production mutation.
"""
import hashlib
import json
import re
import subprocess

CONTRACT="mad4b.central-source-authority-observer.v1"
TARGET="tours-central:staging"
CONTAINER="etg-commerce-staging-php"
WP=["docker","exec","-u","www-data",CONTAINER,"wp","eval"]
ARGS=["--user=1","--path=/var/www/html","--skip-themes"]
SOURCE_PHP=r'''$identity=array("site_uuid"=>(string)MAD4B_SCP_Site_Profile::site_uuid(),
"environment"=>(string)MAD4B_SCP_Site_Profile::current_environment(),
"canonical_origin"=>rtrim(home_url("/"),"/"));
$b=MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();
$p=MAD4B_SCP_Authority_Auto_Reconciliation::plan();
$w=MAD4B_SCP_Staging_Write_Authority::reconciliation_plan();
$allowed=MAD4B_SCP_Staging_Write_Grant_Reconciliation::allowed_ability_providers();
$outside=array();
$missing=array();
if(is_array($p)){foreach((array)($p["missing_exact_grants"]??array()) as $g){
 $name=(string)($g["ability"]??"");$provider=(string)($g["provider"]??"");
 if(($allowed[$name]??null)!==$provider)$outside[]=$name;
 $missing[]=array("ability"=>$name,
  "provider"=>(string)($g["provider"]??""),
  "reason"=>(string)($g["reason"]??""));
}}
echo wp_json_encode(array("contract"=>"mad4b.central-source-authority-observer.v1",
"identity"=>$identity,"source"=>array(
"manifest_valid"=>$b["manifest_valid"]??null,
"runtime_manifest_match"=>$b["runtime_manifest_match"]??null,
"stale"=>$b["stale"]??null,
"provenance_mismatch"=>$b["provenance_mismatch"]??null,
"source_commit_sha"=>$b["source_commit_sha"]??null),
"authority"=>array(
"contract"=>is_array($p)?($p["contract"]??""):"",
"error"=>is_wp_error($p)?$p->get_error_code():"",
"missing"=>$missing,
"outside_grant_policy"=>$outside,
"reviewed_stale_grants_count"=>is_array($w)?(int)($w["stale_allow_grants_count"]??0):null,
"existing_exact_count"=>is_array($w)?(int)($w["exact_grants_existing"]??0):null,
"write_tool_count"=>is_array($w)?(int)($w["write_tool_count"]??0):null,
"subject_conflicts"=>is_array($p)?($p["subject_conflicts"]??array()):array(),
"candidate_binding_match"=>is_array($p)?($p["candidate_binding_match"]??null):null,
"safe_stale_retirement_count"=>is_array($p)?count($p["safe_stale_retirement"]??array()):null,
"needs_review_count"=>is_array($p)?count($p["needs_review"]??array()):null,
"source_commit_sha"=>is_array($p)?($p["source_commit_sha"]??""):"")),JSON_UNESCAPED_SLASHES);'''

def read_live():
    p=subprocess.run(WP+[SOURCE_PHP]+ARGS,capture_output=True,text=True,timeout=48,check=False)
    if p.returncode:
        raise RuntimeError("CENTRAL_WPCLI_READBACK_UNAVAILABLE")
    data=json.loads(p.stdout.strip())
    if not isinstance(data,dict) or data.get("contract")!=CONTRACT:
        raise ValueError("CENTRAL_READBACK_CONTRACT_INVALID")
    return data

def check_identity(data,scope):
    expected=scope.get("site_uuid")
    origin=scope.get("canonical_origin")
    ident=data.get("identity",{})
    return (isinstance(ident,dict)
            and ident.get("site_uuid")==expected
            and ident.get("environment")=="staging"
            and ident.get("canonical_origin")==origin)

def provenance(data,scope):
    if not check_identity(data,scope):
        return ("review_required","central_site_identity_mismatch",{})
    src=data.get("source")
    if not isinstance(src,dict):
        return ("review_required","central_provenance_response_invalid",{})
    source=src.get("source_commit_sha")
    expected=scope.get("expected_source_commit_sha")
    if not isinstance(source,str) or not re.fullmatch(r"[a-f0-9]{40}",source):
        return ("review_required","source_commit_invalid",{})
    evidence={"source_commit_sha":source,"expected_source_commit_sha":expected,
              "manifest_valid":src.get("manifest_valid"),
              "runtime_manifest_match":src.get("runtime_manifest_match"),
              "stale":src.get("stale"),"provenance_mismatch":src.get("provenance_mismatch"),
              "production_mutation":False}
    if (src.get("manifest_valid") is not True or
        src.get("runtime_manifest_match") is not True or
        src.get("stale") is not False or
        src.get("provenance_mismatch")!=[] or
        source!=expected):
        return ("blocked_dependency","source_provenance_or_expected_commit_mismatch",evidence)
    return ("verified","central_source_provenance_verified",evidence)

def authority(data,scope):
    if not check_identity(data,scope):
        return ("review_required","central_site_identity_mismatch",{})
    state=data.get("authority")
    if not isinstance(state,dict) or state.get("contract")!="mad4b.authority-auto-reconciliation.v1":
        return ("awaiting_provider","original_authority_plan_unavailable",{})
    rows=state.get("missing")
    if not isinstance(rows,list) or len(rows)>200:
        return ("review_required","grants_drift_contract_invalid",{})
    if any(not isinstance(x,dict) or
        not isinstance(x.get("ability"),str) or
        not isinstance(x.get("provider"),str) for x in rows):
        return ("review_required","grants_drift_rows_invalid",{})
    digest=hashlib.sha256(json.dumps(rows,sort_keys=True).encode()).hexdigest()
    conflict=state.get("subject_conflicts",[])
    if not isinstance(conflict,list):
        return ("review_required","principal_binding_contract_invalid",{})
    outside=state.get("outside_grant_policy",[])
    if not isinstance(outside,list) or len(outside)>200 or any(not isinstance(x,str) for x in outside):
        return ("review_required","policy_gap_contract_invalid",{})
    evidence={"missing_exact_grants_count":len(rows),
              "existing_exact_grants_count":state.get("existing_exact_count"),
              "write_tool_count":state.get("write_tool_count"),
              "outside_grant_policy_count":len(outside),
              "outside_grant_policy_abilities":outside,
              "reviewed_stale_grants_count":state.get("reviewed_stale_grants_count"),
              "missing_exact_grants_digest":digest,
              "missing_providers":sorted(set(x["provider"] for x in rows)),
              "subject_conflicts":conflict[:30],
              "candidate_binding_match":state.get("candidate_binding_match"),
              "retirement_safe_count":state.get("safe_stale_retirement_count"),
              "needs_review_count":state.get("needs_review_count"),
              "source_commit_sha":state.get("source_commit_sha"),
              "expected_source_commit_sha":scope.get("expected_source_commit_sha"),
              "source_pin_verified":state.get("source_commit_sha")==scope.get("expected_source_commit_sha"),
              "grant_created":False,"production_mutation":False}
    # An identity conflict remains observable when the release pin is stale.
    # Observation does not authorize grants or perform any operation.
    if conflict:
        return ("review_required","original_oauth_agent_binding_conflict",evidence)
    if not evidence["source_pin_verified"]:
        return ("blocked_dependency","grant_plan_source_not_pinned",evidence)
    if rows or state.get("candidate_binding_match") is not True:
        return ("blocked_authority","native_exact_grant_or_candidate_reconciliation_required",evidence)
    return ("verified","native_exact_authority_reconciled",evidence)

def probe(domain,scope):
    try:data=read_live()
    except (ValueError,RuntimeError,subprocess.TimeoutExpired,OSError):
        return ("retry","central_source_readback_unavailable",{})
    return provenance(data,scope) if domain=="central_source" else authority(data,scope)
