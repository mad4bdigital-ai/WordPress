"""Read-only source-native Full Authority status over the enrolled SSH identity.

This adapter does not install plugins, mint grants, use CLI --user, or apply
authority. An operational-status response is NOT a native owner approval plan.
"""
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import shlex

REGISTRY=Path("/opt/mad4b-company-transport/registry-remote-v1/registry_remote.py")
CONTRACT="mad4b.native-authority-status-readback.v1"

# This is a fixed, read-only native status projection. Never interpolate user
# events, arbitrary PHP, WP options or shell commands into this source string.
STAGING_PHP=r'''$class="MAD4B_SCP_Full_Staging_Authority";
if (!class_exists($class) || !class_exists("MAD4B_SCP_Site_Profile")) {
    echo wp_json_encode(array("contract"=>"mad4b.native-authority-status-readback.v1",
        "state"=>"native_owner_missing")); return;
}
$s=$class::status();
$p="MAD4B_SCP_Site_Profile";
echo wp_json_encode(array(
    "contract"=>"mad4b.native-authority-status-readback.v1",
    "state"=>"native_status_observed",
    "native_contract"=>$s["contract"]??"",
    "native_owner"=>$class,
    "site_uuid"=>(string)$p::site_uuid(),
    "environment"=>(string)$p::current_environment(),
    "canonical_origin"=>rtrim(home_url("/"),"/"),
    "profile_exact"=>(bool)($p::configured() && $p::origin_enrolled()
        && $p::site_urls_match_enrollment()),
    "read_only"=>$s["read_only"]??null,
    "authorizing"=>$s["authorizing"]??null,
    "mutation_performed"=>$s["mutation_performed"]??null,
    "ready"=>$s["ready"]??null,
    "authority_ready"=>$s["authority_ready"]??null,
    "operational_ready"=>$s["operational_ready"]??null,
    "operational_state"=>$s["operational_state"]??"",
    "operational_blockers"=>array_values((array)($s["operational_blockers"]??array()))
),JSON_UNESCAPED_SLASHES);'''

def validate(data,scope,provider):
    if not isinstance(data,dict) or data.get("contract")!=CONTRACT:
        return ("review_required","native_status_contract_invalid",{})
    if data.get("state")=="native_owner_missing":
        return ("awaiting_provider","native_owner_not_installed",{})
    if data.get("state")!="native_status_observed":
        return ("review_required","native_owner_status_invalid",{})
    if any([data.get("site_uuid")!=scope.get("site_uuid"),
            data.get("environment")!=provider.get("environment"),
            data.get("canonical_origin")!=scope.get("canonical_origin"),
            data.get("native_owner")!=provider.get("native_owner"),
            data.get("native_contract")!="mad4b.full-staging-authority.v1",
            data.get("profile_exact") is not True,
            data.get("read_only") is not True,
            data.get("authorizing") is not False,
            data.get("mutation_performed") is not False]):
        return ("review_required","native_owner_status_scope_or_readback_invalid",{})
    blockers=data.get("operational_blockers",[])
    if not isinstance(blockers,list) or len(blockers)>32 or not all(
        isinstance(x,str) and re.fullmatch(r"[a-zA-Z0-9_.:-]{1,128}",x) for x in blockers
    ):
        return ("review_required","native_owner_blocker_contract_invalid",{})
    evidence={
        "site_uuid":data["site_uuid"],"environment":data["environment"],
        "native_owner":data["native_owner"],"authority_ready":data.get("authority_ready"),
        "operational_ready":data.get("operational_ready"),
        "operational_state":data.get("operational_state",""),
        "operational_blockers":blockers,
        "source_contract":data["native_contract"],"source_kind":"native_status_not_approval_plan",
        "apply_executed":False}
    raw=json.dumps(evidence,sort_keys=True,separators=(",",":"))
    evidence["status_receipt_sha256"]=hashlib.sha256(raw.encode()).hexdigest()
    if data.get("authority_ready") is not True:
        return ("blocked_authority","native_authority_status_not_ready",evidence)
    if data.get("operational_ready") is not True:
        return ("blocked_dependency","native_executor_operational_readiness_missing",evidence)
    return ("awaiting_provider","native_status_ready_requires_authenticated_plan",evidence)

def probe(scope,provider):
    if provider.get("reader")!="enrolled_site_wpcli_status_v1":
        return ("awaiting_provider","native_owner_status_reader_not_enrolled",{})
    binding=scope.get("transport_binding")
    if binding!="hosting-2:staging" or provider.get("native_owner")!="MAD4B_SCP_Full_Staging_Authority" or provider.get("environment")!="staging":
        return ("review_required","native_reader_target_not_allowlisted",{})
    if not REGISTRY.is_file():
        return ("awaiting_provider","registry_transport_unavailable",{})
    try:
        spec=importlib.util.spec_from_file_location("mad4b_registered_remote_for_auth_status",REGISTRY)
        remote=importlib.util.module_from_spec(spec)
        spec.loader.exec_module(remote)
        record=remote.TARGETS[binding]
        if record.get("site_uuid")!=scope.get("site_uuid"):
            return ("review_required","registry_native_site_uuid_mismatch",{})
        root=remote.guard(binding)
        if root!=scope.get("expected_root"):
            return ("review_required","registry_native_root_mismatch",{})
        cmd="wp --path="+shlex.quote(root)+" --skip-themes eval "+shlex.quote(STAGING_PHP)
        response=remote.remote(binding,cmd)
        data=json.loads(response)
    except (OSError,RuntimeError,ValueError,KeyError,AttributeError):
        return ("retry","registered_native_status_unavailable",{})
    return validate(data,scope,provider)
