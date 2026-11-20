#!/usr/bin/env python3
"""v120 read-only signed Broker Row → Original306/v119 admission bridge.

No real issuer, signer invocation, WordPress writes or Owner Request creation.
Runtime only trusts a fixed root-owned Broker witness, original v118 queued
preimage, enrolled original public key, fresh claimed WordPress originals and
the exact v119 admission implementation.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import time

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
PLAN_ROOT=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
HEX32=re.compile(r"[a-f0-9]{32}\Z")
CONTRACT="mad4b.original306.signed-broker-row-admission.v1"
PINNED={
  "v118":(
    ROOT/"host-authority-v118/queued_original_capture.py",
    "b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2"),
  "v119":(
    ROOT/"host-authority-v119/first_anchor_runner.py",
    "8ad2faf22c7ee9f72e7aa9ac8cf645d176a18a24ef4b21ab1bed6a3adb7ccff6"),
  "v120":(
    ROOT/"host-authority-v120/broker_row_witness.py",
    "336ef367637ce1edbef4debbec4317b3b839d3d71e2e3135e0690eb40d96331f"),
}
BROKER=ROOT.parent/"broker-v1/native-vps-client-principal.py"
BROKER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"

class SignedBrokerAdmissionHeld(ValueError):pass

def need(ok,why):
    if not ok:raise SignedBrokerAdmissionHeld(why)

def pinned(path,sha,name):
    need(path.is_file() and not path.is_symlink(),
        "V120_FIRST_PARTY_ORIGINAL_SOURCE_MISSING")
    st=path.lstat()
    need(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_gid==0
         and st.st_nlink==1 and not st.st_mode&0o077
         and hashlib.sha256(path.read_bytes()).hexdigest()==sha,
         "V120_FIRST_PARTY_ORIGINAL_SOURCE_PIN_CHANGED")
    spec=importlib.util.spec_from_file_location(name,path)
    need(spec is not None and spec.loader is not None,
         "V120_PINNED_SOURCE_IMPORT_FAILED")
    src=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(src)
    return src

def read_original_claimed(job_id,broker):
    need(os.geteuid()==0
        and isinstance(job_id,str) and HEX32.fullmatch(job_id),
        "V120_ROOT_AND_ORIGINAL_JOB_REQUIRED")
    # Current WordPress records come from the enrolled WPCLI principal,
    # without --user impersonation or any write call.
    php='''
$id="'''+job_id+'''";$c="all-royal-egypt";$e="staging";
$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);
$cl=get_option(MAD4B_Central_VPS_Broker_V1::CLAIM.$id,false);
$r=get_option(MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX.$id,false);
$g=is_array($r)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($r["grant_id"]??""),false):false;
$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$c."_".$e,false);
$p=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.($slot["grant_id"]??""),false):false;
$t=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.($slot["transport_id"]??""),false):false;
$a=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2($c,$e);
$active=is_array($p)&&is_array($t)&&MAD4B_Original_Remote_Hostinger_328_V1::active($t,$p);
echo wp_json_encode(["job"=>$j,"claim"=>$cl,"request"=>$r,"grant"=>$g,
 "parent"=>$p,"transport"=>$t,
 "site"=>["original328_active"=>$active,
 "anchor_option_present"=>get_option(MAD4B_Native_Host_Exact_Grant_V1::DYNAMIC_ANCHOR_PREFIX.$c."_".$e,false)!==false,
 "anchor_readback"=>$a]],JSON_UNESCAPED_SLASHES);
'''
    try:
        out=subprocess.run(broker.original_central_wp()+["eval",php],
          capture_output=True,text=True,timeout=30)
    except subprocess.TimeoutExpired as exc:
        raise SignedBrokerAdmissionHeld("V120_WORDPRESS_CLAIMED_READ_BUSY") from exc
    need(out.returncode==0,"V120_WORDPRESS_CLAIMED_ORIGINAL_READ_DENIED")
    lines=[row for row in out.stdout.splitlines() if row.startswith("{")]
    need(len(lines)==1 and len(lines[0])<50000,
        "V120_WORDPRESS_CLAIMED_ORIGINAL_AMBIGUOUS")
    try:original=json.loads(lines[0])
    except (ValueError,TypeError) as exc:
        raise SignedBrokerAdmissionHeld("V120_WORDPRESS_READBACK_JSON_INVALID") from exc
    need(type(original) is dict and
         set(original)=={"job","claim","request","grant","parent","transport","site"}
         and all(type(r) is dict for r in original.values()),
         "V120_WORDPRESS_ORIGINAL305_306_328_318_INCOMPLETE")
    return original

def admit_verified_broker_row(index,preimage,witness,claimed,claim,plan,current,
                              *,now,broker_pub,original_v120,original_v119):
    """Pure testable bridge, not an execution authorization.

    Callers in production MUST use enrolled Registry, original Broker public
    key and pinned v118/v119/v120 modules; no caller-provided proof or
    public key is accepted by the root-only live entrypoint.
    """
    need(os.geteuid()==0,"V120_ROOT_REQUIRED")
    row=original_v120.verified_broker_row(witness,preimage,broker_pub,now=now)
    result=original_v119.validate_readiness(
        index,preimage,claimed,claim,row,plan,current,now=now)
    need(result.get("state")=="CLAIM_READY_FOR_ROOT_313_ORIGINALS"
        and result.get("original306_claim_matched") is True
        and result.get("execution_authorized") is False
        and result.get("anchor_issued") is False
        and result.get("issuer_commissioned") is False,
        "V120_ORIGINAL306_CLAIM_NOT_INDEPENDENTLY_VERIFIED")
    return {
       "contract":CONTRACT,
       "job_id":result["job_id"],
       "state":"SIGNED_BROKER_ROW_AND_ORIGINAL306_CLAIM_VERIFIED",
       "original306_signed_broker_row_verified":True,
       "original305_328_and_original318_verified":True,
       "issuer_commissioned":False,
       "original313_enrollment_issued":False,
       "execution_authorized":False,
       "anchor_issued":False,
    }

def read_only_status(job_id):
    need(os.geteuid()==0 and isinstance(job_id,str)
         and HEX32.fullmatch(job_id),
         "V120_ROOT_EXACT_JOB_ID_REQUIRED")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry,checked_json
    index=Registry(REGISTRY)
    need(index.resolve("company.host-authority.anchor.issue").get(
         "execution_enabled") is False,
         "V120_GENERIC_EXECUTION_POLICY_MUST_REMAIN_DISABLED")
    v118=pinned(*PINNED["v118"],"_host_v120_bridge_v118")
    v120=pinned(*PINNED["v120"],"_host_v120_bridge_v120")
    v119=pinned(*PINNED["v119"],"_host_v120_bridge_v119")
    # Requiring both immutable root-only artifacts blocks preclaim-after-claim
    # reconstruction and excludes unsourced Broker Row dictionaries entirely.
    try:
        historical=v118.read_preimage(job_id)
        signed=v120.read_witness(job_id)
    except (ValueError,FileNotFoundError,OSError,KeyError):
        return {
          "contract":CONTRACT,
          "state":"HOLD_NO_SIGNED_ORIGINAL_BROKER_QUEUED_WITNESS",
          "original313_enrollment_issued":False,
          "anchor_issued":False,
          "wp_write_performed":False,
        }
    plan=checked_json(PLAN_ROOT/(historical["plan_sha256"]+".json"),PLAN_ROOT)
    broker=pinned(BROKER,BROKER_SHA,"_host_v120_bridge_original_central_wp")
    current=read_original_claimed(job_id,broker)
    admitted=admit_verified_broker_row(index,historical,signed,current["job"],
        current["claim"],plan,current,
        now=int(time.time()),
        broker_pub=index.host_anchor_312_public_key,
        original_v120=v120,original_v119=v119)
    return {**admitted,"wp_write_performed":False}

def main(argv=None):
    import argparse
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--job-id",required=True)
    parser.add_argument("--inspect",action="store_true")
    args=parser.parse_args(argv)
    try:
        result=read_only_status(args.job_id)
        status=2
    except (ValueError,TypeError,OSError,KeyError) as exc:
        message=str(exc)
        reason=message if re.fullmatch(r"[A-Z][A-Z0-9_]{5,120}",message) \
            else "V120_SIGNED_BROKER_ROW_READINESS_HELD"
        result={
            "contract":CONTRACT,
            "state":"HOLD",
            "reason":reason,
            "original313_enrollment_issued":False,
            "wp_write_performed":False,
            "anchor_issued":False,
        }
        status=2
    print(json.dumps(result,sort_keys=True))
    return status

if __name__=="__main__":
    raise SystemExit(main())
