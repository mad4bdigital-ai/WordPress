#!/usr/bin/env python3
"""Original312 issue documents. No WordPress execution or authority writes.

The root-only call takes an original306 claimed job and independently
Owner-approved enrollment via pinned v89. This module only returns a
Broker-signed Anchor and Root Revision event for separate Original313
prewrite approval; never issues the Anchor or final receipt itself.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat
from cryptography.exceptions import InvalidSignature

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
FIRST=ROOT/"host-authority-v89/first_anchor_original312.py"
FIRST_SHA="78d435b833cd91e409d2753a9086064266263eec79e431a3ffacbf7acce299f4"
PLAN=REGISTRY/"host-anchor-v2/operation_plan_contract.py"
PLAN_SHA="8438e3874905d75568bfc1c5c78bf5769a982834cae06a68d6aa111d080f6130"
BOOTSTRAP=REGISTRY/"host-anchor-v2/bootstrap_signed_job.py"
BOOTSTRAP_SHA="addef3e2693b5e87b2e8755f23bcc5d7fe8eeab0460640c1bc52e541340c61ed"
GATE=ROOT/"host-authority-v83/v81/current_revision_gate.py"
GATE_SHA="8d45990df6a1cdc75c2f3399f4490eea2c775466cc5de104f73c97d6903b2dd6"
FIELDS=("contract","company","environment","site_uuid","canonical_origin",
 "profile_digest","host_id","ssh_target","ssh_principal","ssh_root_sha256",
 "host_key_sha256","registry_sha256","policy_sha256","revision","issued",
 "expires","nonce","state","revoked","source")
ISSUE="company.host-authority.anchor.issue"
HEX32=re.compile(r"[a-f0-9]{32}\Z")

class IssueHeld(ValueError):pass

def need(ok,cause):
    if not ok:raise IssueHeld(cause)

def pinned(path,sha,name):
    need(path.is_file() and not path.is_symlink()
        and hashlib.sha256(path.read_bytes()).hexdigest()==sha,
        "ORIGINAL312_FIRST_PARTY_SOURCE_PIN_CHANGED")
    spec=importlib.util.spec_from_file_location(name,path)
    need(spec is not None and spec.loader is not None,
         "ORIGINAL312_FIRST_PARTY_SOURCE_IMPORT_FAILED")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def php_text(value):
    # WordPress uses (string) casts in signed field order.
    if value is False or value is None:return ""
    if value is True:return "1"
    need(type(value) in (str,int),"ORIGINAL312_PHP_SCALAR_INVALID")
    return str(value)

def anchor_body(row):
    need(list(row)==list(FIELDS)+["signature"],
         "ORIGINAL312_ANCHOR_SIGNED_ORDER_CHANGED")
    return "\n".join(php_text(row[k]) for k in FIELDS).encode()

def inspect_fresh_wp(snapshot,plan,job,now):
    need(type(snapshot) is dict and
         set(snapshot)=={"site","anchor_readback","original328_active"},
         "ORIGINAL312_FIRST_PARTY_SNAPSHOT_REQUIRED")
    site=snapshot["site"];anchor=snapshot["anchor_readback"]
    need(type(site) is dict and type(anchor) is dict
         and snapshot["original328_active"] is True
         and site.get("company")=="all-royal-egypt"
         and site.get("environment")=="staging"
         and site.get("site_uuid")==job["site_uuid"]
         and site.get("canonical_origin")==job["origin"]
         and site.get("profile_digest")==plan["profile_digest"]
         and anchor.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
         and anchor.get("source")=="original318_fresh_wp_readback"
         and anchor.get("company")==site["company"]
         and anchor.get("environment")==site["environment"]
         and anchor.get("state")=="MISSING_OR_INVALID"
         and anchor.get("revision")==0
         and anchor.get("readback_verified") is False
         and anchor.get("original313_certified") is False
         and anchor.get("write_authorized") is False
         and type(anchor.get("observed_epoch")) is int
         and now-15<=anchor["observed_epoch"]<=now+5,
         "ORIGINAL312_SITE_328_OR_ANCHOR_PRESTATE_DRIFT")

def signed_documents(plan,job,snapshot,bootstrap,signer,pub,*,now=None,nonce=None):
    """Pure signer helper. Only Root from_original_claim can use real key."""
    instant=int(time.time()) if now is None else now
    need(type(plan) is dict and type(job) is dict
         and type(bootstrap) is dict and type(pub) is bytes and len(pub)==32
         and callable(getattr(signer,"sign",None)),
         "ORIGINAL312_DOCS_OR_SIGNER_INVALID")
    need(signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)==pub,
         "ORIGINAL312_SIGNER_PUBKEY_DRIFT")
    need(job.get("operation_id")==ISSUE
         and job.get("company")=="all-royal-egypt"
         and job.get("environment")=="staging"
         and job.get("target_host_id")=="hosting-2"
         and job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
         and job.get("principal_type")=="signed_host_principal_v2"
         and job.get("state")=="BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and job.get("authority_mode")=="bootstrap"
         and job.get("host_v2_bootstrap_only") is True
         and job.get("executed") is False
         and type(job.get("expires_epoch")) is int
         and job["expires_epoch"]>=instant+30,
         "ORIGINAL312_EXACT_CLAIMED_FIRST_JOB_REQUIRED")
    pv=pinned(PLAN,PLAN_SHA,"_host_v100_plan")
    result=pv.verify_plan(plan,ISSUE)
    need(result["first_anchor_bootstrap"] is True
         and plan["grant_revision"]==0 and plan["anchor_revision"]==0
         and plan["requested_anchor_revision"]==1
         and all(plan.get(a)==job.get(b) for a,b in (
             ("company","company"),("environment","environment"),
             ("site_uuid","site_uuid"),("origin","origin"),
             ("target_host_id","target_host_id"),
             ("ssh_principal","target_ssh_principal"),
             ("plan_sha256","plan_sha256"))),
         "ORIGINAL312_SIGNED_ROOT_PLAN_JOB_MISMATCH")
    bv=pinned(BOOTSTRAP,BOOTSTRAP_SHA,"_host_v100_bootstrap")
    need(set(bootstrap)==set(bv.FIELDS)|{"signature"}
         and bootstrap["contract"]==bv.CONTRACT
         and bootstrap["job_id"]==job["job_id"]
         and bootstrap["operation_id"]==ISSUE
         and all(bootstrap.get(f)==job.get(j) for f,j in (
             ("company","company"),("environment","environment"),
             ("site_uuid","site_uuid"),("origin","origin"),
             ("target_host_id","target_host_id"),
             ("ssh_principal","target_ssh_principal"),
             ("command_sha256","command_sha256"),
             ("plan_sha256","plan_sha256")))
         and bootstrap["enrollment_id"]==plan["enrollment_id"]
         and bootstrap["nonce"]==plan["enrollment_nonce"]
         and bootstrap["requested_anchor_revision"]==1
         and type(bootstrap.get("issued_epoch")) is int
         and type(bootstrap.get("expires_epoch")) is int
         and instant-60<=bootstrap["issued_epoch"]<=instant+10
         and instant+20<=bootstrap["expires_epoch"]<=bootstrap["issued_epoch"]+60,
         "ORIGINAL312_FIRST_SIGNED_BOOTSTRAP_CHANGED")
    try:
        signature=base64.b64decode(bootstrap["signature"],validate=True)
        need(len(signature)==64,"ORIGINAL312_BOOTSTRAP_SIGNATURE_INVALID_LENGTH")
        Ed25519PublicKey.from_public_bytes(pub).verify(
            signature,bv.signed_message(bootstrap))
    except (InvalidSignature,ValueError,TypeError) as exc:
        raise IssueHeld("ORIGINAL312_BOOTSTRAP_SIGNATURE_INVALID") from exc
    inspect_fresh_wp(snapshot,plan,job,instant)
    n=secrets.token_hex(16) if nonce is None else nonce
    need(type(n) is str and HEX32.fullmatch(n) is not None,
         "ORIGINAL312_ANCHOR_NONCE_INVALID")
    anchor={
      "contract":"mad4b.company-host-authority-anchor.v2",
      "company":"all-royal-egypt","environment":"staging",
      "site_uuid":job["site_uuid"],
      "canonical_origin":job["origin"],
      "profile_digest":plan["profile_digest"],
      "host_id":"srv2030908",
      "ssh_target":"hosting-2",
      "ssh_principal":job["target_ssh_principal"],
      "ssh_root_sha256":plan["ssh_root_sha256"],
      "host_key_sha256":plan["host_key_sha256"],
      "registry_sha256":plan["registry_sha256"],
      "policy_sha256":plan["policy_sha256"],
      "revision":1,"issued":instant,"expires":instant+900,
      "nonce":n,"state":"ACTIVE_INDEPENDENT_HOST_AUTHORITY",
      "revoked":False,
      "source":"original_approved_host_authority_reconcile",
    }
    need(list(anchor)==list(FIELDS),
         "ORIGINAL312_ANCHOR_WORDPRESS_FIELD_ORDER_MISMATCH")
    body="\n".join(php_text(anchor[f]) for f in FIELDS).encode("utf-8")
    anchor["signature"]=base64.b64encode(signer.sign(body)).decode()
    need(anchor_body(anchor)==body,"ORIGINAL312_WORDPRESS_ANCHOR_BODY_DRIFT")
    Ed25519PublicKey.from_public_bytes(pub).verify(
        base64.b64decode(anchor["signature"],validate=True),body)
    gate=pinned(GATE,GATE_SHA,"_host_v100_ledger_gate")
    ledger={
      "contract":gate.LEDGER,
      "company":"all-royal-egypt","environment":"staging",
      "site_uuid":job["site_uuid"],"target_host_id":"hosting-2",
      "ssh_principal":job["target_ssh_principal"],
      "revision":1,"grant_revision":0,
      "anchor_nonce":n,"state":"ACTIVE",
      "event_id":job["job_id"],
      "previous_event_sha256":"0"*64,
      "issued_epoch":instant,"expires_epoch":instant+45,
    }
    need(list(ledger)==list(gate.FIELDS),
         "ORIGINAL312_SIGNED_LEDGER_SCHEMA_MISMATCH")
    ledger["signature"]=base64.b64encode(signer.sign(
        gate.body(ledger,gate.FIELDS,
                  "MAD4B_ORIGINAL_HOST_LEDGER_V1"))).decode()
    gate.sigcheck(ledger,gate.FIELDS,gate.LEDGER,
                  "MAD4B_ORIGINAL_HOST_LEDGER_V1",pub,instant)
    return {
       "contract":"mad4b.original312.first-anchor-issue-documents.v1",
       "job_id":job["job_id"],"operation_id":ISSUE,
       "bootstrap":bootstrap,"anchor":anchor,"revision_event":ledger,
       "anchor_message_sha256":hashlib.sha256(body).hexdigest(),
       "original312_signatures_verified":True,
       "original318_write_performed":False,
       "original313_prewrite_approved":False,
       "original313_final_receipt_signed":False,
       "anchor_issued":False,"execution_authorized":False,
    }

def fresh_original_wp_snapshot(signer_source):
    """Read ONLY, from central WP site, not an authenticated write session."""
    php='''
$c="all-royal-egypt";$e="staging";
$p=MAD4B_Company_Environment_Registry_V2::target($c,$e);
$a=MAD4B_Native_Host_Exact_Grant_V1::current_anchor_readback_v2($c,$e);
$slot=get_option(MAD4B_Company_Native_WPCLI_Enrollment_V1::SLOT.$c."_".$e,false);
$parent=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::PREFIX.
    (string)($slot["grant_id"]??""),false):false;
$transport=is_array($slot)?get_option(MAD4B_Central_SSH_Command_Intake_V1::TRANSPORT_PREFIX.
    (string)($slot["transport_id"]??""),false):false;
$active=is_array($parent)&&is_array($transport)&&
    MAD4B_Original_Remote_Hostinger_328_V1::active($transport,$parent);
echo wp_json_encode(["site"=>[
 "company"=>$p["company_id"]??null,"environment"=>$p["environment"]??null,
 "site_uuid"=>$p["site_uuid"]??null,"canonical_origin"=>$p["canonical_origin"]??null,
 "profile_digest"=>$p["identity_evidence"]["profile_digest"]??null],
 "anchor_readback"=>$a,"original328_active"=>$active],
 JSON_UNESCAPED_SLASHES);
'''
    run=subprocess.run(signer_source.original_central_wp()+["eval",php],
        capture_output=True,text=True,timeout=38)
    need(run.returncode==0,"ORIGINAL312_CENTRAL_WPCLI_READ_FAILED")
    lines=[x for x in run.stdout.splitlines() if x.startswith("{")]
    need(len(lines)==1,"ORIGINAL312_WORDPRESS_READBACK_NOT_UNIQUE")
    return json.loads(lines[0])

def from_original_claim(index,plan,job,claim,broker_row):
    """Only first-party Root Worker may invoke; module has no CLI endpoint."""
    need(os.geteuid()==0,"ORIGINAL312_FIRST_PARTY_ROOT_REQUIRED")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry
    need(type(index) is Registry and index.root==REGISTRY,
         "ORIGINAL312_ENROLLED_REGISTRY_REQUIRED")
    first=pinned(FIRST,FIRST_SHA,"_host_v100_original_first_anchor")
    signer_source=pinned(first.ORIGINAL_SIGNER_SOURCE,
        first.ORIGINAL_SIGNER_SHA256,"_host_v100_original_signer")
    snapshot=fresh_original_wp_snapshot(signer_source)
    inspect_fresh_wp(snapshot,plan,job,int(time.time()))
    # v89 validates exact Original306 Claim, signed Owner enrollment and
    # Root plan before producing the original Broker bootstrap signature.
    bootstrap=first.sign_original306_first_anchor(
        index,plan,job,claim,broker_row)
    signer=signer_source.original_signer()
    signer_source.check_original_wordpress_signer(signer)
    pub=signer.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    need(pub==index.host_anchor_312_public_key
         and pub!=index.independent_verifier_public_key
         and pub!=index.host_resource_312_public_key,
         "ORIGINAL312_SEPARATE_BROKER_SIGNER_NOT_PINNED")
    return signed_documents(plan,job,snapshot,bootstrap,signer,pub)

def main():
    print(json.dumps({
      "state":"HOLD",
      "reason":"ORIGINAL_OWNER_APPROVED_CLAIM_AND_SIGNED_ENROLLMENT_REQUIRED",
      "anchor_issued":False,"original313_final_receipt_signed":False,
      "original312_signer_called":False},sort_keys=True))
    return 2

if __name__=="__main__":
    raise SystemExit(main())
