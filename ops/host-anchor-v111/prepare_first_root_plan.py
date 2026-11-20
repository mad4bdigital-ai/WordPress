#!/usr/bin/env python3
"""Original first-party Root plan preparer for approved first Staging Anchor.

Read-only preflight plus explicit --apply creates ONE immutable root-only plan.
Never issues a Grant, Owner Job, signed Original312/313 receipt or Host Anchor.
No web, arbitrary plan field, user-supplied digest, or private-key input.
"""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import shlex
import stat
import subprocess
import sys

COMPANY="all-royal-egypt"
ENV="staging"
OP="company.host-authority.anchor.issue"
TARGET_HOST="hosting-2"
CENTRAL="https://tours.dev.mad4b.com"
ROOT=Path("/opt/mad4b-company-transport")
REGISTRY=ROOT/"native-313-independent/dynamic-operation-registry-v1/enrolled"
PLAN_MODULE=REGISTRY/"host-anchor-v2/operation_plan_contract.py"
PLAN_MODULE_SHA="8438e3874905d75568bfc1c5c78bf5769a982834cae06a68d6aa111d080f6130"
POLICY=ROOT/"native-operator-v1/host-authority.json"
SITE_MAP=Path("/opt/mad4b-company-provisioning/policies/company-hostinger-site-binding-map-v1.json")
ROUTE=Path("/opt/mad4b-company-provisioning/bin/company-hostinger-two-route-binding-v1.py")
BROKER=ROOT/"broker-v1/native-vps-client-principal.py"
BROKER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"
POLICY_VERIFIER=ROOT/"broker-v1/native-vps-policy-rotate.py"
KNOWN=Path("/opt/hostinger-shell-mcp/external-targets/hosting-2/known_hosts_verified")
STORE=Path("/var/lib/mad4b/original-host-v2/plans")
PLAN_PATH=STORE/"all-royal-egypt--staging.json"
MAX_BYTES=32768
H64=re.compile(r"^[a-f0-9]{64}$")
H32=re.compile(r"^[a-f0-9]{32}$")

class Held(ValueError):pass

def need(condition, reason):
    if not condition:raise Held(reason)

def digest(raw:bytes)->str:
    return hashlib.sha256(raw).hexdigest()

def trusted_file(path:Path,limit=200000):
    need(path.is_file() and not path.is_symlink(),"MISSING_OR_LINKED_FIRST_PARTY_FILE")
    s=path.stat()
    need(s.st_uid==0 and stat.S_ISREG(s.st_mode)
         and not(s.st_mode&0o022) and 0<s.st_size<=limit,
         "UNTRUSTED_FIRST_PARTY_FILE")
    return path.read_bytes()

def module_from_pinned(path,sha,name):
    raw=trusted_file(path)
    need(digest(raw)==sha,"PINNED_SOURCE_CHANGED")
    spec=importlib.util.spec_from_file_location(name,path)
    need(spec is not None and spec.loader is not None,"TRUSTED_IMPORT_NOT_AVAILABLE")
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
    return m

def load_module(path,name):
    trusted_file(path)
    spec=importlib.util.spec_from_file_location(name,path)
    need(spec is not None and spec.loader is not None,"ROUTE_IMPORT_UNAVAILABLE")
    mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
    return mod

def output_record(raw):
    lines=[line[len("MAD4B_HOST_V111:"):] for line in raw.splitlines()
           if line.startswith("MAD4B_HOST_V111:")]
    need(len(lines)==1,"FIRST_PARTY_READBACK_NOT_UNIQUE")
    obj=json.loads(lines[0])
    need(type(obj) is dict,"FIRST_PARTY_READBACK_NOT_OBJECT")
    return obj

def checked_exec(args,timeout):
    p=subprocess.run(args,check=False,stdout=subprocess.PIPE,
                     stderr=subprocess.PIPE,text=True,timeout=timeout,
                     stdin=subprocess.DEVNULL)
    need(p.returncode==0,"FIRST_PARTY_ORIGINAL_READBACK_UNAVAILABLE")
    return p.stdout

def policy_ready():
    raw=trusted_file(POLICY,65536)
    policy=json.loads(raw)
    need(policy.get("contract")=="mad4b.native-vps-operator.v1"
         and policy.get("host_id")=="srv2030908"
         and policy.get("revoked") is False,
         "ORIGINAL_HOST_POLICY_NOT_CURRENT")
    scope=policy.get("scopes",{}).get(COMPANY+":"+ENV)
    need(type(scope) is dict
         and scope.get("allow_operator") is True
         and scope.get("allow_mutation") is True
         and scope.get("site_uuid") is not None,
         "HOST_STAGING_SCOPE_NOT_AUTHORIZED")
    fresh=json.loads(checked_exec(
        ["/usr/bin/python3",str(POLICY_VERIFIER),"plan"],45).strip().splitlines()[-1])
    need(fresh.get("state")=="ORIGINAL_POLICY_ROTATION_NOT_REQUIRED"
         and fresh.get("original_pinned_signer_verified") is True
         and fresh.get("original_principal_ledger_verified") is True
         and fresh.get("host_key_verified") is True
         and fresh.get("grant_issued") is False
         and fresh.get("production_mutated") is False,
         "FIRST_PARTY_SIGNED_POLICY_LEDGER_NOT_CURRENT")
    return scope, digest(raw)

def registry_ready():
    trusted_file(REGISTRY/"index.json")
    sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    index=Registry(REGISTRY)
    provider=index.resolve(OP)
    need(bool(index.host_anchor_312_public_key)
         and provider.get("execution_enabled") is False
         and provider.get("command",{}).get("format")=="host.anchor.ref.v2"
         and provider.get("plan_contract")=="mad4b.host-authority.anchor-issue.plan.v2",
         "ROOT_HOST_ANCHOR_PROVIDER_OR_SIGNER_NOT_PINNED")
    return index

def central():
    broker=module_from_pinned(BROKER,BROKER_SHA,"mad4b_v111_existing_broker")
    php=r"""$c='all-royal-egypt';$e='staging';
if(DB_NAME!=='etg_commerce_staging'||wp_get_environment_type()!=='staging'
 ||wp_parse_url(home_url(),PHP_URL_HOST)!=='tours.dev.mad4b.com')
 WP_CLI::error('EXACT_CENTRAL_SOURCE_REQUIRED');
$site=MAD4B_Company_Environment_Registry_V2::target($c,$e);
$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$c.'_'.$e,false);
$t=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.$slot['transport_id'],false):false;
$parent=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$slot['grant_id'],false):false;
$active=is_array($t)&&is_array($parent)&&MAD4B_Original_Remote_Hostinger_328_V1::active($t,$parent);
$anchor=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2($c,$e);
global $wpdb;
$gp=MAD4B_Central_SSH_Command_Intake_V1::PREFIX;
$names=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 300",$wpdb->esc_like($gp).'%'));
$matches=[];
foreach($names as $name) {
 $g=get_option($name,false);
 if(!is_array($g)||($g['company']??null)!==$c||($g['environment']??null)!==$e
 ||($g['operation']??null)!=='company.host-authority.anchor.issue'
 ||($g['state']??null)!=='CENTRAL_OPERATION_SCOPE_GRANTED')continue;
 $matches[]=$g;
}
$reqp=MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX;
$requests=$wpdb->get_col($wpdb->prepare("SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s ORDER BY option_id DESC LIMIT 300",$wpdb->esc_like($reqp).'%'));
$jobCount=0;foreach($requests as $n){$v=get_option($n,false);
 if(is_array($v)&&($v['company']??null)===$c&&($v['environment']??null)===$e
 &&($v['operation']??null)==='company.host-authority.anchor.issue')$jobCount++;
}
$grant=count($matches)===1?$matches[0]:[];
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$rights=MAD4B_Manager_Auto_Write_V1::entitlement($manager,$c,'operations.write',(int)($grant['central_principal_id']??0));
$out=[
 'site_uuid'=>$site['site_uuid']??null,'origin'=>$site['canonical_origin']??null,
 'environment'=>$site['environment']??null,
 'profile_digest'=>$site['identity_evidence']['profile_digest']??null,
 'site_company'=>$site['company_id']??null,'original328_active'=>$active,
 'owner_grant_count'=>count($matches),'owner_request_count'=>$jobCount,
 'owner_grant_valid'=>($grant['host_v2_bootstrap_only']??null)===true
    &&($grant['host_v2_execution_authorized']??null)===false
    &&($grant['execution_authorized']??null)===false
    &&($grant['original328_parent_grant_id']??null)===($slot['grant_id']??null)
    &&($grant['transport_id']??null)===($slot['transport_id']??null)
    &&($grant['assignment_revision']??null)===($t['assignment_revision']??null)
    &&($grant['site_uuid']??null)===($site['site_uuid']??null)
    &&($grant['canonical_origin']??null)===($site['canonical_origin']??null)
    &&($grant['central_principal_id']??null)===($parent['central_principal_id']??null),
 'delegation_active'=>($rights['assignment_authorized']??false)===true,
 'transport_host'=>$t['host_id']??null,
 'transport_ssh_principal'=>$t['host_principal']??null,
 'transport_site_uuid'=>$t['site_uuid']??null,
 'anchor_state'=>$anchor['state']??null,
 'anchor_revision'=>$anchor['revision']??null,
 'anchor_write_authorized'=>$anchor['write_authorized']??null,
 'anchor_readback_verified'=>$anchor['readback_verified']??null
];
echo 'MAD4B_HOST_V111:'.wp_json_encode($out,JSON_UNESCAPED_SLASHES);
"""
    data=output_record(checked_exec([*broker.original_central_wp(),"eval",php],42))
    need(data.get("site_company")==COMPANY and data.get("environment")==ENV
         and data.get("original328_active") is True
         and data.get("owner_grant_count")==1
         and data.get("owner_request_count")==0
         and data.get("owner_grant_valid") is True
         and data.get("delegation_active") is True
         and data.get("transport_host")==TARGET_HOST
         and data.get("transport_site_uuid")==data.get("site_uuid")
         and data.get("anchor_state")=="MISSING_OR_INVALID"
         and data.get("anchor_revision")==0
         and data.get("anchor_write_authorized") is False
         and data.get("anchor_readback_verified") is False,
         "EXACT_ORIGINAL328_OWNER_OR_ANCHOR_PRESTATE_INVALID")
    need(type(data.get("profile_digest")) is str
         and H64.fullmatch(data["profile_digest"]) is not None,
         "SITE_PROFILE_DIGEST_NOT_PRESENT")
    return data

def remote(row):
    routes=load_module(ROUTE,"mad4b_v111_host_route")
    need(row["ssh_target"]==TARGET_HOST
         and row["ssh_username"]==routes.HOSTS[TARGET_HOST][0],
         "FIRST_PARTY_REMOTE_PRINCIPAL_CHANGED")
    php="""$s=MAD4B_SCP_Site_Profile::status();
echo 'MAD4B_HOST_V111:'.wp_json_encode([
 'uuid'=>$s['site_uuid']??null,
 'origin'=>$s['canonical_origin']??null,
 'environment'=>$s['environment']??null,
 'digest'=>$s['profile_digest']??null,
 'origin_match'=>$s['origin_match']??false,
 'environment_match'=>$s['environment_match']??false],JSON_UNESCAPED_SLASHES);"""
    cmd="cd "+shlex.quote(row["ssh_root"])+" && wp eval "+shlex.quote(php)+" --skip-themes"
    return output_record(checked_exec(routes.ssh_cmd(TARGET_HOST)+[cmd],47))

def facts():
    need(os.geteuid()==0,"ROOT_ONLY_ROOT_PLAN_PREPARATION")
    need(subprocess.run(["hostname","-s"],capture_output=True,text=True,timeout=4).stdout.strip()=="srv2030908",
         "FIRST_PARTY_ROOT_HOST_NOT_MATCHED")
    scope,policy_sha=policy_ready()
    idx=registry_ready()
    rows=json.loads(trusted_file(SITE_MAP,200000))
    need(rows.get("contract")=="mad4b.company-hostinger-site-binding-map.v1"
         and isinstance(rows.get("sites"),list),
         "REGISTERED_SITE_MAP_INVALID")
    matched=[r for r in rows["sites"]
             if r.get("company_id")==COMPANY and r.get("environment")==ENV]
    need(len(matched)==1,"EXACT_STAGING_SITE_MAP_NOT_UNIQUE")
    row=matched[0]
    need(row.get("ssh_target")==TARGET_HOST
         and row.get("origin")==scope.get("canonical_origin")
         and row.get("ssh_root")==scope.get("expected_root")
         and scope.get("registry_binding")==TARGET_HOST+":"+ENV
         and row.get("ssh_root","").startswith("/home/"+row.get("ssh_username","")+"/domains/"),
         "FIRST_PARTY_STAGING_SSH_POLICY_MAP_DRIFT")
    site=central()
    need(site["site_uuid"]==scope["site_uuid"]
         and site["origin"]==row["origin"]
         and site["transport_ssh_principal"]==row["ssh_username"],
         "ORIGINAL328_SITE_AND_HOST_POLICY_SCOPE_CHANGED")
    observed=remote(row)
    need(observed.get("uuid")==site["site_uuid"]
         and observed.get("origin")==site["origin"]
         and observed.get("environment")==ENV
         and observed.get("digest")==site["profile_digest"]
         and observed.get("origin_match") is True
         and observed.get("environment_match") is True,
         "INDEPENDENT_HOSTINGER_SITE_PROFILE_MISMATCH")
    root_sha=digest(row["ssh_root"].encode("utf-8"))
    host_sha=digest(trusted_file(KNOWN,200000))
    return {
      "operation_id":OP,
      "contract":"mad4b.host-authority.anchor-issue.plan.v2",
      "company":COMPANY,"environment":ENV,
      "site_uuid":site["site_uuid"],"origin":site["origin"],
      "target_host_id":TARGET_HOST,"ssh_principal":row["ssh_username"],
      "policy_sha256":policy_sha,
      "profile_digest":site["profile_digest"],
      "host_key_sha256":host_sha,
      "ssh_root_sha256":root_sha,
      "registry_sha256":digest(trusted_file(SITE_MAP,200000)),
      "authority_mode":"bootstrap",
      "grant_revision":0,"anchor_revision":0,
      "requested_anchor_revision":1,
    }

def make_plan(verified_facts,enrollment_id,enrollment_nonce):
    need(type(verified_facts) is dict
         and H32.fullmatch(enrollment_id) is not None
         and H32.fullmatch(enrollment_nonce) is not None
         and enrollment_id!=enrollment_nonce,
         "EXACT_FIRST_ENROLLMENT_ID_AND_NONCE_REQUIRED")
    plan=dict(verified_facts)
    plan["enrollment_id"]=enrollment_id
    plan["enrollment_nonce"]=enrollment_nonce
    verifier=module_from_pinned(PLAN_MODULE,PLAN_MODULE_SHA,"mad4b_v111_root_plan_contract")
    plan["plan_sha256"]=digest(verifier.canonical(plan))
    result=verifier.verify_plan(plan,OP)
    need(result["first_anchor_bootstrap"] is True
         and result["execution_authorized"] is False
         and result["anchor_issued"] is False,
         "ROOT_FIRST_ANCHOR_PLAN_REJECTED")
    return plan

def write_once(plan,destination=PLAN_PATH):
    path=Path(destination)
    need(not path.is_symlink(),"UNTRUSTED_PLAN_SYMLINK")
    path.parent.mkdir(mode=0o700,parents=True,exist_ok=True)
    s=path.parent.stat()
    need(s.st_uid==0 and stat.S_IMODE(s.st_mode)==0o700,
         "ROOT_PLAN_STORE_NOT_0700")
    raw=(json.dumps(plan,sort_keys=True,indent=2,ensure_ascii=False)+"\n").encode("utf-8")
    need(len(raw)<MAX_BYTES,"ROOT_PLAN_TOO_LARGE")
    if path.exists():
        existing=json.loads(trusted_file(path,MAX_BYTES))
        verifier=module_from_pinned(PLAN_MODULE,PLAN_MODULE_SHA,"mad4b_v111_existing_plan_contract")
        verifier.verify_plan(existing,OP)
        for key,val in plan.items():
            if key in ("enrollment_id","enrollment_nonce","plan_sha256"):continue
            need(existing.get(key)==val,"PRIOR_ROOT_PLAN_SOURCE_DRIFT")
        return existing,False
    fd=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(raw);f.flush();os.fsync(f.fileno())
        os.chmod(path,0o600)
    except BaseException:
        path.unlink(missing_ok=True)
        raise
    return plan,True

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument("--apply",action="store_true",
                    help="Create exactly one immutable root-only plan; default: preflight")
    args=ap.parse_args()
    try:
        checked=facts()
        if not args.apply:
            result={"state":"ROOT_PLAN_PREFLIGHT_READY","operation_id":OP,
                    "staging_site_verified":True,"existing_owner_grant_verified":True,
                    "remote_profile_matched":True,"source_readbacks_current":True,
                    "plan_written":False,"owner_request_queued":False,
                    "anchor_issued":False}
            print(json.dumps(result,sort_keys=True));return 0
        fresh=make_plan(checked,secrets.token_hex(16),secrets.token_hex(16))
        plan,written=write_once(fresh)
        result={"state":"ROOT_FIRST_PLAN_PREPARED_VERIFIED",
                "operation_id":OP,"company":COMPANY,"environment":ENV,
                "plan_sha256":plan["plan_sha256"],"plan_written":written,
                "site_uuid":plan["site_uuid"],"root_plan_path":str(PLAN_PATH),
                "owner_request_queued":False,"original312_signed":False,
                "original313_signed":False,"anchor_issued":False,
                "production_changed":False}
        print(json.dumps(result,sort_keys=True));return 0
    except (Held,ValueError,OSError,KeyError,subprocess.TimeoutExpired) as e:
        print(json.dumps({"state":"HOLD","reason":str(e)[:170],
                          "plan_written":False,"owner_request_queued":False,
                          "anchor_issued":False},sort_keys=True))
        return 2

if __name__=="__main__":
    raise SystemExit(main())
