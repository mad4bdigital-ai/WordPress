#!/usr/bin/env python3
"""Publish a signed, scope-restricted Host v121 *readiness* receipt.

This status is not Owner authorization or Host Anchor issuance. It is
signed by original Broker key only after Root-scoped commissioning, installed
worker SHA, original signer identity, and fresh pristine Original318 readback
are verified. No arbitrary CLI args, paths, principal impersonation, grants
or authority changes; the only WordPress mutation is the signed status option.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time
import secrets

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
WORKER=ROOT/"claimed-job-worker.py"
RUNNER=ROOT/"host-authority-v121/first_anchor_coordinator.py"
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
BROKER=ROOT.parent/"broker-v1/native-vps-client-principal.py"
WORKER_SHA="c3cd37bde507c34c472a3aecc75208841b4355290b284fc9526f6c8e19794ca9"
RUNNER_SHA="b5aa997abf39bdfe5027bf06da5a7e137ee1f3801dc91035c2e1f4907f0b3ba5"
BROKER_SHA="ebb4971efef114e5b984a9c9ea60d46b5fe7250db9991b5d94cd61708651dc26"
CONTRACT="mad4b.host-authority-scoped-root-readiness.v121"
OPTION="mad4b_host_authority_v121_scoped_readiness_v1"
DOMAIN="MAD4B_HOST_AUTHORITY_V121_SCOPED_READINESS\n"
FIELDS=(
 "contract","company","environment","host","runner_sha256",
 "worker_sha256","issuer","mode","issued_epoch","expires_epoch","nonce"
)
class ReadinessHeld(ValueError):pass
def require(ok,reason):
    if not ok:raise ReadinessHeld(reason)
def digest(b):
    return hashlib.sha256(b).hexdigest()

def pinned(path,sha,label):
    require(os.geteuid()==0 and path.is_file()
       and not path.is_symlink(),label+"_MISSING")
    st=path.lstat()
    # The original first-party Broker signer is a root-owned executable
    # 0700. All newly installed v121 sources are root-only 0600.
    expected_mode=0o700 if path==BROKER else 0o600
    require(st.st_uid==0 and st.st_gid==0 and st.st_nlink==1
       and (st.st_mode & 0o777)==expected_mode
       and digest(path.read_bytes())==sha,label+"_DRIFT")
    sp=importlib.util.spec_from_file_location(label,path)
    require(sp is not None and sp.loader is not None,
            label+"_IMPORT_DENIED")
    m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
    return m

def signed_receipt(key,now):
    from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
    require(type(now) is int and now>=1600000000,
            "V121_STATUS_EPOCH_INVALID")
    body={
        "contract":CONTRACT,
        "company":"all-royal-egypt","environment":"staging",
        "host":"hosting-2",
        "runner_sha256":RUNNER_SHA,"worker_sha256":WORKER_SHA,
        "issuer":"first-party-root-only",
        "mode":"signed-first-anchor-only",
        "issued_epoch":now,"expires_epoch":now+21600,
        "nonce":secrets.token_hex(16)
    }
    require(tuple(body)==FIELDS,"V121_STATUS_PHP_FIELD_SCHEMA_DRIFT")
    sig=key.sign((DOMAIN+"\n".join(str(body[k]) for k in FIELDS)).encode())
    body["signature"]=base64.b64encode(sig).decode()
    return body

def php_source(payload):
    """Fixed option/name/verification, only receipt base64 passed as data."""
    encoded=base64.b64encode(json.dumps(payload,
      separators=(",",":"),ensure_ascii=False).encode()).decode()
    return '''$c="all-royal-egypt";$e="staging";
$key="mad4b_host_authority_v121_scoped_readiness_v1";
$expected="''' + RUNNER_SHA + '''";
$worker="''' + WORKER_SHA + '''";
$raw=base64_decode("''' + encoded + '''",true);
$x=is_string($raw)?json_decode($raw,true):false;
$names=["contract","company","environment","host","runner_sha256",
"worker_sha256","issuer","mode","issued_epoch","expires_epoch","nonce",
"signature"];
if(!is_array($x)||array_keys($x)!==$names
 ||($x["contract"]??null)!=="mad4b.host-authority-scoped-root-readiness.v121"
 ||($x["company"]??null)!==$c||($x["environment"]??null)!==$e
 ||($x["host"]??null)!=="hosting-2"
 ||($x["runner_sha256"]??null)!==$expected
 ||($x["worker_sha256"]??null)!==$worker
 ||($x["issuer"]??null)!=="first-party-root-only"
 ||($x["mode"]??null)!=="signed-first-anchor-only"
 ||!is_int($x["issued_epoch"]??null)
 ||!is_int($x["expires_epoch"]??null)
 ||$x["issued_epoch"]>time()+10||$x["issued_epoch"]<time()-90
 ||$x["expires_epoch"]<=time()+600
 ||$x["expires_epoch"]>$x["issued_epoch"]+21600
 ||!preg_match("/^[a-f0-9]{32}$/D",(string)($x["nonce"]??"")))
  WP_CLI::error("HOST_V121_SCOPED_STATUS_SCHEMA_REJECTED");
$pub=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,""),true);
$sig=base64_decode((string)$x["signature"],true);
$values=[];
foreach(array_slice($names,0,-1) as $name)$values[]=(string)$x[$name];
$msg="MAD4B_HOST_AUTHORITY_V121_SCOPED_READINESS\\n".implode("\\n",$values);
if(!is_string($pub)||strlen($pub)!==32
 ||!is_string($sig)||strlen($sig)!==64
 ||!sodium_crypto_sign_verify_detached($sig,$msg,$pub))
 WP_CLI::error("HOST_V121_SCOPED_STATUS_SIGNATURE_REJECTED");
$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$c."_".$e,false);
$parent=is_array($slot)?get_option(
 MAD4B_Central_SSH_Command_Intake_V1::PREFIX.(string)($slot["grant_id"]??""),false):false;
$transport=is_array($slot)?get_option(
 MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.(string)($slot["transport_id"]??""),false):false;
$ready=is_array($parent)&&is_array($transport)&&
 MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$parent);
$anchor=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2($c,$e);
if(!$ready||($anchor["state"]??null)!=="MISSING_OR_INVALID"
 ||($anchor["revision"]??null)!==0
 ||($anchor["original313_certified"]??null)!==false
 ||($anchor["write_authorized"]??null)!==false)
 WP_CLI::error("HOST_V121_ORIGINAL328_OR_ANCHOR_PRISTINE_REQUIRED");
if(!update_option($key,$x,false))
  WP_CLI::error("HOST_V121_STATUS_OPTION_WRITE_NOT_CONFIRMED");
$readback=get_option($key,false);
if(!is_array($readback)||$readback!==$x)
 WP_CLI::error("HOST_V121_STATUS_OPTION_READBACK_CHANGED");
echo wp_json_encode(["state"=>"SIGNED_SCOPED_ROOT_READINESS_PUBLISHED",
 "expires_epoch"=>$x["expires_epoch"],
 "revision"=>$anchor["revision"]],JSON_UNESCAPED_SLASHES);'''

def publish():
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    index=Registry(REGISTRY)
    require(index.resolve("company.host-authority.anchor.issue")
         .get("execution_enabled") is False,
         "V121_STATUS_GENERIC_REGISTRY_MUST_STAY_DISABLED")
    runner=pinned(RUNNER,RUNNER_SHA,"V121_STATUS_RUNNER")
    runner.root_gate()
    runner.root_file(WORKER,WORKER_SHA,size=120000)
    broker=pinned(BROKER,BROKER_SHA,"V121_STATUS_ORIGINAL_BROKER")
    state=runner.read_wp_anchor()
    observed=state.get("anchor_readback",{})
    require(state.get("original328_active") is True
        and observed.get("state")=="MISSING_OR_INVALID"
        and observed.get("revision")==0
        and observed.get("original313_certified") is False
        and observed.get("write_authorized") is False,
        "V121_STATUS_FRESH_ORIGINAL328_AND_REVISION_0_REQUIRED")
    signer=broker.original_signer()
    broker.check_original_wordpress_signer(signer)
    from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
    require(signer.public_key().public_bytes(
       Encoding.Raw,PublicFormat.Raw)==index.host_anchor_312_public_key,
       "V121_STATUS_ORIGINAL_BROKER_SIGNER_NOT_ENROLLED")
    signed=signed_receipt(signer,int(time.time()))
    proof=php_source(signed)
    try:
        run=subprocess.run(broker.original_central_wp()+["eval",proof],
          text=True,capture_output=True,timeout=35)
    except subprocess.TimeoutExpired as e:
        raise ReadinessHeld("V121_STATUS_WPCLI_WRITE_AMBIGUOUS_READBACK_REQUIRED") from e
    require(run.returncode==0,
        "V121_STATUS_WPCLI_SOURCE_REJECTED")
    rows=[x for x in run.stdout.splitlines() if x.startswith("{")]
    require(len(rows)==1 and len(rows[0])<3000,
        "V121_STATUS_WORDPRESS_READBACK_MISSING")
    result=json.loads(rows[0])
    require(result.get("state")==
        "SIGNED_SCOPED_ROOT_READINESS_PUBLISHED"
        and result.get("revision")==0,
        "V121_STATUS_WORDPRESS_READBACK_MISMATCH")
    return result

def main():
    try:
        response=publish()
        print(json.dumps(response,sort_keys=True))
        return 0
    except (ReadinessHeld,ValueError,TypeError,OSError,KeyError) as e:
        reason=str(e)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{5,130}",reason):
            reason="V121_SCOPED_READINESS_HOLD"
        print(json.dumps({"state":"HOLD","reason":reason,
            "owner_request_created":False,"anchor_issued":False},
            sort_keys=True))
        return 2

if __name__=="__main__":
    raise SystemExit(main())
