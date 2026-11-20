#!/usr/bin/env python3
"""v123 independent *existing* signed Final Receipt publication (Staging only).

No new signing, owner approvals, grants, anchors, or execution. Verify three
original signed Root records against enrolled original Ed25519 public keys and
current original318 readback. WordPress stores a display-only certificate
bundle and verifies all its original signatures again in the owner UI.
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
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
RUNNER=ROOT/"host-authority-v121/first_anchor_coordinator.py"
RUNNER_SHA="a4e7dad6638be30a492bf27202d99bb27d2b74a2cfc116fbe17269da4fc2a6b3"
JOB="5b21b7aae2de8e3ef9342ae2ceaa2802"
SCOPE="all-royal-egypt--staging.json"
CONTRACT="mad4b.original313.host-anchor-final-owner-display.v123"
OPTION="mad4b_host_authority_first_final_certificate_v123"
OP="company.host-authority.anchor.issue"

class CertificateHeld(ValueError):pass
def need(valid,code):
    if not valid:raise CertificateHeld(code)
def canonical(d):
    return json.dumps(d,sort_keys=True,separators=(",",":"),ensure_ascii=False).encode()

def read_verified_originals():
    need(os.geteuid()==0 and RUNNER.is_file() and not RUNNER.is_symlink()
         and hashlib.sha256(RUNNER.read_bytes()).hexdigest()==RUNNER_SHA,
         "V123_RUNNER_SOURCE_SHA_DRIFT")
    spec=importlib.util.spec_from_file_location("_v123_original121",RUNNER)
    need(spec is not None and spec.loader is not None,"V123_ROOT_RUNNER_UNAVAILABLE")
    co=importlib.util.module_from_spec(spec);spec.loader.exec_module(co)
    if str(co.REGISTRY) not in sys.path:sys.path.insert(0,str(co.REGISTRY))
    from registry import Registry
    index=Registry(co.REGISTRY)
    need(index.resolve(OP).get("execution_enabled") is False,
         "V123_GENERIC_HOST_EXECUTION_NOT_CLOSED")
    v99=co.module("v99")
    store=v99.PrivateStore()
    revision=store.read(store.path("revisions",SCOPE))
    checkpoint=store.read(store.path("checkpoints",SCOPE))
    receipt=store.read(store.path("receipts",JOB+".json"))
    need(all(type(x) is dict for x in (revision,checkpoint,receipt)),
         "V123_ORIGINAL_SIGNED_RECORDS_NOT_COMPLETE")
    now=int(time.time())
    gate=v99.load_gate()
    gate.sigcheck(revision,gate.FIELDS,gate.LEDGER,
        "MAD4B_ORIGINAL_HOST_LEDGER_V1",
        index.host_anchor_312_public_key,now,historical=True)
    gate.sigcheck(checkpoint,gate.C_FIELDS,gate.CHECKPOINT,
        "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1",
        index.independent_verifier_public_key,now,historical=True)
    need(set(receipt)==set(v99.RECEIPT_FIELDS)|{"signature"}
        and receipt["contract"]==v99.RECEIPT
        and receipt["job_id"]==JOB
        and receipt["operation_id"]==OP
        and revision["event_id"]==JOB
        and all(r["company"]=="all-royal-egypt"
                and r["environment"]=="staging"
                and r["revision"]==1
                and r["state"]=="ACTIVE" for r in (revision,checkpoint,receipt))
        and revision["target_host_id"]=="hosting-2"
        and revision["site_uuid"]==checkpoint["site_uuid"]==receipt["site_uuid"]
        and revision["anchor_nonce"]==checkpoint["anchor_nonce"]==receipt["anchor_nonce"]
        and checkpoint["event_sha256"]==receipt["event_sha256"]
        and receipt["event_sha256"]==hashlib.sha256(gate.body(revision,gate.FIELDS,
             "MAD4B_ORIGINAL_HOST_LEDGER_V1")).hexdigest()
        and receipt["checkpoint_sha256"]==hashlib.sha256(gate.body(
             checkpoint,gate.C_FIELDS,
             "MAD4B_ORIGINAL_HOST_CHECKPOINT_V1")).hexdigest(),
        "V123_ROOT_SIGNED_LEDGER_AND_RECEIPT_LINK_MISMATCH")
    try:
        sig=base64.b64decode(receipt["signature"],validate=True)
        need(len(sig)==64,"V123_ORIGINAL313_RECEIPT_SIG_SIZE")
        Ed25519PublicKey.from_public_bytes(index.independent_verifier_public_key).verify(
            sig,b"MAD4B_ORIGINAL313_HOST_FINAL_RECEIPT_V1\n"+
            canonical({k:receipt[k] for k in v99.RECEIPT_FIELDS}))
    except (ValueError,TypeError,InvalidSignature) as exc:
        raise CertificateHeld("V123_ORIGINAL313_RECEIPT_SIGNATURE_WRONG") from exc
    snap=co.read_wp_anchor()
    wp=snap["anchor_readback"]
    need(snap.get("original328_active") is True
        and type(wp) is dict
        and wp.get("state")=="ACTIVE"
        and wp.get("revision")==1
        and wp.get("readback_verified") is True
        and wp.get("anchor_nonce")==receipt["anchor_nonce"]
        and wp.get("signed_anchor_message_sha256")==receipt["wp_signed_message_sha256"],
        "V123_LIVE_ORIGINAL318_NOT_BINDING_TO_RECEIPT")
    return {
        "contract":CONTRACT,
        "job_id":JOB,
        "revision_event":revision,
        "checkpoint":checkpoint,
        "receipt":receipt,
    },co

def php_update_code(bundle):
    encoded=base64.b64encode(canonical(bundle)).decode()
    return '''$key="mad4b_host_authority_first_final_certificate_v123";
$raw=base64_decode("'''+encoded+'''",true);
$data=is_string($raw)?json_decode($raw,true):false;
if(!is_array($data)||array_keys($data)!==
 ["checkpoint","contract","job_id","receipt","revision_event"]
 ||($data["contract"]??null)!=="mad4b.original313.host-anchor-final-owner-display.v123"
 ||($data["job_id"]??null)!=="5b21b7aae2de8e3ef9342ae2ceaa2802")
 WP_CLI::error("V123_CERTIFICATE_EXACT_BUNDLE_REQUIRED");
$a=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2(
 "all-royal-egypt","staging");
if(($a["state"]??null)!=="ACTIVE"
 ||($a["revision"]??null)!==1
 ||($a["readback_verified"]??null)!==true
 ||($a["anchor_nonce"]??null)!==($data["receipt"]["anchor_nonce"]??null)
 ||($a["signed_anchor_message_sha256"]??null)!==
    ($data["receipt"]["wp_signed_message_sha256"]??null))
 WP_CLI::error("V123_LIVE_ANCHOR_RECEIPT_BINDING_REJECTED");
$old=get_option($key,false);
if($old!==false){
 if($old!==$data)WP_CLI::error("V123_CERTIFICATE_IMMUTABLE_OPTION_DRIFT");
 echo wp_json_encode(["state"=>"EXISTING_VERIFIED_CERTIFICATE_UNCHANGED",
   "revision"=>1,"job_id"=>$data["job_id"]]);return;
}
if(!add_option($key,$data,"","no"))
 WP_CLI::error("V123_IMMUTABLE_CERTIFICATE_INSERT_REJECTED");
if(get_option($key,false)!==$data)
 WP_CLI::error("V123_IMMUTABLE_CERTIFICATE_READBACK_REJECTED");
echo wp_json_encode(["state"=>"SIGNED_FINAL_CERTIFICATE_PUBLISHED",
 "revision"=>1,"job_id"=>$data["job_id"]]);'''

def publish():
    bundle,co=read_verified_originals()
    broker=co.module("v105").pinned(
      co.BROKER,co.BROKER_SHA,"_v123_original_wpcli_transport")
    cmd=broker.original_central_wp()+["eval",php_update_code(bundle)]
    try:
        result=subprocess.run(cmd,capture_output=True,text=True,timeout=35)
    except subprocess.TimeoutExpired as exc:
        raise CertificateHeld("V123_CERTIFICATE_PUBLISH_RESULT_AMBIGUOUS") from exc
    need(result.returncode==0,"V123_CERTIFICATE_WORDPRESS_REJECTED")
    lines=[line for line in result.stdout.splitlines() if line.startswith("{")]
    need(len(lines)==1,"V123_CERTIFICATE_WORDPRESS_READBACK_NOT_UNIQUE")
    result=json.loads(lines[0])
    need(result.get("state") in (
        "SIGNED_FINAL_CERTIFICATE_PUBLISHED",
        "EXISTING_VERIFIED_CERTIFICATE_UNCHANGED")
        and result.get("revision")==1 and result.get("job_id")==JOB,
        "V123_CERTIFICATE_WORDPRESS_CONFIRMATION_INVALID")
    return result

def main():
    try:
        output=publish()
        print(json.dumps(output,sort_keys=True))
        return 0
    except (CertificateHeld,ValueError,TypeError,KeyError,OSError) as e:
        reason=str(e)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{5,140}",reason):
            reason="V123_FIRST_ANCHOR_CERTIFICATE_HELD"
        print(json.dumps({"state":"HOLD","reason":reason,
            "owner_request_created":False,
            "host_execution_performed":False,
            "anchor_mutated":False},sort_keys=True))
        return 2

if __name__=="__main__":
    raise SystemExit(main())
