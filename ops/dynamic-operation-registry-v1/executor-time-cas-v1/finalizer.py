#!/usr/bin/env python3
"""Independent Original 313 Finalizer for an already-applied CAS Original Job.

No target mutation, grant/job/claim issuance, host mutation replay or rollback
occurs here. This is a content-pinned SOURCE CANDIDATE, NOT a live enrolled
313 finalizer. A first-party operator must enroll the exact CAS provider,
release-grant gate, central per-operation trust and Original 313 routing.

The only final certification is written by Central's *existing* Original
313 native finish, after two independently signed Original 313 evidences
and a fresh exact WordPress postimage + durable target replay-fence check.
"""
from __future__ import annotations

import argparse
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
from typing import Any

from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

OPERATION="company.resource.cas.apply"
ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
DYNAMIC=ROOT/"dynamic-operation-registry-v1/enrolled"
CAS= DYNAMIC/"executor-time-cas-v1"
ORIGINAL_313=Path(
 "/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/"
 "scripts/original_dynamic_313_finalizer.py"
)
ORIGINAL_OPTION=ROOT/"full-wpcli-v1/original-option-wpcli-executor.py"
ORIGINAL_SIGNER=Path(
 "/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py"
)
RECEIPTS=ROOT/"receipts"
HOST="srv2030908"
HEX32=re.compile(r"^[a-f0-9]{32}$")
HEX64=re.compile(r"^[a-f0-9]{64}$")
NATIVE_V4_FIELDS=[
 "contract","job_id","company","environment","site_uuid","origin",
 "target_id","grant_id","transport_id","command_sha256",
 "native_receipt_sha256","output_sha256","expected_readback_sha256",
 "readback_sha256","issued","expires","nonce","observed_operation",
 "production_mutation","postcondition_type","postcondition_key",
 "sealed_argv_sha256","dynamic_plan_sha256","dynamic_evidence_sha256",
]


class FinalizerHold(ValueError):
    pass


def require(ok:bool,reason:str):
    if not ok:raise FinalizerHold(reason)


def pinned_module(path:Path,name:str,index=None,digest:str|None=None):
    import stat
    st=path.lstat()
    require(st.st_uid==0 and stat.S_ISREG(st.st_mode)
            and not(st.st_mode&0o022) and not path.is_symlink(),
            "ORIGINAL_313_FIRST_PARTY_MODULE_UNTRUSTED")
    if digest is not None:
        require(HEX64.fullmatch(digest) is not None and
                hashlib.sha256(path.read_bytes()).hexdigest()==digest,
                "ORIGINAL_313_PINNED_VERIFIER_SOURCE_DRIFT")
    spec=importlib.util.spec_from_file_location(name,path)
    require(spec is not None and spec.loader is not None,
            "ORIGINAL_313_PINNED_MODULE_NOT_LOADABLE")
    mod=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(mod)
    return mod


def readback_approved_original(job:str,plan_sha:str)->dict:
    """Independent readback of the original target. Never runs an Apply."""
    require(os.geteuid()==0 and isinstance(job,str)
            and HEX32.fullmatch(job) is not None
            and isinstance(plan_sha,str)
            and HEX64.fullmatch(plan_sha) is not None,
            "ORIGINAL_313_EXACT_ROOT_JOB_AND_PLAN_REQUIRED")
    sys.path.insert(0,str(DYNAMIC))
    import registry
    index=registry.Registry(DYNAMIC)
    manifest=index.resolve(OPERATION)
    require(manifest.get("finalizer",{}).get("path")==
                "executor-time-cas-v1/finalizer.py",
            "ORIGINAL_313_CAS_NOT_INSTALLED_IN_REGISTRY")
    current=Path(__file__).resolve()
    require(current==DYNAMIC/manifest["finalizer"]["path"]
            and hashlib.sha256(current.read_bytes()).hexdigest()==
                manifest["finalizer"]["sha256"],
            "ORIGINAL_313_FINALIZER_RUNTIME_SOURCE_NOT_PINNED")
    handler=pinned_module(
        DYNAMIC/manifest["handler"]["path"],
        "_mad4b_original313_cas_host",
        index,manifest["handler"]["sha256"])
    reader=pinned_module(
        DYNAMIC/manifest["verifier"]["path"],
        "_mad4b_original313_cas_independent",
        index,manifest["verifier"]["sha256"])
    _,plan,authority,digest=handler.pinned_plan(job,plan_sha)
    grant=handler.verify_signed_specific_grant(job,plan,authority,digest)
    require(grant.get("signed_release_grant_verified") is True,
            "ORIGINAL_313_CAS_SIGNED_RELEASE_GRANT_NOT_READ")
    target=pinned_module(handler.WORDPRESS_HOST_PROOF,
        "_mad4b_original313_cas_exact_registered_target")
    central,site,container,_policy,_fingerprint,_broker=target.info(
        plan["company"],plan["environment"])
    require(central.get("site_uuid")==plan["site_uuid"]
            and central.get("origin")==plan["origin"]
            and site.get("site_uuid")==plan["site_uuid"]
            and site.get("origin")==plan["origin"]
            and site.get("environment")==plan["environment"],
            "ORIGINAL_313_CAS_CURRENT_WORDPRESS_BINDING_CHANGED")
    # This is a new read from WordPress, separate from the mutating worker.
    independent=reader.independent_verify(
        job,plan,container,site["actor_user_id"])
    require(independent.get("independent_source_readback_verified") is True
            and independent.get("original_313_signed") is False
            and independent.get("state")==
                "INDEPENDENT_TARGET_CAS_POSTIMAGE_VERIFIED_UNSIGNED"
            and independent.get("resource_key")==
                plan["resource_type"]+":"+plan["resource_id"]
            and independent.get("before_sha256")==plan["expected_before_sha256"]
            and independent.get("after_sha256")==plan["expected_after_sha256"]
            and independent.get("company_preservation_sha256")==
                plan["expected_company_preservation_sha256"],
            "ORIGINAL_313_CAS_INDEPENDENT_POSTIMAGE_INVALID")
    receipt=reader.verified_host_receipt(job,plan)
    recorded=receipt["record"]
    now=int(time.time())
    require(recorded.get("command_sha256")==digest
            and type(recorded.get("applied_epoch")) is int
            and now-3600<=recorded["applied_epoch"]<=now+5
            and recorded["applied_epoch"]<=authority["expires_epoch"]
            and recorded.get("original_313_certified") is False
            and receipt["native_receipt_sha256"]==
                independent["native_receipt_sha256"],
            "ORIGINAL_313_CAS_MUTATION_WINDOW_OR_RECEIPT_CHANGED")
    return {"index":index,"manifest":manifest,"plan":plan,
        "authority":authority,"original_job_id":job,
        "command_sha256":digest,"independent":independent,
        "native_receipt_sha256":receipt["native_receipt_sha256"]}


def make_signed_dynamic_proof(
    evidence:dict,signer,*,issued:int|None=None)->dict:
    """Build independent signed evidence from freshly observed source only."""
    import registry
    state=evidence["independent"]
    index=evidence["index"]
    manifest=evidence["manifest"]
    plan=evidence["plan"]
    authority=evidence["authority"]
    instant=int(time.time()) if issued is None else issued
    require(manifest.get("operation_id")==OPERATION
            and manifest.get("evidence_schema")=="resource_cas.v1"
            and state.get("independent_source_readback_verified") is True
            and state.get("original_313_signed") is False,
            "ORIGINAL_313_CAS_PROOF_NOT_INDEPENDENT")
    post_sha=state["after_sha256"]
    payload={
        "result_status":state["state"],
        "resource_key":state["resource_key"],
        "before_sha256":state["before_sha256"],
        "after_sha256":post_sha,
        "company_preservation_sha256":
            state["company_preservation_sha256"],
        "native_receipt_sha256":evidence["native_receipt_sha256"],
        "readback_sha256":post_sha,
        "expected_readback_sha256":post_sha,
        "output_sha256":post_sha,
        "grant_id":authority["grant_id"],
        "transport_id":authority["transport_id"],
        "original_313_signed":False,
        "production_mutation":plan["environment"]=="production",
    }
    schema=index.evidence_definition(OPERATION)
    require(schema.get("success_state")==payload["result_status"]
            and set(payload)==set(schema["fields"])
            and payload["after_sha256"]==plan["expected_after_sha256"]
            and payload["before_sha256"]==plan["expected_before_sha256"]
            and payload["company_preservation_sha256"]==
                plan["expected_company_preservation_sha256"],
            "ORIGINAL_313_CAS_EVIDENCE_SCHEMA_OR_POSTIMAGE_CHANGED")
    from evidence import validate_payload
    validate_payload(payload,schema["fields"])
    proof={
        "contract":"mad4b.dynamic-313-independent-proof.v1",
        "job_id":evidence["original_job_id"],
        "operation_id":OPERATION,
        "provider_version":manifest["version"],
        "evidence_schema":manifest["evidence_schema"],
        "company":plan["company"],
        "environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "target_id":HOST,
        "command_sha256":evidence["command_sha256"],
        "plan_sha256":plan["plan_sha256"],
        "evidence_sha256":registry.sha(registry.canonical(payload)),
        "payload":payload,
        "issued_epoch":instant,"expires_epoch":instant+110,
        "nonce":secrets.token_hex(16),
    }
    proof["signature"]=base64.b64encode(
        signer.sign(registry.canonical(proof))).decode()
    return proof


def make_signed_native_v4(dynamic:dict,evidence:dict,signer,
                          *,issued:int|None=None)->dict:
    """Independent v4 binds the same evidence to the existing 313 finisher."""
    instant=int(time.time()) if issued is None else issued
    manifest=evidence["manifest"];plan=evidence["plan"];auth=evidence["authority"]
    digest=dynamic["payload"]["readback_sha256"]
    require(dynamic["operation_id"]==OPERATION
            and dynamic["job_id"]==evidence["original_job_id"]
            and dynamic["plan_sha256"]==plan["plan_sha256"]
            and digest==plan["expected_after_sha256"]
            and dynamic["payload"]["native_receipt_sha256"]==
                evidence["native_receipt_sha256"],
            "ORIGINAL_313_CAS_DYNAMIC_AND_V4_PROOF_DISAGREE")
    fields={
        "contract":"mad4b.native-313-independent-host-proof.v4",
        "job_id":dynamic["job_id"],"company":plan["company"],
        "environment":plan["environment"],"site_uuid":plan["site_uuid"],
        "origin":plan["origin"],"target_id":HOST,
        "grant_id":auth["grant_id"],"transport_id":auth["transport_id"],
        "command_sha256":evidence["command_sha256"],
        "native_receipt_sha256":evidence["native_receipt_sha256"],
        "output_sha256":digest,
        "expected_readback_sha256":digest,
        "readback_sha256":digest,
        "issued":instant,"expires":instant+85,
        "nonce":secrets.token_hex(16),
        "observed_operation":OPERATION,
        "production_mutation":
            "true" if plan["environment"]=="production" else "false",
        "postcondition_type":"enrolled_dynamic_evidence",
        "postcondition_key":manifest["evidence_schema"],
        "sealed_argv_sha256":evidence["command_sha256"],
        "dynamic_plan_sha256":plan["plan_sha256"],
        "dynamic_evidence_sha256":dynamic["evidence_sha256"],
    }
    require(list(fields)==NATIVE_V4_FIELDS,
            "ORIGINAL_313_V4_CAS_FIELDS_CHANGED")
    body="\n".join(str(fields[name]) for name in NATIVE_V4_FIELDS).encode()
    fields["signature"]=base64.b64encode(signer.sign(body)).decode()
    return fields


def finalization_once(evidence:dict,dp:dict,v4:dict,*,legacy)->dict:
    """Reuse existing original 313 ingest, independent proof and finish.

    No host Apply call here. If a signer/transport step times out after a
    pending receipt, a subsequent attempt must compare immutable evidence
    and deliver only the same finished record.
    """
    job=evidence["original_job_id"];authority=evidence["authority"]
    plan=evidence["plan"];digest=dp["payload"]["readback_sha256"]
    state=legacy.original_central_state(job)
    if(state.get("job_state")=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED"
       and state.get("job_executed") is True
       and state.get("original_readback_verified") is True
       and state.get("pending_native_certified") is True
       and state.get("pending_native_sha256")==v4["native_receipt_sha256"]
       and state.get("finished_native_sha256")==v4["native_receipt_sha256"]
       and state.get("signed_dynamic_verified") is True
       and state.get("signed_dynamic_plan")==plan["plan_sha256"]
       and state.get("signed_dynamic_evidence")==dp["evidence_sha256"]
       and state.get("signed_native_contract")==
           "mad4b.native-313-independent-host-proof.v4"
       and state.get("signed_native_plan")==plan["plan_sha256"]
       and state.get("signed_native_evidence")==dp["evidence_sha256"]):
        return {"state":"WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED",
             "job_id":job,"original_313_certified":True,
             "already_certified":True,"host_execution_replayed":False}
    require(state.get("job_state")==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY"
            and state.get("job_executed") is False,
            "ORIGINAL_313_CAS_JOB_NOT_PENDING")
    helper=legacy.original_module(ORIGINAL_SIGNER,"original_313_cas_broker")
    broker=helper.original_signer()
    helper.check_original_wordpress_signer(broker)
    original_option=legacy.original_module(
        ORIGINAL_OPTION,"original_313_cas_pending")
    if state.get("pending_state") is None:
        original_option.send_signed_pending(job,authority,digest,broker)
    else:
        require(state.get("pending_state")==
                 "PENDING_INDEPENDENT_NATIVE_READBACK"
            and state.get("pending_grant_id")==authority["grant_id"]
            and state.get("pending_command_sha256")==dp["command_sha256"]
            and state.get("pending_output_sha256")==digest
            and state.get("pending_readback_sha256")==digest,
            "ORIGINAL_313_CAS_EXISTING_PENDING_PROOF_CHANGED")
    if state.get("signed_dynamic_plan") is None:
        payload=base64.b64encode(json.dumps(
            dp,separators=(",",":")).encode()).decode()
        stored=legacy.wp_manager_command([
            "mad4b","dynamic-313-proof","ingest","--payload="+payload])
        require(stored.get("verified") is True
            and stored.get("state")=="INDEPENDENT_DYNAMIC_EVIDENCE_STORED"
            and stored.get("job_id")==job,
            "ORIGINAL_313_CAS_CENTRAL_DYNAMIC_PROOF_NOT_ACCEPTED")
    else:
        require(state.get("signed_dynamic_verified") is True
            and state.get("signed_dynamic_plan")==plan["plan_sha256"]
            and state.get("signed_dynamic_evidence")==dp["evidence_sha256"],
            "ORIGINAL_313_CAS_PREVIOUS_DYNAMIC_PROOF_CHANGED")
    if state.get("signed_native_contract") is None:
        encoded=base64.b64encode(json.dumps(
            v4,separators=(",",":")).encode()).decode()
        stored=legacy.wp_manager_command([
            "mad4b","native-313-proof","ingest","--payload="+encoded])
        require(stored.get("verified") is True
            and stored.get("state")=="INDEPENDENT_NATIVE_HOST_PROOF_STORED"
            and stored.get("job_id")==job,
            "ORIGINAL_313_CAS_V4_PROOF_NOT_ACCEPTED")
    else:
        require(state.get("signed_native_contract")==
                "mad4b.native-313-independent-host-proof.v4"
            and state.get("signed_native_receipt")==v4["native_receipt_sha256"]
            and state.get("signed_native_plan")==plan["plan_sha256"]
            and state.get("signed_native_evidence")==dp["evidence_sha256"],
            "ORIGINAL_313_CAS_PREVIOUS_NATIVE_PROOF_CHANGED")
    finish={
        "contract":"mad4b.broker-wpcli-native-finish.v1",
        "host_id":HOST,"job_id":job,"target_id":HOST,
        "grant_id":authority["grant_id"],
        "transport_id":authority["transport_id"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "environment":plan["environment"],
        "command_sha256":evidence["command_sha256"],
        "native_receipt_sha256":v4["native_receipt_sha256"],
        "expected_readback_sha256":digest,
        "readback_sha256":digest,"output_sha256":digest,
        "exit_code":0,
        "issued":int(time.time()),"expires":int(time.time())+65,
        "nonce":secrets.token_hex(16),
    }
    names=[
        "contract","host_id","job_id","target_id","grant_id","transport_id",
        "site_uuid","origin","environment","command_sha256",
        "native_receipt_sha256","expected_readback_sha256",
        "readback_sha256","output_sha256","exit_code","issued","expires","nonce",
    ]
    raw="\n".join(str(finish[k]) for k in names).encode()
    finish["signature"]=base64.b64encode(broker.sign(raw)).decode()
    stored=legacy.store_original_finish_once(
        RECEIPTS/(job+"-resource-cas313-finish.json"),finish)
    require(stored["expires"]>int(time.time())+8
            and stored["native_receipt_sha256"]==v4["native_receipt_sha256"],
            "ORIGINAL_313_CAS_STORED_FINISH_EXPIRED_OR_CHANGED")
    encoded=base64.b64encode(json.dumps(
        stored,separators=(",",":")).encode()).decode()
    end=legacy.wp_manager_command([
        "mad4b","broker-remote-ssh","wpcli-finish","--payload="+encoded])
    require(end.get("verified") is True
            and end.get("job_id")==job
            and end.get("native_receipt_sha256")==v4["native_receipt_sha256"]
            and end.get("state")=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED",
            "ORIGINAL_313_CAS_FINAL_CERTIFICATE_NOT_PERSISTED")
    return {"state":"WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED",
            "operation_id":OPERATION,"job_id":job,
            "original_313_certified":True,
            "host_execution_replayed":False,
            "original_305_job_created":False,
            "new_grant_created":False}


def finalize(job:str,plan_sha:str,*,read_only:bool)->dict:
    evidence=readback_approved_original(job,plan_sha)
    if read_only:
        return {"contract":"mad4b.original313.resource-cas-finalizer.v1",
            "state":"EXACT_CAS_INDEPENDENT_POSTIMAGE_READY_UNSIGNED",
            "job_id":job,"operation_id":OPERATION,
            "signed_original_313":False,"host_execution_replayed":False}
    old=pinned_module(ORIGINAL_313,"mad4b_original313_legacy_functions")
    # Existing original host verifier signing seed is read only here.
    signer=old.native_signer(evidence["index"])
    now=int(time.time())
    dp=make_signed_dynamic_proof(evidence,signer,issued=now)
    v4=make_signed_native_v4(dp,evidence,signer,issued=now)
    result=finalization_once(evidence,dp,v4,legacy=old)
    return {"contract":"mad4b.original313.resource-cas-finalizer.v1",
            "operation_id":OPERATION,**result}


if __name__=="__main__":
    args=argparse.ArgumentParser()
    args.add_argument("--job",required=True)
    args.add_argument("--plan-sha256",required=True)
    args.add_argument("--read-only-preflight",action="store_true")
    a=args.parse_args()
    try:
        result=finalize(a.job,a.plan_sha256,read_only=a.read_only_preflight)
        print(json.dumps(result,sort_keys=True))
    except Exception as error:
        # Avoid printing a tenant secret, signed payload or private root path.
        print("ORIGINAL_313_CAS_HOLD:"+type(error).__name__,file=sys.stderr)
        raise SystemExit(2)
