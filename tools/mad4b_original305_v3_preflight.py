#!/usr/bin/env python3
"""Read-only Original305 v3 enrollment/approval readiness from ORIGINAL Root.

This reads the actual enrolled Original312/313 index, protected Owner305
incident, and existing live read-only Host profile. It never loads private
signing keys or calls a signer or WordPress mutation.
"""
from __future__ import annotations
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
INDEX=ROOT/"dynamic-operation-registry-v1/enrolled/index.json"
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
HOLD=ROOT/"host-source-v1/original305-readiness-ambiguous-write-HOLD.json"
PROFILE=Path("/opt/mad4b-company-transport/host-runner-readonly-toursdev-v129/profile.json")
ROOT_TRUST=Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/000-mad4b-host-v3-root-trust.php")
WORKER_SOURCE=Path("/opt/mad4b-company-transport/native-313-independent/host-source-v1")
STATUS_CONTRACT="mad4b.original305.host-v3-root-preflight.v1"
MANIFEST="dynamic-operation-registry-v1/providers/host.source.release/manifest.v8.json"
MU_PUBLIC_SHA="9ac13f38b8a685d54ed89a5f0624d04d7e08f42b98604012e247be3878254d3d"
EXPECTED_SITE="e5e64bca-ed62-48d2-b2b3-dff693cd702a"
EXPECTED_ORIGIN="https://tours.dev.mad4b.com"

class ReadinessHold(RuntimeError):pass

def check_root_file(path:Path):
    if not path.is_file() or path.is_symlink():
        raise ReadinessHold("ROOT_SOURCE_OR_FILE_MISSING")
    s=path.lstat()
    if not (stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_gid==0
            and s.st_nlink==1 and not (stat.S_IMODE(s.st_mode)&0o077)):
        raise ReadinessHold("ROOT_TRUST_FILE_UNTRUSTED")
    return path.read_bytes()

def status()->dict:
    if os.geteuid()!=0:
        raise ReadinessHold("ORIGINAL305_ROOT_IDENTITY_REQUIRED")
    registry=json.loads(check_root_file(INDEX))
    if registry.get("installed_by_first_party") is not True:
        raise ReadinessHold("ORIGINAL_REGISTRY_NOT_FIRSTPARTY")
    rows=[p for p in registry.get("providers",[])
          if type(p) is dict and p.get("manifest")==MANIFEST]
    if len(rows)!=1:
        raise ReadinessHold("ORIGINAL305_BROKER_V8_MANIFEST_ABSENT")
    manifest=REGISTRY/MANIFEST
    mraw=check_root_file(manifest)
    if hashlib.sha256(mraw).hexdigest()!=rows[0].get("sha256"):
        raise ReadinessHold("ORIGINAL305_BROKER_INDEX_DRIFT")
    index305=json.loads(mraw)
    if index305.get("operation_id")!="host.source.release" or index305.get("version")!=8:
        raise ReadinessHold("ORIGINAL305_BROKER_MANIFEST_INVALID")
    p312=base64.b64decode(registry["host_anchor_312_public_key_b64"],validate=True)
    p313=base64.b64decode(registry["independent_verifier_public_key_b64"],validate=True)
    if len(p312)!=32 or len(p313)!=32 or p312==p313:
        raise ReadinessHold("ORIGINAL312_313_PIN_INVALID")
    profile=json.loads(check_root_file(PROFILE))
    bind=profile["live_current_binding"]
    if (profile["allowed_operations"]!=["site.code.current.read"]
        or bind["site_uuid"]!=EXPECTED_SITE or bind["origin"]!=EXPECTED_ORIGIN
        or bind["environment"]!="staging" or bind["company"]!="mad4b-central"):
        raise ReadinessHold("SITE_BINDING_READONLY_PROFILE_CHANGED")
    current_sha=hashlib.sha256(ROOT_TRUST.read_bytes()).hexdigest()
    if current_sha!=MU_PUBLIC_SHA:
        raise ReadinessHold("ROOT_PUBLIC_VERIFIER_SOURCE_DRIFT")
    if not HOLD.is_file() or HOLD.is_symlink():
        raise ReadinessHold("ORIGINAL305_HOLD_MARKER_CHANGED_REVIEW_REQUIRED")
    sticky=json.loads(check_root_file(HOLD))
    if (sticky.get("state")!="AMBIGUOUS_SIGNED_WRITE_HOLD"
        or sticky.get("automatic_retry_allowed") is not False):
        raise ReadinessHold("ORIGINAL305_HOLD_STATE_UNEXPECTED")
    # The Original312 legacy public is the SAME as the incumbent
    # Original Broker public. It cannot be borrowed as independent 305.
    return {
      "contract":STATUS_CONTRACT,"site":EXPECTED_ORIGIN,
      "site_uuid":EXPECTED_SITE,"company":"mad4b-central",
      "environment":"staging",
      "original305_legacy_broker_registered":True,
      "original305_legacy_broker_public_sha256":hashlib.sha256(p312).hexdigest(),
      "original305_existing_broker_key_is_also_legacy312":True,
      "original305_v3_independent_owner_key_registered":False,
      "original305_v3_approved_owner_stepup_registered":False,
      "original305_v3_exact_approval_signed":False,
      "original312_legacy_public_sha256":hashlib.sha256(p312).hexdigest(),
      "original312_v3_distinct_signer_enrollment_verified":False,
      "original313_independent_public_sha256":hashlib.sha256(p313).hexdigest(),
      "original313_predecessor_v3_signed":False,
      "original305_legacy_ambiguous_write_hold_preserved":True,
      "original305_legacy_hold_reason":sticky.get("reason_code"),
      "original305_v8_manifest_pin_verified":True,
      "current_original_registry_sha256":hashlib.sha256(
          check_root_file(INDEX)).hexdigest(),
      "root_public_verifier_live_sha256":current_sha,
      "approved_write":False,
      "automatic_signature_attempted":False,
      "production_authorized":False,
      "next_exact_authority":"FIRST_PARTY_OWNER_STEPUP_AND_DISTINCT_305_312_SIGNER_REGISTRATION",
      "state":"HOLD_MISSING_OWNER_V3_SIGNED_IDENTITY",
    }

def main():
    import argparse
    p=argparse.ArgumentParser(description=__doc__)
    p.add_argument("--check",required=True,action="store_true")
    opt=p.parse_args()
    try:
        result=status()
        rc=0
    except (ReadinessHold,KeyError,ValueError,OSError,TypeError) as e:
        code=str(e)
        result={
            "contract":STATUS_CONTRACT,
            "state":"HOLD_ROOT_FIRSTPARTY_READBACK_NOT_VERIFIED",
            "error_code":code if re.fullmatch(r"[A-Z][A-Z0-9_]{5,110}",code)
                         else "ORIGINAL305_ROOT_READBACK_FAILED",
            "approved_write":False,"automatic_signature_attempted":False,
        }
        rc=2
    print(json.dumps(result,sort_keys=True))
    return rc
if __name__=="__main__":
    raise SystemExit(main())
