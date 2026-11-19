#!/usr/bin/env python3
"""v119 First Anchor Root runner — read-only commissioning admission.

This source is deliberately NOT an issuer. Before any independent Original313
signer is connected it must prove that both original305/306 identities, the
immutable real queued witness from v118, the real claimed WordPress record,
an externally sourced broker row and original318 are current and identical.

No CLI switch enables signing or WordPress changes. A later independently
reviewed root release must connect original v105/v100/v99/v318 in order and
test recovery before commissioning. The existing signed provider manifest
must retain execution_enabled=false.
"""
from __future__ import annotations
from pathlib import Path
import hashlib
import importlib.util
import json
import os
import re
import stat
import sys
import time

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
REGISTRY=ROOT/"dynamic-operation-registry-v1/enrolled"
PLAN_STORE=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
PRIVATE=Path("/var/lib/mad4b/original-host-v2")
SOURCES={
    "v118":(ROOT/"host-authority-v118/queued_original_capture.py",
            "b76778a5ecc786c74a02b5bd0a9c42f9256a17a7f46b4089ff1bd72368b0ecb2"),
    "v105":(ROOT/"host-authority-v105/original313_first_owner_enrollment_issuer.py",
            "9abf86765eed1f7128258c997a474aecc6c872ad0552079189c61e5a6c1f3b71"),
    "v100":(ROOT/"host-authority-v100/original312_first_issue_bundle.py",
            "1a49a50046b19a3076b63fbaf1757d2f8d19982e86bd7c990b36b65af458277d"),
    "v99":(ROOT/"host-authority-v99/original313_postwrite_transaction.py",
            "1b42065db70e224a889c969d28d9b8119e1dceba8003d43e05dc0b0111f0c00d"),
}
ISSUE="company.host-authority.anchor.issue"
HEX32=re.compile(r"[a-f0-9]{32}\Z")
HEX64=re.compile(r"[a-f0-9]{64}\Z")
SCOPE="all-royal-egypt--staging.json"
TRANSFORMED_JOB_KEYS=frozenset((
    "state","claimed_epoch","independent_host_permission_verified"
))
CONTRACT="mad4b.original313.first-anchor-root-runner-readiness.v1"

class FirstAnchorHeld(ValueError):pass

def require(cond, reason):
    if not cond: raise FirstAnchorHeld(reason)

def pin(name):
    require(name in SOURCES,"V119_SOURCE_NOT_ENROLLED")
    p,sha=SOURCES[name]
    require(p.is_file() and not p.is_symlink(),"V119_SOURCE_MISSING")
    st=p.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and st.st_gid==0
        and st.st_nlink==1 and stat.S_IMODE(st.st_mode)==0o600
        and hashlib.sha256(p.read_bytes()).hexdigest()==sha,
        "V119_SOURCE_PIN_DRIFT")
    spec=importlib.util.spec_from_file_location("_host_v119_"+name,p)
    require(spec is not None and spec.loader is not None,
        "V119_ORIGINAL_IMPORT_DENIED")
    m=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(m)
    return m

def check_scope(index,plan,job):
    from registry import Registry
    require(type(index) is Registry and index.root==REGISTRY,
        "V119_FIRST_PARTY_REGISTRY_REQUIRED")
    require(type(plan) is dict and type(job) is dict,
        "V119_EXACT_ROOT_PLAN_AND_JOB_REQUIRED")
    manifest=index.resolve(ISSUE)
    require(manifest.get("execution_enabled") is False
        and manifest.get("execution_requires_signed_originals") is True
        and manifest.get("target_scope")=="host"
        and manifest.get("job_type")=="signed_remote_hostinger_wpcli_v2"
        and manifest.get("command",{}).get("format")=="host.anchor.ref.v2",
        "V119_GENERIC_HOST_EXECUTION_MUST_STAY_OFF")
    require(job.get("operation")==ISSUE
        and job.get("operation_id")==ISSUE
        and job.get("company")=="all-royal-egypt"
        and job.get("environment")=="staging"
        and job.get("target_host_id")=="hosting-2"
        and job.get("job_type")=="signed_remote_hostinger_wpcli_v2"
        and job.get("principal_type")=="signed_host_principal_v2"
        and job.get("executed") is False
        and job.get("host_v2_bootstrap_only") is True
        and job.get("authority_mode")=="bootstrap"
        and isinstance(job.get("job_id"),str)
        and HEX32.fullmatch(job["job_id"])
        and isinstance(job.get("plan_sha256"),str)
        and HEX64.fullmatch(job["plan_sha256"])
        and plan.get("plan_sha256")==job["plan_sha256"]
        and index.command_sha256(ISSUE,plan["plan_sha256"])==
            job.get("command_sha256"),
        "V119_OTHER_TENANT_OR_OPERATION_DENIED")

def compare_queued_and_claimed(queued,claimed,claim,*,now):
    """Do not reconstruct a prior QUEUED Job from current claimed state."""
    require(type(queued) is dict and type(claimed) is dict
        and type(claim) is dict,
        "V119_GENUINE_QUEUED_AND_CLAIMED_ORIGINALS_REQUIRED")
    require(queued.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
        and claimed.get("state")==
            "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        and claim.get("state")==claimed["state"]
        and queued.get("job_id")==claimed.get("job_id")==claim.get("job_id")
        and type(claim.get("claimed_epoch")) is int
        and claimed.get("claimed_epoch")==claim["claimed_epoch"]
        and now-30<=claim["claimed_epoch"]<=now+5
        and type(queued.get("expires_epoch")) is int
        and type(claimed.get("expires_epoch")) is int
        and claimed["expires_epoch"]>=now+115
        and queued["expires_epoch"]==claimed["expires_epoch"],
        "V119_CLAIM_NOT_CURRENT_FOR_COMPLETE_SIGNING_WINDOW")
    require(set(claimed)-set(queued)==TRANSFORMED_JOB_KEYS-set(queued),
        "V119_UNEXPECTED_CLAIMED_JOB_KEYS")
    require(all(queued.get(k)==claimed.get(k) for k in queued
        if k not in TRANSFORMED_JOB_KEYS),
        "V119_ORIGINAL306_JOB_MUTATED_OUTSIDE_CLAIM_FIELDS")
    require(claimed.get("independent_host_permission_verified") is False
        and claim.get("production_mutation_performed") is False,
        "V119_CLAIM_ALREADY_EXECUTING_OR_MODIFIED")

def validate_readiness(index,preimage,claimed,claim,broker_row,
                       plan,current,*,now=None):
    """Pure testable admission; caller MUST source broker_row independently.

    This gate cannot issue or sign. If current live Broker Row provenance is
    missing, the root entrypoint will hold rather than synthesize a row from
    the Original306 claimed Job.
    """
    instant=int(time.time()) if now is None else now
    require(os.geteuid()==0,"V119_ROOT_REQUIRED")
    require(type(preimage) is dict and type(current) is dict,
        "V119_HISTORIC_AND_FRESH_RECORDS_REQUIRED")
    require(set(current)=={"job","claim","request","grant","parent",
                          "transport","site"} and
        all(type(current[k]) is dict for k in current),
        "V119_EXACT_CURRENT_ORIGINAL_SET_REQUIRED")
    historical=preimage.get("originals")
    require(preimage.get("contract")==
        "mad4b.original306.first-anchor-queued-preimage.v1"
        and preimage.get("issuer_execution_authorized") is False
        and preimage.get("anchor_issued") is False
        and type(preimage.get("captured_epoch")) is int
        and preimage["captured_epoch"]<=instant
        and type(historical) is dict,
        "V119_NONAUTHORIZING_PRECLAIM_WITNESS_REQUIRED")
    queued=historical.get("queued")
    require(type(queued) is dict and preimage.get("job_id")==queued.get("job_id"),
        "V119_CLAIM_AND_ROOT_PREIMAGE_IDENTITY_CHANGED")
    check_scope(index,plan,queued)
    require(preimage.get("plan_sha256")==plan.get("plan_sha256"),
        "V119_IMMUTABLE_PLAN_CHANGED")
    compare_queued_and_claimed(queued,claimed,claim,now=instant)
    require(current["job"]==claimed and current["claim"]==claim,
        "V119_LIVE_ORIGINAL306_READBACK_CHANGED")
    for k in ("request","grant","parent","transport"):
        require(historical.get(k)==current[k],
            "V119_ORIGINAL305_328_OWNER_CHANGED:"+k.upper())
    site=current["site"]
    require(site.get("original328_active") is True
        and site.get("anchor_option_present") is False
        and type(site.get("anchor_readback")) is dict
        and site["anchor_readback"].get("state")=="MISSING_OR_INVALID"
        and site["anchor_readback"].get("revision")==0
        and site["anchor_readback"].get("readback_verified") is False
        and site["anchor_readback"].get("write_authorized") is False
        and site["anchor_readback"].get("original313_certified") is False
        and type(site["anchor_readback"].get("observed_epoch")) is int
        and instant-15<=site["anchor_readback"]["observed_epoch"]<=instant+5,
        "V119_ORIGINAL318_PRISTINE_FRESH_READBACK_REQUIRED")
    require(type(broker_row) is dict,
        "V119_INDEPENDENT_ORIGINAL_BROKER_ROW_MISSING")
    require(current["request"].get("request_id")==queued["job_id"]
        and current["request"].get("status")==
          "ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY"
        and current["request"].get("execution_performed") is False
        and type(current["request"].get("expires_epoch")) is int
        and current["request"]["expires_epoch"]>=instant+115,
        "V119_ORIGINAL305_OWNER_REQUEST_EXPIRED")
    v95=pin("v118")  # Mandatory source pin to prevent shadowed preimage reader.
    require(v95.CONTRACT==preimage["contract"],
        "V119_PREIMAGE_VERIFIER_NOT_PINNED")
    # Original306 v86 is invoked via original 313 v105's enrolled pinned
    # loader (not a copied/hand-rolled schema).
    v105=pin("v105")
    v86=v105.pinned(v105.V86,v105.V86_SHA,
        "_v119_original306_claim_verifier")
    verified=v86.inspect_claimed_original(index,claimed,claim,broker_row,plan)
    require(verified.get("state")==
        "HOST_V2_CLAIM_MATCHED__SIGNED_ORIGINAL312_ISSUER_PENDING"
        and verified.get("original306_claim_matched") is True
        and verified.get("execution_authorized") is False
        and verified.get("original313_certified") is False
        and verified.get("anchor_issued") is False,
        "V119_SIGNED_ORIGINAL306_CLAIM_NOT_VERIFIED")
    return {
        "contract":CONTRACT,
        "job_id":queued["job_id"],
        "state":"CLAIM_READY_FOR_ROOT_313_ORIGINALS",
        "plan_sha256":plan["plan_sha256"],
        "historic_queued_validated":True,
        "original306_claim_matched":True,
        "original318_pristine":True,
        "owner_expiry_verified":True,
        "issuer_commissioned":False,
        "execution_authorized":False,
        "anchor_issued":False,
    }

def classify_recovery(readiness, store_state, live_state):
    """Crash recovery classification only. NEVER invokes WP318 or a signer."""
    require(type(readiness) is dict and type(store_state) is dict
        and type(live_state) is dict,
        "V119_RECOVERY_REQUIRES_READBACK")
    require(set(store_state)=={"enrollment","prewrite","signed_docs",
                              "revision","checkpoint","receipt"}
        and set(live_state)=={"revision","state","verified","write_authorized"},
        "V119_EXACT_RECOVERY_STATE_REQUIRED")
    require(readiness.get("contract")==CONTRACT
        and readiness.get("execution_authorized") is False,
        "V119_RECOVERY_ORIGINAL_PROOF_REQUIRED")
    e=store_state["enrollment"]
    t=store_state["prewrite"]
    d=store_state["signed_docs"]
    r=store_state["revision"]
    c=store_state["checkpoint"]
    receipt=store_state["receipt"]
    # All durable source files are verified separately by Original313 v99,
    # never promoted to trusted just because they exist.
    if live_state["write_authorized"] is True:
        return "HOLD_UNEXPECTED_WRITE_AUTHORITY"
    if live_state["revision"]==0 and live_state["state"]=="MISSING_OR_INVALID":
        if r or c or receipt:
            return "HOLD_POSTWRITE_LEDGER_WITH_MISSING_ORIGINAL318_ANCHOR"
        if not e:return "READ_ONLY_READY_NO_ORIGINAL313_ENROLLMENT"
        if not d:return "HOLD_ENROLLMENT_PERSISTED_ORIGINAL312_NOT_VERIFIED"
        if not t:return "HOLD_SIGNED_DOCS_WITHOUT_ORIGINAL313_PREWRITE"
        return "PREWRITE_PERSISTED_REVERIFY_SIGNATURE_AND_EXPIRY_BEFORE_SINGLE_WRITE"
    if live_state["revision"]==1 and live_state["state"]=="ACTIVE":
        if not t or not d or not e:
            return "HOLD_ORIGINAL318_WRITE_WITHOUT_PRIOR_SIGNED_PREWRITE"
        if not receipt or not c or not r:
            return "POSTWRITE_RECOVERY_ORIGINAL313_FINALIZE_ONLY_NO_REPEAT_WP_WRITE"
        return "FINAL_RECEIPT_PRESENT_REQUIRE_INDEPENDENT_SIGNED_READBACK"
    return "HOLD_UNKNOWN_ANCHOR_STATE"

def read_only_status(job_id):
    """Only safe live entrypoint; no user-data-supplied Broker Row accepted."""
    require(os.geteuid()==0,"V119_ROOT_REQUIRED")
    require(type(job_id) is str and HEX32.fullmatch(job_id),
        "V119_JOB_ID_INVALID")
    v118=pin("v118")
    if str(REGISTRY) not in sys.path:sys.path.insert(0,str(REGISTRY))
    from registry import Registry,checked_json
    index=Registry(REGISTRY)
    manifest=index.resolve(ISSUE)
    require(manifest["execution_enabled"] is False,
        "V119_PROVIDER_GENERIC_EXECUTION_MUST_STAY_DISABLED")
    try:
        saved=v118.read_preimage(job_id)
    except (ValueError,FileNotFoundError,OSError,KeyError):
        return {
            "contract":CONTRACT,
            "state":"HOLD_NO_IMMUTABLE_ORIGINAL306_QUEUED_PREIMAGE",
            "anchor_issued":False,"signature_invoked":False,
            "wp_write_performed":False,
        }
    plan=checked_json(PLAN_STORE/(saved["plan_sha256"]+".json"),PLAN_STORE)
    check_scope(index,plan,saved["originals"]["queued"])
    # An independent, read-only broker row fetcher and live claim readback
    # are deliberately absent from this noncommissioned module. Do not
    # synthesize that row from the WordPress Job or return signing admission.
    return {
        "contract":CONTRACT,
        "state":"HOLD_INDEPENDENT_BROKER_ROW_AND_ORIGINAL313_RUNNER_NOT_COMMISSIONED",
        "historical_queued_evidence_verified":True,
        "anchor_issued":False,"signature_invoked":False,
        "wp_write_performed":False,
    }

def main(argv=None):
    import argparse
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--job-id",required=True)
    parser.add_argument("--inspect",action="store_true",
                        help="Root read-only inspection; no execution mode")
    args=parser.parse_args(argv)
    try:
        result=read_only_status(args.job_id)
        status=2
    except (FirstAnchorHeld,ValueError,KeyError,TypeError,OSError) as err:
        reason=str(err)
        if not re.fullmatch(r"[A-Z][A-Z0-9_]{5,120}",reason):
            reason="V119_FIRST_ANCHOR_READINESS_HELD"
        result={"contract":CONTRACT,"state":"HOLD","reason":reason,
            "anchor_issued":False,"signature_invoked":False,
            "wp_write_performed":False}
        status=2
    print(json.dumps(result,sort_keys=True))
    return status

if __name__=="__main__":
    raise SystemExit(main())
