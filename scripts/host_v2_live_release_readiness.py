#!/usr/bin/env python3
"""Read-only release admission diagnostics for Original Host Authority v2.

No mutation, grant, provider registration, signer access or publisher. A passing
source hash or existence of a Python file can never certify a live operation.
"""
from __future__ import annotations
import hashlib
import json
import os
import sys
import stat
import subprocess
from pathlib import Path
from datetime import datetime, timezone

ROOT = Path(__file__).resolve().parents[1]
LIVE318 = Path("/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/"
 "wp-content/plugins/mad4b-platform/modules/authority/318-mad4b-native-host-exact-grant.php")
REGISTRY = Path("/opt/mad4b-company-transport/native-313-independent/"
 "dynamic-operation-registry-v1/enrolled")
CANDIDATE318 = ROOT/"ops/original318-signed-anchor-candidate-v82/318-mad4b-native-host-exact-grant.php"
EXPECTED318 = "56c78c0036c1e690069a2abbbbe4e84f8745bf30fec4dcc9c875835e52e442be"
# Reviewed v83 successor adds signed current/revoked Original318 snapshots.
# Hash pin is code provenance, NEVER an issuer, signed Job or Original313 receipt.
EXPECTED318_V83 = "1c80391b15c750238339cf43c248dfd6c4017ec80102ddc01e210b9e8ec26b0b"
# Exact, tested and selectively installed successor code hashes.
# Provenance alone NEVER certifies a Job, independent enrollment or release.
EXPECTED318_V95 = "6a27f5aac43ac8b6f70b42ef2ea7c5fdfb58224902094fc576badccb19d7dee4"
EXPECTED318_V96 = "66da81222c43ae8b609857f6d738e66d4b1ec7870cbcc12ff6303c0c6e8cbb9f"
EXPECTED318_V103 = "76ba920416dec16db8f6f53c63bfa749a7eb6277dd5190cc6ce6c74ebb49fc23"
REVIEWED_318 = {
    EXPECTED318: "v82", EXPECTED318_V83: "v83",
    EXPECTED318_V95: "v95", EXPECTED318_V96: "v96",
    EXPECTED318_V103: "v103",
}


ISSUE = "company.host-authority.anchor.issue"
REVOKE = "company.host-authority.anchor.revoke"

def digest(path:Path)->str|None:
    try:
        if path.is_symlink() or not path.is_file() or path.stat().st_size > 2_000_000:
            return None
        return hashlib.sha256(path.read_bytes()).hexdigest()
    except (OSError,ValueError):
        return None

def original305_306_host_job_probe(*, run=None)->dict:
    """Read-only WordPress SQL/options scan; no owner impersonation or grants.

    Scope is the enrolled Central Staging WP-CLI runtime, not a caller URL.
    Presence is NOT a signed Owner Enrollment or permission to execute.
    """
    php=r"""
global $wpdb;
$kinds=[
  "jobs"=>MAD4B_Central_VPS_Broker_V1::JOB,
  "requests"=>MAD4B_Central_SSH_Command_Intake_V1::REQUEST_PREFIX
];
$out=["contract"=>"mad4b.original305-306.host-job-presence.v1",
      "scanned"=>[],"issue_count"=>0,"revoke_count"=>0,"incomplete"=>false];
foreach($kinds as $kind=>$prefix) {
 $names=$wpdb->get_col($wpdb->prepare(
   "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s LIMIT 256",
   $wpdb->esc_like($prefix)."%"));
 $out["scanned"][$kind]=count($names);
 if(count($names)>=256)$out["incomplete"]=true;
 foreach($names as $name) {
  $row=get_option($name,false);
  if(!is_array($row))continue;
  $op=(string)($row["operation_id"]??$row["operation"]??"");
  if(($row["company"]??null)!=="all-royal-egypt"||
     ($row["environment"]??null)!=="staging")continue;
  if($op==="company.host-authority.anchor.issue")$out["issue_count"]++;
  if($op==="company.host-authority.anchor.revoke")$out["revoke_count"]++;
 }
}
echo wp_json_encode($out,JSON_UNESCAPED_SLASHES);
"""
    try:
        if run is None:
            native="/opt/mad4b-company-transport"
            if native not in sys.path:sys.path.insert(0,native)
            from central_wpcli_runtime import central_wp
            run=lambda source:subprocess.run(
                central_wp()+["eval",source],
                capture_output=True,text=True,timeout=38)
        result=run(php)
        if result.returncode!=0:
            raise ValueError("READ_ONLY_WPCLI_UNAVAILABLE")
        rows=[x.strip() for x in result.stdout.splitlines()
              if x.strip().startswith("{")]
        if len(rows)!=1:raise ValueError("READ_ONLY_WPCLI_RESPONSE_AMBIGUOUS")
        data=json.loads(rows[0])
        if data.get("contract")!="mad4b.original305-306.host-job-presence.v1":
            raise ValueError("WP_READ_ONLY_CONTRACT_CHANGED")
        scanned=data.get("scanned")
        if (type(scanned) is not dict
            or set(scanned)!={"jobs","requests"}
            or not all(type(scanned[k]) is int and 0<=scanned[k]<=256
                       for k in ("jobs","requests"))
            or not all(type(data.get(k)) is int and 0<=data[k]<=512
                       for k in ("issue_count","revoke_count"))
            or type(data.get("incomplete")) is not bool):
            raise ValueError("READ_ONLY_WPCLI_COUNTS_INVALID")
        if data["incomplete"] or any(v>=256 for v in scanned.values()):
            return {"state":"INCOMPLETE","scanned":scanned,
                    "job_present_verified":False,
                    "authorization_verified":False}
        total=data["issue_count"]+data["revoke_count"]
        return {"state":"NO_HOST_AUTHORITY_JOB" if total==0 else "PRESENT_UNVERIFIED",
                "scanned":scanned,
                "issue_count":data["issue_count"],
                "revoke_count":data["revoke_count"],
                "job_present_verified":total>0,
                "authorization_verified":False}
    except (ValueError,TypeError,KeyError,ImportError,OSError,
            subprocess.TimeoutExpired) as exc:
        return {"state":"UNKNOWN",
                "reason":"ORIGINAL305_306_READ_ONLY_SOURCE_UNAVAILABLE",
                "job_present_verified":False,
                "authorization_verified":False}


def first_enrollment_presence(
    root:Path=Path("/var/lib/mad4b/original-host-v2/enrollments"))->dict:
    """Absence is factual; presence is never treated as signed authority."""
    try:
        parent=root.lstat()
        if (not stat.S_ISDIR(parent.st_mode)
            or root.is_symlink() or parent.st_uid!=0 or parent.st_gid!=0
            or stat.S_IMODE(parent.st_mode)!=0o700):
            raise ValueError("ENROLLMENT_ROOT_UNTRUSTED")
        path=root/"all-royal-egypt--staging.json"
        if not path.exists() and not path.is_symlink():
            return {"state":"NO_INDEPENDENT_ENROLLMENT",
                    "authorization_verified":False}
        entry=path.lstat()
        if (not stat.S_ISREG(entry.st_mode) or path.is_symlink()
            or entry.st_uid!=0 or entry.st_gid!=0 or entry.st_nlink!=1
            or stat.S_IMODE(entry.st_mode)!=0o600):
            return {"state":"UNTRUSTED_FILE",
                    "authorization_verified":False}
        return {"state":"PRESENT_SIGNATURE_NOT_VERIFIED",
                "authorization_verified":False}
    except (OSError,ValueError):
        return {"state":"UNKNOWN","authorization_verified":False}


def inspect(live318:Path=LIVE318,registry:Path=REGISTRY,
            candidate:Path=CANDIDATE318, *,
            owner_probe=None, enrollment_probe=None)->dict:
    checks={}
    current=digest(live318)
    vetted=digest(candidate)
    checks["original318_candidate_exact"] = vetted == EXPECTED318
    checks["original318_live_v82"] = current == EXPECTED318
    version = REVIEWED_318.get(current, "unrecognized")
    checks["original318_live_reviewed"] = version != "unrecognized"
    reviewed_successor = version in ("v83", "v95", "v96", "v103")
    # These do not affect commissioning or Original313 receipt proof.
    checks["original318_dual_signed_reconcile_present"] = version in ("v95", "v96", "v103")
    checks["original318_revocation_binding_v96_present"] = version in ("v96", "v103")
    checks["original318_expired_signed_readback_v103_present"] = version == "v103"

    try:
        candidate_text=candidate.read_text()
        checks["source_revocation_observer_present"]=(
            "public static function independent_revoked_anchor_observation_v2(" in candidate_text
            and "independent_highwater_verified" in candidate_text
        )
    except (OSError,UnicodeDecodeError):
        checks["source_revocation_observer_present"]=False
    try:
        index_file=registry/"index.json"
        index=json.loads(index_file.read_text())
        if index.get("contract")!="mad4b.dynamic-operation-index.v1":
            raise ValueError("wrong registry contract")
        providers={}
        for row in index.get("providers",[]):
            path=row.get("manifest"); pinned=row.get("sha256")
            if not isinstance(path,str) or not path.startswith(
                "dynamic-operation-registry-v1/providers/") or ".." in Path(path).parts:
                raise ValueError("manifest path untrusted")
            manifest_file=registry/path
            if digest(manifest_file)!=pinned:
                raise ValueError("manifest source digest drift")
            manifest=json.loads(manifest_file.read_text())
            op=manifest.get("operation_id")
            if op in providers:raise ValueError("duplicate registry operation")
            providers[op]=manifest
        checks["installed_registry_manifest_digest_verified"]=True
        checks["host_issue_provider_registered"]=(
            ISSUE in providers and
            providers[ISSUE].get("resource_kind")=="host_anchor" and
            providers[ISSUE].get("source_family")==
                "mad4b.original-host-authority-anchor.v2" and
            providers[ISSUE].get("execution_enabled") is False)
        checks["host_revoke_provider_registered"]=(
            REVOKE in providers and
            providers[REVOKE].get("resource_kind")=="host_anchor" and
            providers[REVOKE].get("source_family")==
                "mad4b.original-host-authority-anchor.v2" and
            providers[REVOKE].get("execution_enabled") is False)
    except (OSError,ValueError,TypeError,KeyError):
        providers={}
        checks["installed_registry_manifest_digest_verified"]=False
        checks["host_issue_provider_registered"]=False
        checks["host_revoke_provider_registered"]=False
    # Registry entry != commissioned signed original312 issuer/revoker.
    # An explicit real signed approval/receipt from independent original
    # stores is needed to change either of these false runtime states.
    checks["host_issue_original312_issuer_commissioned"]=False
    checks["host_revoke_original312_issuer_commissioned"]=False
    worker_text=""
    evidence_text=""
    try:
        worker_text=(registry/"worker_adapter.py").read_text()
        evidence_text=(registry/"evidence.py").read_text()
    except (OSError,UnicodeDecodeError):
        pass
    # Presence of a source token is diagnostic only, never a live signed grant.
    checks["installed_worker_has_explicit_v2_admission"] = (
        "signed_remote_hostinger_wpcli_v2" in worker_text
        and "resolve_remote_host_v2_claim" in worker_text
    )
    checks["installed_evidence_has_host_v2_operation_contract"] = (
        "signed_remote_hostinger_wpcli_v2" in evidence_text
        and "company.host-authority.anchor.revoke" in evidence_text
    )
    checks["independent_signer_and_real_receipt_certified"] = False
    # Static file presence alone cannot attest to an enrolled current reader.
    try:
        checks["v83_signed_current_readback_code_present"] = (
            reviewed_successor
            and "public static function current_anchor_readback_v2(" in live318.read_text()
            and "signed_anchor_active_in_option" in live318.read_text()
            and "fresh_central_highwater_verified" in live318.read_text()
        )
    except (OSError, UnicodeDecodeError):
        checks["v83_signed_current_readback_code_present"] = False
    # Receipt certification must be populated only by original independently
    # signed evidence readback, never inferred from this observer.
    # A reviewed v83 successor supersedes the historical v82 byte predicate;
    # both still need real, enrolled Original306/312/313 issue/revoke proof.
    informational={
        "original318_live_v82", "v83_signed_current_readback_code_present",
        "original318_dual_signed_reconcile_present",
        "original318_revocation_binding_v96_present",
        "original318_expired_signed_readback_v103_present",
    }
    pending=[k for k,v in checks.items()
             if not v and (k not in informational or
                 (k=="original318_live_v82" and not checks["original318_live_reviewed"]))]
    owner = (owner_probe() if owner_probe is not None
             else {"state":"NOT_PROBED","authorization_verified":False})
    enrollment = (enrollment_probe() if enrollment_probe is not None
                  else {"state":"NOT_PROBED","authorization_verified":False})
    if owner.get("state")=="NO_HOST_AUTHORITY_JOB":
        first_dependency="ORIGINAL305_AUTHENTIC_OWNER_HOST_JOB_MISSING"
    elif enrollment.get("state")=="NO_INDEPENDENT_ENROLLMENT":
        first_dependency="ORIGINAL313_INDEPENDENT_OWNER_ENROLLMENT_MISSING"
    else:
        first_dependency="ORIGINAL306_312_313_EXECUTION_NOT_COMMISSIONED"
    return {
        "contract":"mad4b.unified-host-v2.readonly-release-admission.v1",
        "owner_job_evidence":owner,
        "first_enrollment_evidence":enrollment,
        "first_unresolved_runtime_dependency":first_dependency,
        "observed_utc":datetime.now(timezone.utc).isoformat(),
        "source_candidate_sha256":vetted,
        "original318_live_sha256":current,
        "original318_live_reviewed_version":version,
        "checks":checks,
        "pending":pending,
        "state":"BLOCKED" if pending else "SOURCE_PREFLIGHT_ONLY_NOT_A_RELEASE",
        "checks_are_non_authorizing":True,
        "release_applied":False,"grant_created":False,
        "anchor_issued":False,"anchor_revoked":False,
        "original313_receipt_signed":False,
        "production_mutated":False,
    }

if __name__=="__main__":
    print(json.dumps(inspect(
        owner_probe=original305_306_host_job_probe,
        enrollment_probe=first_enrollment_presence),sort_keys=True,indent=2))
