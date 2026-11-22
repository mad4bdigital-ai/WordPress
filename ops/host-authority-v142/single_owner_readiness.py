#!/usr/bin/env python3
"""MAD4B Single Owner v3: unattended *read-only* first-party readiness monitor.

The sole-owner model is an owner preference, NEVER a signed identity or grant.
This process reads independent Root sources and can publish only a private
diagnostic JSON file. It cannot enroll/sign/deploy/grant or replay Original305.
"""
from __future__ import annotations
import argparse
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import sys
import time

BASE=Path("/opt/mad4b-company-transport")
V132=BASE/"host-authority-v132-original305-readonly/readonly-root-original305-v3-preflight.py"
V134=BASE/"host-authority-v134-original313-readonly/readonly-original313-archive-census.py"
V133=BASE/"host-authority-v133-v3-issuer-standby/INSTALL-MANIFEST.json"
V2_ARCHIVE=Path("/var/lib/mad4b/original-host-v2")
INDEX=BASE/"native-313-independent/dynamic-operation-registry-v1/enrolled/index.json"
CURRENT=BASE/"host-runner-readonly-toursdev-v129/latest-summary.json"
REPORT=BASE/"host-authority-v142-single-owner-readiness-readonly/latest.json"
PIN_132="ccac242a3447b252627b633c409b9696859e03c2caff8e41dce68248811a96c6"
PIN_134="cb2d8b2794d5acc62f2bf83454e00e498f4f36b9312d7bc00e37629094c7a293"
CONTRACT="mad4b.original-host-v142.single-owner-readiness-readonly.v1"
SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"

class UntrustedReadiness(ValueError):
    pass

def require(condition, code):
    if not condition:
        raise UntrustedReadiness(code)

def trusted(path:Path, *, sha=None):
    st=path.lstat()
    require(stat.S_ISREG(st.st_mode) and not path.is_symlink()
            and st.st_uid==0 and st.st_gid==0
            and stat.S_IMODE(st.st_mode)&0o077==0
            and st.st_size<=262144, "READINESS_ROOT_SOURCE_NOT_TRUSTED")
    data=path.read_bytes()
    if sha is not None:
        require(hashlib.sha256(data).hexdigest()==sha,
                "READINESS_FIRSTPARTY_VERIFIER_PIN_DRIFT")
    return data

def evaluate(*,original305:dict, archive313:dict,
             standby:dict, current:dict, now:int)->dict:
    """Pure and fail-closed: never returns an authorization or certificate."""
    require(all(type(x) is dict for x in (original305,archive313,standby,current))
            and type(now) is int and now>0,
            "READINESS_INVALID_EVIDENCE")
    require(original305.get("contract")=="mad4b.original305.host-v3-root-preflight.v1",
            "READINESS_ORIGINAL305_CONTRACT_MISMATCH")
    require(archive313.get("contract")==
            "mad4b.original313.host-v134-private-archive-census.v1"
            and archive313.get("target_site_uuid")==SITE,
            "READINESS_ORIGINAL313_CONTRACT_OR_SITE_MISMATCH")
    require(standby.get("contract")==
            "mad4b.host-v133.verified-original-v3-source-standby.v1"
            and standby.get("site_uuid")==SITE
            and standby.get("activation_state")==
                "VERIFIED_ROOT_PRIVATE_SOURCE_STANDBY_NOT_EXECUTABLE",
            "READINESS_STANDBY_SOURCE_NOT_BOUND")
    require(original305.get("site_uuid")==SITE
            and original305.get("company")=="mad4b-central"
            and original305.get("environment")=="staging"
            and standby.get("company")=="mad4b-central"
            and standby.get("environment")=="staging",
            "READINESS_WRONG_COMPANY_OR_ENVIRONMENT")
    require(original305.get("original305_v8_manifest_pin_verified") is True
            and original305.get("original305_legacy_ambiguous_write_hold_preserved") is True,
            "READINESS_LEGACY_HOLD_OR_PROVIDER_NOT_VERIFIED")
    require(archive313.get("v2_signed_current_pairs_verified") is not None
            and type(archive313["v2_signed_current_pairs_verified"]) is int
            and type(archive313.get("root_archive_sha256")) is str,
            "READINESS_ARCHIVE_AUDIT_MALFORMED")
    require(current.get("signed_current_verified") is True,
            "READINESS_HOST_CURRENT_NOT_SIGNED")
    require(original305.get("approved_write") is False
            and current.get("write_authorized") is False,
            "READINESS_UNEXPECTED_ACTIVE_WRITE_REQUIRES_MANUAL_AUDIT")

    checks={
      "complete_original313_history":(
         archive313.get("complete_cross_generation_history_verified") is True
         and archive313.get("complete_original313_predecessor_for_target")
             != "NOT_ESTABLISHED"),
      "fresh_signed_original313_predecessor":
         original305.get("original313_predecessor_v3_signed") is True,
      "independent_owner_signer_enrolled":
         original305.get("original305_v3_independent_owner_key_registered") is True,
      "human_owner_stepup_registered":
         original305.get("original305_v3_approved_owner_stepup_registered") is True,
      "original305_exact_v3_approval_signed":
         original305.get("original305_v3_exact_approval_signed") is True,
      "original312_distinct_v3_enrollment":
         original305.get("original312_v3_distinct_signer_enrollment_verified") is True,
    }
    blockers=[key for key,ok in checks.items() if not ok]
    # Even if independent issuers eventually become ready, this monitor
    # DOES NOT allow Write. A fresh Original318 + Root313 readback and
    # scoped operation grants remain distinct checks in their own channel.
    code=("FIRSTPARTY_ISSUANCE_READBACK_REQUIRED" if not blockers
          else "WAITING_FOR_INDEPENDENT_FIRSTPARTY_EVIDENCE")
    if not checks["independent_owner_signer_enrolled"]:
        owner_action=("VPS_HOST_SSH_TRANSPORT_SUPPORTED__"
                      "EXISTING_OWNER_V3_SIGNER_TRUST_GATE_SEPARATE")
    elif not checks["complete_original313_history"] or not checks["fresh_signed_original313_predecessor"]:
        owner_action="WAIT_FOR_INDEPENDENT_ORIGINAL313_SIGNED_OFFER"
    elif not checks["human_owner_stepup_registered"]:
        owner_action="SIGN_FRESH_EXACT_ORIGINAL313_OFFER_ON_OWNER_DEVICE"
    else:
        owner_action="NO_REPEAT_WORDPRESS_REVIEW"
    return {
      "contract":CONTRACT,"observed_epoch":now,
      "site_uuid":SITE,"company":"mad4b-central","environment":"staging",
      "single_owner_model_requested":True,
      "single_owner_identity_cryptographically_enrolled":
         checks["independent_owner_signer_enrolled"],
      "owner_wordpress_review_must_not_be_repeated":True,
      "remote_repair_ssh_transport":"VPS_TO_REGISTERED_HOSTS_ONLY",
      "owner_workstation_required_for_hosting_ssh":False,
      "host_ssh_key_counts_as_owner_signature":False,
      "checks":checks,"blockers":blockers,
      "archive_v2_verified_chains":archive313["v2_signed_current_pairs_verified"],
      "original313_archive_sha256":archive313["root_archive_sha256"],
      "original305_legacy_hold_preserved":True,
      "original305_legacy_hold_reason":
         original305.get("original305_legacy_hold_reason"),
      "host_signed_current_snapshot_sha256":current.get("snapshot_sha256"),
      "source_activation_state":standby["activation_state"],
      "owner_action":owner_action,
      "original305_retry_performed":False,
      "owner_signature_issued":False,
      "v3_enrollment_issued":False,
      "write_authorized":False,"production_authorized":False,
      "next_state":code,
    }

def live_evidence():
    require(os.geteuid()==0, "READINESS_ROOT_REQUIRED")
    trusted(V132,sha=PIN_132)
    trusted(V134,sha=PIN_134)
    standby=json.loads(trusted(V133))
    current=json.loads(trusted(CURRENT))
    command=[sys.executable,"-B",str(V132),"--check"]
    output=subprocess.run(command,capture_output=True,text=True,timeout=15,check=False,
                          env={"PATH":"/usr/local/bin:/usr/bin:/bin",
                               "PYTHONDONTWRITEBYTECODE":"1"})
    require(output.returncode==0 and len(output.stdout)<32768,
            "READINESS_ORIGINAL305_PREFLIGHT_FAILED")
    original=json.loads(output.stdout)
    spec=importlib.util.spec_from_file_location("_pinned_original313_census",V134)
    require(spec is not None and spec.loader is not None,
            "READINESS_ORIGINAL313_IMPORT_BLOCKED")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    archive=module.history_census(root=V2_ARCHIVE,index_path=INDEX)
    return original,archive,standby,current

def publish_private(value:dict,path:Path):
    """Root-owned atomic status, no arbitrary user-selected output path."""
    require(path==REPORT and os.geteuid()==0,"READINESS_PUBLISH_DESTINATION_DENIED")
    root=path.parent
    require(root.is_dir() and not root.is_symlink()
            and root.stat().st_uid==0
            and stat.S_IMODE(root.stat().st_mode)==0o700,
            "READINESS_REPORT_ROOT_NOT_PRIVATE")
    if path.exists():
        trusted(path)
    payload=(json.dumps(value,sort_keys=True,indent=2)+"\n").encode()
    temp=root/(".latest-"+str(os.getpid())+".json")
    fd=os.open(temp,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,"wb") as f:
            f.write(payload)
            f.flush()
            os.fsync(f.fileno())
        os.replace(temp,path)
        dirfd=os.open(root,os.O_RDONLY|os.O_DIRECTORY|os.O_NOFOLLOW)
        try:os.fsync(dirfd)
        finally:os.close(dirfd)
    finally:
        if temp.exists():temp.unlink()

def main():
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--check",action="store_true")
    p.add_argument("--publish",action="store_true")
    args=p.parse_args()
    require(args.check != args.publish,"READINESS_EXACT_MODE_REQUIRED")
    original,history,standby,current=live_evidence()
    value=evaluate(original305=original,archive313=history,
                   standby=standby,current=current,now=int(time.time()))
    if args.publish:publish_private(value,REPORT)
    print(json.dumps(value,sort_keys=True))
    return 0

if __name__=="__main__":
    try:sys.exit(main())
    except (UntrustedReadiness,ValueError,KeyError,FileNotFoundError,
            PermissionError,subprocess.TimeoutExpired) as e:
        print("HOST_V142_READINESS_FAIL_CLOSED:"+str(e),file=sys.stderr)
        sys.exit(3)
