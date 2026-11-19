#!/usr/bin/env python3
"""Enroll first-party Code Release Host Policy, immutable plan, private receipts.

Strict original Broker key parity; no WordPress Job, signed Broker Grant,
Company Code Release apply, rollback or original 313 receipt created here.
"""
from __future__ import annotations
import argparse
import base64
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import tempfile
sys.path.insert(0,str(Path(__file__).resolve().parents[1]))
import company_code_release as code

ROOT=Path(__file__).resolve().parents[1]
REPORT=Path("/opt/mad4b-company-provisioning/reports/company-code-release-git-local-20261108")
PREVIEW=REPORT/"preview-policy-environment-neutral-NOT-INSTALLED.json"
PLAN=REPORT/"wayfa-plan-brand-copy-reconciled.json"
POLICY=code.POLICY_PATH
PLANS=Path("/var/lib/mad4b/company-code-release/plans")
GRANTS=Path("/var/lib/mad4b/company-code-release/grants")
BROKER_PUB=Path("/opt/mad4b-company-transport/broker-v1/host-ed25519-public.base64")
RECEIPTS=Path("/var/lib/mad4b/company-code-release/receipts")

def require(ok:bool,msg:str):
    if not ok:raise code.Denied(msg)

def private_dir(path:Path):
    code.no_link(path)
    if not path.exists():path.mkdir(mode=0o700,parents=True)
    info=path.stat()
    require(path.is_dir() and info.st_uid==0 and
            not stat.S_IMODE(info.st_mode)&0o077,
            "ROOT_CODE_RELEASE_DIRECTORY_NOT_PRIVATE")

def atomic(path:Path,raw:bytes):
    private_dir(path.parent)
    require(not path.exists() and not path.is_symlink(),
            "FIRST_PARTY_FILE_ALREADY_EXISTS")
    fd,temp=tempfile.mkstemp(prefix=".first-party-code-release-",dir=path.parent)
    try:
        os.fchmod(fd,0o600)
        with os.fdopen(fd,"wb") as f:
            f.write(raw);f.flush();os.fsync(f.fileno())
        os.link(temp,path)
        d=os.open(path.parent,os.O_DIRECTORY)
        try:os.fsync(d)
        finally:os.close(d)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def preflight():
    require(os.geteuid()==0,"FIRST_PARTY_HOST_ROOT_REQUIRED")
    require(not POLICY.exists(),"HOST_POLICY_ALREADY_ENROLLED")
    base=json.loads(PREVIEW.read_text())
    plan=json.loads(PLAN.read_text())
    require(base["contract"]==code.POLICY_CONTRACT and
            base.get("preview_only_no_authorization") is True,
            "SOURCE_POLICY_NOT_MARKED_PREVIEW")
    allowed={"wayfatours:production"}
    require(set(base["targets"])==allowed and
            base["targets"]["wayfatours:production"]["site_uuid"]==plan["site_uuid"],
            "HOST_POLICY_SCOPE_NOT_PINNED")
    pubinfo=BROKER_PUB.lstat()
    require(stat.S_ISREG(pubinfo.st_mode) and pubinfo.st_uid==0 and
            not stat.S_IMODE(pubinfo.st_mode)&0o077,
            "ORIGINAL_BROKER_PUBLIC_KEY_UNTRUSTED")
    public=BROKER_PUB.read_text().strip()
    require(len(base64.b64decode(public,validate=True))==32,
            "BROKER_PUBKEY_INVALID")
    result=subprocess.run(
        ["docker","exec","-u","www-data","php-wkgtkzta35wkkgouwt6ruljc",
         "wp","--path=/var/www/html","--skip-themes","eval",
         "echo get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,'');"],
        capture_output=True,text=True,timeout=50,check=True)
    require(public==result.stdout.strip(),"ORIGINAL_CENTRAL_BROKER_KEY_DIFFERS")
    policy={key:base[key] for key in
            ("contract","source_git_root","runtime_registry","targets","receipt_dir")}
    policy["broker_public_key_b64"]=public
    code.checked_policy(policy)
    code.confirm_plan(plan,policy,expect_preimage=True)
    code.assert_runtime_binding(plan,policy)
    require(str(PLANS)!=str(RECEIPTS) and not (PLANS/(
        plan["plan_sha256"]+".json")).exists(),
        "FIRST_PARTY_CODE_RELEASE_PLAN_ALREADY_PRESENT")
    return policy,plan

def run(apply=False):
    policy,plan=preflight()
    status={"state":"POLICY_AND_EXACT_PLAN_PREFLIGHT_PASSED_NOT_INSTALLED",
            "scope":plan["company"]+":"+plan["environment"],
            "plan_sha256":plan["plan_sha256"],
            "original_broker_pubkey_matched":True,
            "git_preimage_valid":True,
            "target_runtime_valid":True,
            "policy_enrolled":False,"original_job_issued":False,
            "broker_grant_issued":False,"production_mutated":False}
    if not apply:return status
    private_dir(RECEIPTS)
    private_dir(PLANS)
    private_dir(GRANTS)
    encoded_plan=code.canonical(plan)+b"\n"
    atomic(PLANS/(plan["plan_sha256"]+".json"),encoded_plan)
    # Policy published LAST, after immutable plan and directories exist.
    atomic(POLICY,code.canonical(policy)+b"\n")
    loaded=code.trusted_config(POLICY)
    require(loaded==policy,"PINNED_HOST_POLICY_READBACK_CHANGED")
    code.confirm_plan(plan,loaded,expect_preimage=True)
    code.assert_pinned_receipt_directory(loaded,RECEIPTS)
    code.assert_runtime_binding(plan,loaded)
    require(not list(GRANTS.glob("*.json")),"GRANT_STORE_NOT_EMPTY")
    status["state"]="FIRST_PARTY_PINNED_HOST_POLICY_AND_PLAN_INSTALLED"
    status["policy_enrolled"]=True
    status["root_receipts_ready"]=True
    return status

if __name__=="__main__":
    parser=argparse.ArgumentParser()
    g=parser.add_mutually_exclusive_group(required=True)
    g.add_argument("--check",action="store_true")
    g.add_argument("--install",action="store_true")
    a=parser.parse_args()
    print(json.dumps(run(apply=a.install),sort_keys=True))
