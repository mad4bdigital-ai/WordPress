#!/usr/bin/env python3
"""Issue a genuine first-party Central 305 + Broker 306 Code Release Job.

No WordPress user override, shadow Job, modified owner identity, invented
receipt, Staging-only limitation or direct database insertion. The signed
original Native 305 company standing grant must already be enrolled, and
the root Broker signer must match Central. A separate job-scoped release
grant is signed only *after* a real original 305 and Broker 306 Job readback.
"""
from __future__ import annotations

import argparse
import base64
import datetime as dt
import fcntl
import hashlib
import json
import os
from pathlib import Path
import secrets
import subprocess
import sys
import time

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import (
    Encoding, PublicFormat, load_pem_private_key
)

OPS=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(OPS/"company-code-release-v1"))
sys.path.insert(0,str(OPS/"dynamic-operation-registry-v1"))
import company_code_release as release
import registry

HOST="srv2030908"
CONTAINER="php-wkgtkzta35wkkgouwt6ruljc"
ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
INDEX=ROOT/"dynamic-operation-registry-v1/enrolled"
PLAN_FILE=Path("/opt/mad4b-company-provisioning/reports/p1-closure-20261109/media/wayfa-media-library-release-candidate-plan.json")
BROKER_KEY=Path("/opt/mad4b-company-transport/broker-v1/host-ed25519-private.pem")
GRANTS=Path("/var/lib/mad4b/company-code-release/grants")
BRIDGE="/tmp/mad4b-original-305-signed-native-release-bridge.php"
LOCK=Path("/run/lock/mad4b-original-305-code-release-issue.lock")
FIELDS=("contract","company","environment","site_uuid","canonical_origin",
        "host_id","grant_id","actor_user_id","method","route","body_sha256",
        "issued","expires","nonce")
COMPANY="wayfatours"
ENV="production"

def require(value:bool,code:str):
    if not value:raise RuntimeError(code)

def run(command:list[str],*,body:str|None=None,timeout:int=42)->str:
    result=subprocess.run(command,input=body,stdout=subprocess.PIPE,
                          stderr=subprocess.PIPE,text=True,timeout=timeout)
    require(result.returncode==0,"ORIGINAL_NATIVE_COMMAND_FAILED:"
            +("CENTRAL_WPCLI_BUSY" if "STAGING_WPCLI_BUSY" in result.stderr
              else "FIRST_PARTY_NATIVE_RESULT_REJECTED"))
    lines=[x.strip() for x in result.stdout.splitlines() if x.strip()]
    require(bool(lines),"EMPTY_ORIGINAL_NATIVE_REPLY")
    return lines[-1]

def cli_json(code:str)->dict:
    x=run(["docker","exec","-u","www-data",CONTAINER,
           "wp","--path=/var/www/html","--skip-themes","eval",code],timeout=65)
    value=json.loads(x)
    require(isinstance(value,dict),"ORIGINAL_READBACK_NOT_OBJECT")
    return value

def signer()->Ed25519PrivateKey:
    info=BROKER_KEY.lstat()
    require(info.st_uid==0 and info.st_mode & 0o077==0
            and not BROKER_KEY.is_symlink(),"FIRST_PARTY_BROKER_SIGNER_NOT_ROOT_ONLY")
    key=load_pem_private_key(BROKER_KEY.read_bytes(),password=None)
    require(isinstance(key,Ed25519PrivateKey),"NOT_FIRST_PARTY_ED25519_BROKER")
    return key

def admission(index:registry.Registry,p:dict,signer:Ed25519PrivateKey)->dict:
    digest=index.command_sha256("company.code.release",p["plan_sha256"])
    code=r"""$company="wayfatours";$env="production";
$c="MAD4B_Company_Native_WPCLI_Enrollment_V1";
$e=$c::readback($company,$env);
$s=get_option($c::SLOT.$company."_".$env,false);
$g=is_array($s)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.$s["grant_id"],false):false;
$t=MAD4B_Manager_Auto_Write_V1::company_target($company);
$mgr=MAD4B_Company_Operation_Admission_V1::manager_id();
$a=is_array($g)?(int)($g["central_principal_id"]??0):0;
$rights=$a>0?MAD4B_Manager_Auto_Write_V1::entitlement($mgr,$company,"operations.write",$a):[];
$key=MAD4B_Original_WPCLI_Sealed_Command_V2::KEY_PREFIX."srv2030908";
$host=get_option($key,false);
echo wp_json_encode([
"native"=>$e,"company_target"=>$t,
"grant"=>is_array($g)?$g:null,
"rights"=>["assignment_authorized"=>$rights["assignment_authorized"]??false,
"manager_membership_active"=>$rights["manager_membership_active"]??false,
"company_binding_verified"=>$rights["company_binding_verified"]??false],
"original_host_x25519_enrolled"=>is_array($host),
"registered_original_305"=>MAD4B_Original_Native_Operation_Providers_V1::original_305_operation_registered("company.remote.wpcli.full"),
"broker_public_key_b64"=>get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,"")
]);"""
    x=cli_json(code)
    e=x["native"];g=x["grant"];t=x["company_target"];rights=x["rights"]
    require(isinstance(e,dict) and
            e.get("state")=="ORIGINAL_305_PERSISTENT_PAIR_ENROLLED" and
            e.get("full_wpcli_active") is True and
            e.get("host_authority_verified") is True,
            "ORIGINAL_305_COMPANY_NATIVE_ENROLLMENT_REQUIRED")
    require(isinstance(g,dict) and
            g.get("state")=="CENTRAL_OPERATION_SCOPE_GRANTED" and
            g.get("grant_mode")=="persistent" and
            g.get("operation")=="company.remote.wpcli.full" and
            g.get("principal_type")=="signed_vps_wpcli" and
            g.get("company")==COMPANY and g.get("environment")==ENV and
            g.get("site_uuid")==p["site_uuid"] and
            g.get("canonical_origin")==p["origin"] and
            g.get("target_host_id")==HOST and
            isinstance(g.get("central_principal_id"),int) and
            g["central_principal_id"]>0,
            "ORIGINAL_305_STANDING_COMPANY_GRANT_NOT_ACTIVE")
    require(isinstance(t,dict) and t.get("state")=="REGISTERED" and
            t.get("site_uuid")==p["site_uuid"] and
            t.get("canonical_origin")==p["origin"] and
            t.get("environment")==ENV,
            "ORIGINAL_COMPANY_TARGET_CHANGED")
    require(all(rights.get(k) is True for k in rights),
            "ORIGINAL_305_COMPANY_DELEGATION_NOT_ACTIVE")
    require(x.get("registered_original_305") is True and
            x.get("original_host_x25519_enrolled") is True,
            "ORIGINAL_305_SIGNED_SEALED_WPCLI_NOT_ENROLLED")
    pub=signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    require(base64.b64encode(pub).decode()==x.get("broker_public_key_b64"),
            "ORIGINAL_305_BROKER_PUBLIC_KEY_CHANGED")
    return {"company":COMPANY,"environment":ENV,
            "site_uuid":p["site_uuid"],"origin":p["origin"],
            "original_305_grant_id":g["grant_id"],
            "original_actor_id":g["central_principal_id"],
            "original_305_transport_id":g["transport_id"],
            "command_sha256":digest}

def signed_request(action:str,body:str,scope:dict,key:Ed25519PrivateKey)->dict:
    route=("/mad4b/v1/central-execution-lanes/"+COMPANY+
           ("/commands" if action=="intake" else "/broker-dispatch"))
    now=int(time.time())
    proof=dict(contract="mad4b.original-305-signed-native-cli.v1",
               company=COMPANY,environment=ENV,site_uuid=scope["site_uuid"],
               canonical_origin=scope["origin"],host_id=HOST,
               grant_id=scope["original_305_grant_id"],
               actor_user_id=scope["original_actor_id"],method="POST",
               route=route,body_sha256=hashlib.sha256(body.encode()).hexdigest(),
               issued=now,expires=now+60,nonce=secrets.token_hex(16))
    require(tuple(proof)==FIELDS,"ORIGINAL_NATIVE_PROOF_FIELD_ORDER_CHANGED")
    proof["signature"]=base64.b64encode(
        key.sign("\n".join(str(proof[f]) for f in FIELDS).encode())).decode()
    return {"action":action,"raw_body":body,"proof":proof}

def send(action:str,data:dict,scope:dict,key:Ed25519PrivateKey)->dict:
    raw=json.dumps(data,separators=(",",":"),ensure_ascii=False)
    payload=signed_request(action,raw,scope,key)
    response=run(["docker","exec","-i","-u","www-data",CONTAINER,"wp",
                 "--path=/var/www/html","--skip-themes","eval-file",BRIDGE],
                 body=json.dumps(payload,separators=(",",":"),ensure_ascii=False),
                 timeout=55)
    x=json.loads(response)
    require(x.get("state")=="ACCEPTED" and
            x.get("http_status") in (200,201,202) and
            isinstance(x.get("result"),dict),
            "ORIGINAL_305_OR_306_DENIED")
    return x["result"]

def verify_expired_jobs_before_reissue(plan:dict,policy:dict,key:Ed25519PrivateKey)->int:
    # Never roll forward an uncertain Host execution, and never rewrite,
    # delete, extend or replay the expired original 305/306 Job or Grant.
    # A *new* 305 request must be signed independently.
    previous=sorted(GRANTS.glob("*.json"))
    require(len(previous)<=15,"RELEASE_GRANT_HISTORY_UNBOUNDED")
    for item in previous:
        info=item.lstat()
        require(item.is_file() and not item.is_symlink() and
                info.st_uid==0 and info.st_mode&0o077==0,
                "HISTORICAL_GRANT_FILE_UNTRUSTED")
        grant=json.loads(item.read_text())
        # Historical grants for a DIFFERENT immutable release must be
        # cryptographically checked, expired, and disjoint in file scope.
        # They are not retry grants for this plan and must never be replayed.
        if grant.get("plan_sha256")!=plan["plan_sha256"]:
            other=grant.get("plan_sha256")
            require(isinstance(other,str) and len(other)==64 and
                    all(c in '0123456789abcdef' for c in other),
                    "HISTORICAL_OTHER_PLAN_INVALID")
            otherfile=Path("/var/lib/mad4b/dynamic-operation-registry/plans")/(other+".json")
            require(otherfile.is_file() and not otherfile.is_symlink() and
                    otherfile.stat().st_uid==0 and
                    otherfile.stat().st_mode&0o077==0,
                    "HISTORICAL_OTHER_PLAN_NOT_ENROLLED")
            oldplan=json.loads(otherfile.read_text())
            oldpayload={k:v for k,v in oldplan.items() if k not in
                        ("plan_sha256","changed_count")}
            require(registry.sha(registry.canonical(oldpayload))==other and
                    oldplan.get("company")==plan["company"] and
                    oldplan.get("environment")==plan["environment"] and
                    oldplan.get("site_uuid")==plan["site_uuid"] and
                    oldplan.get("origin")==plan["origin"],
                    "HISTORICAL_OTHER_PLAN_SCOPE_INVALID")
            oldtargets={row["target"] for row in oldplan["files"]}
            newtargets={row["target"] for row in plan["files"]}
            require(not oldtargets.intersection(newtargets),
                    "HISTORICAL_OTHER_PLAN_TARGET_OVERLAP")
            require(grant.get("contract")==release.AUTH_CONTRACT and
                    grant.get("issuer")=="mad4b-company-broker" and
                    grant.get("action")=="apply" and
                    grant.get("company")==plan["company"] and
                    grant.get("environment")==plan["environment"] and
                    grant.get("site_uuid")==plan["site_uuid"] and
                    grant.get("origin")==plan["origin"] and
                    grant.get("original_job_id")==item.stem,
                    "HISTORICAL_OTHER_GRANT_SCOPE_INVALID")
            try:
                key.public_key().verify(base64.b64decode(grant["signature"],validate=True),
                                        release.grant_message(grant))
                previous_expiry=dt.datetime.fromisoformat(grant["expires_at"])
            except Exception as exc:
                raise RuntimeError("HISTORICAL_OTHER_GRANT_SIGNATURE_INVALID") from exc
            require(previous_expiry.tzinfo is not None and
                    previous_expiry<dt.datetime.now(dt.timezone.utc),
                    "HISTORICAL_OTHER_GRANT_STILL_ACTIVE")
            continue
        job=grant.get("original_job_id")
        require(isinstance(job,str) and len(job)==32 and
                all(c in '0123456789abcdef' for c in job) and
                item.name==job+".json",
                "HISTORICAL_SIGNED_JOB_ID_MISMATCH")
        require(grant.get("contract")==release.AUTH_CONTRACT and
                grant.get("issuer")=="mad4b-company-broker" and
                grant.get("action")=="apply" and
                grant.get("plan_sha256")==plan["plan_sha256"] and
                grant.get("company")==plan["company"] and
                grant.get("environment")==plan["environment"] and
                grant.get("site_uuid")==plan["site_uuid"] and
                grant.get("origin")==plan["origin"],
                "HISTORICAL_SIGNED_RELEASE_SCOPE_DRIFT")
        try:
            key.public_key().verify(
                base64.b64decode(grant["signature"],validate=True),
                release.grant_message(grant))
            expiry=dt.datetime.fromisoformat(grant["expires_at"])
        except Exception as exc:
            raise RuntimeError("HISTORICAL_BROKER_SIGNATURE_INVALID") from exc
        require(expiry.tzinfo is not None and
                expiry<dt.datetime.now(dt.timezone.utc),
                "ACTIVE_OR_UNCERTAIN_RELEASE_GRANT_EXISTS")
        original=cli_json('$id="'+job+'";'
            '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
            '$c=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);'
            '$q=get_option(MAD4B_Broker_Remote_SSH_Receipt_V1::WPCLI_PREFIX.$id,false);'
            '$d=get_option(MAD4B_Dynamic_WP_313_Readback_V1::PROOF_PREFIX.$id,false);'
            'echo wp_json_encode(["job"=>$j,"claim"=>$c,"pending"=>$q,'
            '"dynamic_proof"=>$d]);')
        j=original.get("job")
        require(isinstance(j,dict) and
                j.get("job_id")==job and
                j.get("company")==plan["company"] and
                j.get("environment")==plan["environment"] and
                j.get("site_uuid")==plan["site_uuid"] and
                j.get("command_sha256")==grant["command_sha256"] and
                j.get("executed") is False and
                j.get("state") in (
                    "QUEUED_FOR_VPS_BROKER_CLAIM",
                    "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY") and
                type(j.get("expires_epoch")) is int and
                j["expires_epoch"]<int(time.time()),
                "HISTORICAL_JOB_NOT_VERIFIABLY_UNEXECUTED")
        require(not isinstance(original.get("pending"),dict) and
                not isinstance(original.get("dynamic_proof"),dict) and
                not (ROOT/"receipts"/(job+".json")).exists(),
                "HISTORICAL_ORIGINAL_313_EVIDENCE_EXISTS_RECONCILE_ONLY")
        reservation=ROOT/"reserved"/(job+".reserved")
        host_receipt=Path(policy["receipt_dir"])/(grant["nonce"]+".json")
        if reservation.exists():
            # An already-reserved command must NEVER be replayed.
            # Its first-party, exact-scope ROLLED_BACK receipt plus restored
            # preimages proves a *new* original 305 Job can be issued.
            # Anything uncertain remains held for reconciliation only.
            info=reservation.lstat()
            require(reservation.is_file() and not reservation.is_symlink()
                    and info.st_uid==0 and info.st_mode&0o077==0
                    and reservation.read_bytes()==(
                        "dynamic:company.code.release "+
                        grant["command_sha256"]+"\n").encode(),
                    "HISTORICAL_ORIGINAL_RESERVATION_NOT_PINNED")
            require(host_receipt.is_file() and not host_receipt.is_symlink()
                    and host_receipt.stat().st_uid==0
                    and host_receipt.stat().st_mode&0o077==0,
                    "HISTORICAL_APPLY_NOT_CERTIFIED_ROLLED_BACK")
            receipt=json.loads(host_receipt.read_text())
            require(
                receipt.get("contract")==release.RECEIPT_CONTRACT and
                receipt.get("state")=="ROLLED_BACK" and
                receipt.get("nonce")==grant["nonce"] and
                receipt.get("plan_sha256")==plan["plan_sha256"] and
                receipt.get("company")==plan["company"] and
                receipt.get("environment")==plan["environment"] and
                receipt.get("site_uuid")==plan["site_uuid"] and
                receipt.get("git_commit")==plan["git_commit"] and
                receipt.get("files")==[x["target"] for x in plan["files"]] and
                receipt.get("broker_grant_signature_verified") is True and
                receipt.get("original_313_receipt") is False and
                isinstance(receipt.get("failed_reason"),str) and
                len(receipt["failed_reason"])>0 and
                isinstance(original.get("claim"),dict) and
                original["claim"].get("job_id")==job and
                original["claim"].get("state")==
                    "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
                "HISTORICAL_ROLLBACK_FIRST_PARTY_RECEIPT_INCONSISTENT")
            # run_preflight() already enforces every exact before-hash;
            # do not delete this reservation, receipt, grant, or original Job.
        else:
            require(not host_receipt.exists(),
                    "HISTORICAL_HOST_RECEIPT_WITHOUT_RESERVATION")
    return len(previous)

def run_preflight()->tuple:
    require(os.geteuid()==0,"FIRST_PARTY_NATIVE_ROOT_OPERATOR_REQUIRED")
    policy=release.trusted_config(release.POLICY_PATH)
    p=json.loads(PLAN_FILE.read_text())
    release.confirm_plan(p,policy,expect_preimage=True)
    release.assert_runtime_binding(p,policy)
    index=registry.Registry(INDEX)
    manifest=index.resolve("company.code.release")
    require(manifest.get("version",0)>=3 and
            manifest.get("evidence_schema")=="code_release.v2",
            "ORIGINAL_DYNAMIC_RELEASE_PROVIDER_NOT_ENROLLED")
    d=index.command_sha256("company.code.release",p["plan_sha256"])
    for folder in (
        Path("/var/lib/mad4b/dynamic-operation-registry/plans"),
        Path("/var/lib/mad4b/company-code-release/plans")):
        local=folder/(p["plan_sha256"]+".json")
        info=local.lstat()
        require(info.st_uid==0 and info.st_mode&0o077==0 and
                json.loads(local.read_text())==p,
                "FIRST_PARTY_EXACT_RELEASE_PLAN_NOT_ENROLLED")
    require(GRANTS.is_dir() and GRANTS.stat().st_uid==0 and
            GRANTS.stat().st_mode&0o077==0,"ORIGINAL_GRANT_STORE_UNTRUSTED")
    key=signer()
    scope=admission(index,p,key)
    require(scope["command_sha256"]==d,"REGISTERED_COMMAND_SHA_CHANGED")
    # Expired original claims are immutable history, not reusable grants.
    # Only reissue if the actual Central 306 and Host 313 show *no* Apply.
    verified_historical=verify_expired_jobs_before_reissue(p,policy,key)
    return p,policy,index,key,scope

def verify_original_job(jobid:str,plan:dict,scope:dict)->dict:
    require(len(jobid)==32 and all(c in '0123456789abcdef' for c in jobid),
            "ORIGINAL_JOB_ID_INVALID")
    js=cli_json('$id="'+jobid+'";'
        '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
        '$r=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);'
        'echo wp_json_encode(["job"=>$j,"intake"=>$r]);')
    j=js["job"];i=js["intake"]
    require(isinstance(j,dict) and isinstance(i,dict) and
            j.get("job_id")==jobid and i.get("request_id")==jobid and
            j.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM" and
            i.get("status")=="ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY" and
            j.get("executed") is False and
            j.get("job_type")=="signed_vps_wpcli" and
            j.get("operation")=="company.remote.wpcli.full" and
            j.get("target_scope")=="wordpress" and
            j.get("command_format")=="wpcli.argv.json.v1" and
            j.get("company")==COMPANY and j.get("environment")==ENV and
            j.get("site_uuid")==plan["site_uuid"] and
            j.get("canonical_origin")==plan["origin"] and
            j.get("target_host_id")==HOST and
            j.get("grant_id")==scope["original_305_grant_id"] and
            j.get("transport_id")==scope["original_305_transport_id"] and
            j.get("central_principal_id")==scope["original_actor_id"] and
            j.get("command_sha256")==scope["command_sha256"] and
            i.get("command_sha256")==scope["command_sha256"] and
            j.get("sealed_argv_sha256")==i.get("sealed_argv_sha256") and
            isinstance(j.get("expires_epoch"),int) and
            j["expires_epoch"]>int(time.time())+300,
            "ORIGINAL_JOB_OR_SEALED_WPCLI_IDENTITY_CHANGED")
    return j

def root_atomic_new(path:Path,content:bytes)->None:
    require(not path.exists() and not path.is_symlink(),
            "ORIGINAL_RELEASE_GRANT_ALREADY_EXISTS")
    descriptor=os.open(path,os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    try:
        with os.fdopen(descriptor,"wb") as writer:
            writer.write(content);writer.flush();os.fsync(writer.fileno())
    except BaseException:
        path.unlink(missing_ok=True)
        raise

def issue_grant(job:dict,plan:dict,policy:dict,key:Ed25519PrivateKey)->dict:
    now=dt.datetime.now(dt.timezone.utc)
    remaining=max(1,int(job["expires_epoch"]-int(time.time())))
    require(remaining>300,"ORIGINAL_SIGNED_JOB_WINDOW_TOO_SHORT")
    expiry=now+dt.timedelta(seconds=min(remaining-120,1200))
    payload={
      "contract":release.AUTH_CONTRACT,
      "issuer":"mad4b-company-broker",
      "action":"apply",
      "company":plan["company"],
      "environment":plan["environment"],
      "site_uuid":plan["site_uuid"],
      "plan_sha256":plan["plan_sha256"],
      "original_job_id":job["job_id"],
      "original_305_grant_id":job["grant_id"],
      "original_305_transport_id":job["transport_id"],
      "command_sha256":job["command_sha256"],
      "origin":plan["origin"],
      "issued_at":now.isoformat(),
      "expires_at":expiry.isoformat(),
      "nonce":secrets.token_hex(16)
    }
    payload["signature"]=base64.b64encode(key.sign(release.grant_message(payload))).decode()
    release.verify_grant(plan,policy,payload,"apply")
    dest=GRANTS/(job["job_id"]+".json")
    root_atomic_new(dest,registry.canonical(payload)+b"\n")
    check=json.loads(dest.read_text())
    require(check==payload,"ORIGINAL_BROKER_GRANT_READBACK_FAILED")
    release.verify_grant(plan,policy,check,"apply")
    return {"grant_id":check["nonce"],"grant_expiry":check["expires_at"],
            "grant_file":str(dest)}

def main(execute:bool):
    p,policy,index,key,scope=run_preflight()
    state={"contract":"mad4b.first-party-original305-release-job.v1",
           "state":"ORIGINAL_305_EXACT_GRANT_PREFLIGHT_VERIFIED",
           "company":COMPANY,"environment":ENV,
           "plan_sha256":p["plan_sha256"],
           "command_sha256":scope["command_sha256"],
           "original_native_305_registered":True,
           "original_owner_standing_grant_verified":True,
           "original_broker_ed25519_verified":True,
           "original_signed_x25519_key_present":True,
           "job_issued":False,"release_grant_issued":False,
           "production_mutated_by_issuer":False}
    if not execute:return state
    correlation="mad4b-release-"+secrets.token_hex(12)
    body={"grant_id":scope["original_305_grant_id"],
          "argv":index.command_argv("company.code.release",p["plan_sha256"]),
          "summary":"Authorized first-party Company Code Release to Wayfa; require original 313 receipt",
          "correlation_id":correlation,
          "target_scope":"wordpress"}
    request=send("intake",body,scope,key)
    require(request.get("company")==COMPANY and
            request.get("command_sha256")==scope["command_sha256"] and
            request.get("status")=="ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY" and
            request.get("grant_id")==scope["original_305_grant_id"],
            "ORIGINAL_305_REQUEST_READBACK_UNCERTAIN")
    jid=request.get("request_id")
    state["original_305_request_id"]=jid
    state["state"]="ORIGINAL_305_REQUEST_ISSUED_PENDING_BROKER_DISPATCH"
    state["job_issued"]=True  # original 305 intake, not yet original 306 Job
    job=send("dispatch",{"request_id":jid},scope,key)
    require(job.get("job_id")==jid,"ORIGINAL_306_JOB_ID_MISMATCH")
    final=verify_original_job(jid,p,scope)
    state["state"]="ORIGINAL_306_JOB_QUEUED_AWAIT_SIGNED_RELEASE_GRANT"
    state["original_306_job_verified"]=True
    release_data=issue_grant(final,p,policy,key)
    state["release_grant_issued"]=True
    state["state"]="ORIGINAL_306_JOB_QUEUED_WITH_EXACT_BROKER_RELEASE_GRANT"
    state["release_grant_expires_at"]=release_data["grant_expiry"]
    # Original 306 Worker is scheduled independently: no direct apply or
    # receipt occurs in this issuer.
    return state

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    group=parser.add_mutually_exclusive_group(required=True)
    group.add_argument("--preflight",action="store_true")
    group.add_argument("--execute",action="store_true")
    args=parser.parse_args()
    try:
        with open(LOCK,"a+") as lock:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
            print(json.dumps(main(args.execute),sort_keys=True))
    except BaseException as exc:
        reason=str(exc)
        if not (reason.isascii() and len(reason)<=120 and
                all(c=='_' or c==':' or c.isupper() or c.isdigit() for c in reason)):
            reason=type(exc).__name__
        print(json.dumps({"contract":"mad4b.first-party-original305-release-job.v1",
                          "state":"HELD","reason":reason,
                          "production_mutated_by_issuer":False},sort_keys=True))
        raise SystemExit(2)
