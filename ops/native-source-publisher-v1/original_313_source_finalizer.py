#!/usr/bin/env python3
"""Original313 source-code-release Finalizer candidate, first-party only.

Uses existing independent Original313 signer, Central Dynamic Proof, original
Broker signed pending/finish and real WPCLI readback. No new signer, grant,
original Job, Claim, target Apply, rollback, or replacement execution path.

Fail-closed until the same first-party Registry, finalizer SHA pin, per-operation
WordPress trust and real original305/312 signed authority are enrolled.
"""
from __future__ import annotations

import argparse
import base64
from datetime import datetime,timezone
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import stat
import sys
import time

import native_source_publisher as publisher
import original_313_host_handler as handler
import original_313_independent_readback as independent

HOST=handler.HOST
INDEX=publisher.REGISTRY_ROOT
PLANS=handler.PLANS
GRANTS=handler.GRANTS
APPROVALS=handler.APPROVALS
FIRST_PARTY_ORIGINAL313=Path(
    "/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/"
    "scripts/original_dynamic_313_finalizer.py")
ORIGINAL_OPTION=Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "full-wpcli-v1/original-option-wpcli-executor.py")
ORIGINAL_SIGNER=Path(
    "/opt/mad4b-company-transport/broker-v1/native-vps-client-principal.py")
RECEIPTS=Path("/opt/mad4b-company-transport/native-313-independent/receipts")
HEX32=re.compile(r"^[a-f0-9]{32}$")
HEX64=re.compile(r"^[a-f0-9]{64}$")
FINALIZER_REL="native-source-publisher-v1/original_313_source_finalizer.py"
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


def require(ok:bool,reason:str)->None:
    if not ok:raise FinalizerHold(reason)


def original_finalizer_module():
    """Load the EXISTING root-owned Original313 orchestrator, never a key."""
    st=FIRST_PARTY_ORIGINAL313.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and
            not st.st_mode&0o022 and not FIRST_PARTY_ORIGINAL313.is_symlink(),
            "FIRST_PARTY_ORIGINAL313_ORCHESTRATOR_NOT_TRUSTED")
    spec=importlib.util.spec_from_file_location(
        "_mad4b_original313_existing_source_finalizer",FIRST_PARTY_ORIGINAL313)
    require(spec is not None and spec.loader is not None,
            "FIRST_PARTY_ORIGINAL313_ORCHESTRATOR_NOT_LOADABLE")
    module=importlib.util.module_from_spec(spec)
    module.__dict__["__source_finalizer_import_only__"]=True
    spec.loader.exec_module(module)
    return module


def exact_enrolled_finalizer(index,manifest:dict)->None:
    """No candidate source or unsigned manifest can install the Finalizer."""
    import registry
    declared=manifest.get("finalizer")
    require(isinstance(declared,dict) and
            declared.get("path")==FINALIZER_REL and
            isinstance(declared.get("sha256"),str) and
            HEX64.fullmatch(declared["sha256"]) is not None,
            "SOURCE_FIRST_PARTY_SIGNED_FINALIZER_NOT_ENROLLED")
    path=index.root/FINALIZER_REL
    require(path.absolute()==Path(__file__).absolute() and not path.is_symlink(),
            "SOURCE_FINALIZER_NOT_IN_ENROLLED_REGISTRY_ROOT")
    raw=registry.protected_file(path,index.root,maximum=2_000_000)
    require(registry.sha(raw)==declared["sha256"],
            "SOURCE_FINALIZER_BYTE_PIN_CHANGED")
    require(manifest.get("source_family")==publisher.FAMILY and
            manifest.get("evidence_schema")=="source_code_release.v1",
            "SOURCE_FINALIZER_ENROLLED_OPERATION_WRONG")


def readback_approved_original(job:str,plan_sha:str)->dict:
    """Read exact original305, original312, Broker approval and host postimage."""
    require(os.geteuid()==0 and isinstance(job,str) and HEX32.fullmatch(job)
            and isinstance(plan_sha,str) and HEX64.fullmatch(plan_sha),
            "SOURCE_FINALIZER_ROOT_JOB_AND_PLAN_REQUIRED")
    index=publisher.original_registry()
    raw_plan=handler.protected_file(PLANS/(plan_sha+".json"),PLANS)
    original=json.loads(raw_plan)
    require(isinstance(original,dict) and original.get("plan_sha256")==plan_sha,
            "SOURCE_FINALIZER_ORIGINAL_CAS_PLAN_INVALID")
    plan=publisher.verified_enriched_plan(original,registry=index)
    operation=plan[publisher.BINDING_KEY]["operation_id"]
    manifest=publisher.enrolled_original_provider(operation,index)
    exact_enrolled_finalizer(index,manifest)
    # `checked_plan` of Original306 checks the independent immutable CAS plan
    # from the ORIGINAL store; this must match the root source Publisher's plan.
    import registry
    import admission
    trusted=admission.checked_plan(PLANS,plan_sha,manifest)
    require(trusted==original,"SOURCE_FINALIZER_ORIGINAL305_STORED_PLAN_CHANGED")
    policy=publisher.checked_policy()
    publisher.check_plan(plan,policy,after=True,registry=index)
    broker_grant=json.loads(handler.protected_file(
        GRANTS/(job+".json"),GRANTS))
    approval=json.loads(handler.protected_file(
        APPROVALS/(plan_sha+".json"),APPROVALS))
    require(broker_grant.get("original_job_id")==job,
            "SOURCE_FINALIZER_BROKER_GRANT_JOB_DRIFT")
    publisher.verify_master_approval(plan,policy,approval)
    publisher.fresh_master_ticket_readback(plan,policy,approval)
    # The independent reader re-verifies Original312, signed Grant, runtime
    # identity, actual target bytes and original Host receipt.
    evidence=independent.evidence_for_original_job(job,plan,policy,broker_grant)
    signed=handler.original_312_for_claim(job,plan)
    command=index.command_sha256(operation,plan_sha)
    require(signed.get("original_312_verified") is True and
            signed.get("command_sha256")==command and
            signed.get("grant_id")==evidence["grant_id"] and
            signed.get("transport_id")==evidence["transport_id"] and
            evidence["readback_sha256"]==evidence["expected_readback_sha256"]
            and evidence["readback_sha256"]==evidence["output_sha256"]
            and evidence["readback_sha256"]==evidence["target_sha256"],
            "SOURCE_FINALIZER_SIGNED312_EVIDENCE_SCOPE_CHANGED")
    root=Path(policy["receipt_dir"])
    raw_receipt=handler.protected_file(root/(broker_grant["nonce"]+".json"),root)
    receipt=json.loads(raw_receipt)
    try:
        happened=datetime.fromisoformat(receipt["at"])
        require(happened.tzinfo is not None,"SOURCE_RECEIPT_TIMEZONE_MISSING")
        elapsed=int(happened.timestamp())
    except (KeyError,TypeError,ValueError) as error:
        raise FinalizerHold("SOURCE_FINALIZER_RECEIPT_APPLIED_EPOCH_INVALID") from error
    instant=int(time.time())
    require(type(signed.get("expires_epoch")) is int and
            instant-3600<=elapsed<=instant+5 and
            elapsed<=signed["expires_epoch"],
            "SOURCE_FINALIZER_APPLY_OUTSIDE_ORIGINAL312_WINDOW")
    schema=index.evidence_definition(operation)
    require(schema.get("schema_id")==manifest["evidence_schema"] and
            schema.get("success_state")==evidence["result_status"] and
            set(schema.get("fields",{}))==set(evidence),
            "SOURCE_FINALIZER_ORIGINAL313_EVIDENCE_SCHEMA_DRIFT")
    return {"index":index,"manifest":manifest,"plan":plan,
            "authority":signed,"original_job_id":job,
            "operation_id":operation,"command_sha256":command,
            "evidence":evidence,"native_receipt_sha256":publisher.sha(raw_receipt),
            "readback_after_original312":True}


def make_signed_dynamic_proof(observed:dict,signer,*,issued:int|None=None,
                              nonce:str|None=None)->dict:
    """Format the existing first-party Dynamic313 signature contract."""
    import registry
    instant=int(time.time()) if issued is None else issued
    index=observed["index"];manifest=observed["manifest"]
    plan=observed["plan"];authority=observed["authority"]
    payload=observed["evidence"]
    operation=observed["operation_id"]
    schema=index.evidence_definition(operation)
    require(manifest["operation_id"]==operation
            and manifest["evidence_schema"]==schema["schema_id"]
            and payload["result_status"]==schema["success_state"]
            and set(payload)==set(schema["fields"])
            and payload["output_sha256"]==payload["readback_sha256"]
            ==payload["expected_readback_sha256"]==payload["target_sha256"]
            and payload["grant_id"]==authority["grant_id"]
            and payload["transport_id"]==authority["transport_id"]
            and payload["production_mutation"] is (plan["environment"]=="production")
            and observed["readback_after_original312"] is True,
            "SOURCE_DYNAMIC_PROOF_UNVERIFIED_ORIGINAL313_EVIDENCE")
    from evidence import validate_payload
    validate_payload(payload,schema["fields"])
    random_nonce=secrets.token_hex(16) if nonce is None else nonce
    require(HEX32.fullmatch(random_nonce) is not None,"SOURCE_DYNAMIC_NONCE_INVALID")
    proof={
        "contract":"mad4b.dynamic-313-independent-proof.v1",
        "job_id":observed["original_job_id"],"operation_id":operation,
        "provider_version":manifest["version"],
        "evidence_schema":manifest["evidence_schema"],
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "target_id":HOST,"command_sha256":observed["command_sha256"],
        "plan_sha256":plan["plan_sha256"],
        "evidence_sha256":registry.sha(registry.canonical(payload)),
        "payload":payload,"issued_epoch":instant,"expires_epoch":instant+110,
        "nonce":random_nonce,
    }
    proof["signature"]=base64.b64encode(
        signer.sign(registry.canonical(proof))).decode()
    return proof


def make_signed_native_v4(dynamic:dict,observed:dict,signer,
                          *,issued:int|None=None,nonce:str|None=None)->dict:
    """Original native v4 host binding signed by the SAME existing root key."""
    instant=int(time.time()) if issued is None else issued
    payload=dynamic["payload"]
    plan=observed["plan"];auth=observed["authority"]
    operation=observed["operation_id"]
    digest=payload["readback_sha256"]
    require(dynamic["operation_id"]==operation and
            dynamic["job_id"]==observed["original_job_id"] and
            dynamic["plan_sha256"]==plan["plan_sha256"] and
            dynamic["evidence_schema"]==observed["manifest"]["evidence_schema"] and
            dynamic["command_sha256"]==observed["command_sha256"] and
            digest==payload["expected_readback_sha256"]==payload["output_sha256"],
            "SOURCE_NATIVE_V4_DYNAMIC_PROOF_SCOPE_DRIFT")
    random_nonce=secrets.token_hex(16) if nonce is None else nonce
    require(HEX32.fullmatch(random_nonce) is not None,"SOURCE_NATIVE_V4_NONCE_INVALID")
    proof={
        "contract":"mad4b.native-313-independent-host-proof.v4",
        "job_id":dynamic["job_id"],"company":plan["company"],
        "environment":plan["environment"],"site_uuid":plan["site_uuid"],
        "origin":plan["origin"],"target_id":HOST,
        "grant_id":auth["grant_id"],"transport_id":auth["transport_id"],
        "command_sha256":observed["command_sha256"],
        "native_receipt_sha256":observed["native_receipt_sha256"],
        "output_sha256":digest,"expected_readback_sha256":digest,
        "readback_sha256":digest,"issued":instant,"expires":instant+85,
        "nonce":random_nonce,"observed_operation":operation,
        "production_mutation":"true" if plan["environment"]=="production" else "false",
        "postcondition_type":"enrolled_dynamic_evidence",
        "postcondition_key":observed["manifest"]["evidence_schema"],
        "sealed_argv_sha256":observed["command_sha256"],
        "dynamic_plan_sha256":plan["plan_sha256"],
        "dynamic_evidence_sha256":dynamic["evidence_sha256"],
    }
    require(list(proof)==NATIVE_V4_FIELDS,
            "SOURCE_ORIGINAL313_NATIVE_V4_FIELD_ORDER_DRIFT")
    body="\n".join(str(proof[key]) for key in NATIVE_V4_FIELDS).encode()
    proof["signature"]=base64.b64encode(signer.sign(body)).decode()
    return proof


def first_party_wordpress_313_ready(legacy,operation:str)->None:
    """Require a live per-operation Central first-party 313 enrollment."""
    require(isinstance(operation,str) and publisher.OP_ID.fullmatch(operation),
            "SOURCE_FINALIZER_OPERATION_INVALID")
    # The operation is derived from signed Original306 association and an
    # enrolled Registry manifest; never from user input or an unsigned Job.
    php=(
        '$op="'+operation+'";'
        '$r=class_exists("MAD4B_Dynamic_WP_313_Readback_V1",false)'
        '&&MAD4B_Dynamic_WP_313_Readback_V1::operation_operational_ready($op);'
        '$v=class_exists("MAD4B_Native_WPCLI_Receipt_Verifier_V1",false)'
        '&&MAD4B_Native_WPCLI_Receipt_Verifier_V1::supports_dynamic_v4();'
        'echo wp_json_encode(["ready"=>$r,"native_v4"=>$v]);'
    )
    state=legacy.wp_manager_command(["eval",php])
    require(isinstance(state,dict) and state.get("ready") is True
            and state.get("native_v4") is True,
            "SOURCE_FIRST_PARTY_WORDPRESS_SIGNED313_NOT_READY")


def finalization_once(observed:dict,dynamic:dict,v4:dict,*,legacy)->dict:
    """Reuse original signed pending, ingest and finish services, never Apply."""
    job=observed["original_job_id"]
    auth=observed["authority"]
    plan=observed["plan"]
    operation=observed["operation_id"]
    digest=dynamic["payload"]["readback_sha256"]
    state=legacy.original_central_state(job)
    require(isinstance(state,dict),"SOURCE_ORIGINAL313_CENTRAL_STATE_UNAVAILABLE")
    if (state.get("job_state")=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED" and
        state.get("job_executed") is True and
        state.get("original_readback_verified") is True and
        state.get("pending_native_certified") is True and
        state.get("pending_native_sha256")==v4["native_receipt_sha256"] and
        state.get("finished_native_sha256")==v4["native_receipt_sha256"] and
        state.get("signed_dynamic_verified") is True and
        state.get("signed_dynamic_plan")==plan["plan_sha256"] and
        state.get("signed_dynamic_evidence")==dynamic["evidence_sha256"] and
        state.get("signed_native_contract")==
             "mad4b.native-313-independent-host-proof.v4" and
        state.get("signed_native_receipt")==v4["native_receipt_sha256"] and
        state.get("signed_native_plan")==plan["plan_sha256"] and
        state.get("signed_native_evidence")==dynamic["evidence_sha256"]):
        return {"contract":"mad4b.native-source.original313-finalizer.v1",
                "state":"WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED",
                "operation_id":operation,"job_id":job,
                "original_313_certified":True,"already_certified":True,
                "host_execution_replayed":False,"new_grant_created":False,
                "new_original305_job_created":False}
    require(state.get("job_state")==
                "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY" and
            state.get("job_executed") is False,
            "SOURCE_FINALIZER_ORIGINAL_JOB_NOT_PENDING")
    helper=legacy.original_module(ORIGINAL_SIGNER,"_mad4b_source_original_broker_signer")
    broker=helper.original_signer()
    helper.check_original_wordpress_signer(broker)
    original_option=legacy.original_module(
        ORIGINAL_OPTION,"_mad4b_source_original_pending_transport")
    if state.get("pending_state") is None:
        original_option.send_signed_pending(job,auth,digest,broker)
    else:
        require(state.get("pending_state")=="PENDING_INDEPENDENT_NATIVE_READBACK"
            and state.get("pending_grant_id")==auth["grant_id"]
            and state.get("pending_command_sha256")==dynamic["command_sha256"]
            and state.get("pending_output_sha256")==digest
            and state.get("pending_readback_sha256")==digest,
            "SOURCE_FINALIZER_EXISTING_PENDING_CHANGED")
    if state.get("signed_dynamic_plan") is None:
        blob=base64.b64encode(json.dumps(
            dynamic,separators=(",",":")).encode()).decode()
        stored=legacy.wp_manager_command([
            "mad4b","dynamic-313-proof","ingest","--payload="+blob])
        require(isinstance(stored,dict) and stored.get("verified") is True
            and stored.get("state")=="INDEPENDENT_DYNAMIC_EVIDENCE_STORED"
            and stored.get("job_id")==job,
            "SOURCE_FINALIZER_CENTRAL_DYNAMIC_PROOF_NOT_STORED")
    else:
        require(state.get("signed_dynamic_verified") is True and
                state.get("signed_dynamic_plan")==plan["plan_sha256"] and
                state.get("signed_dynamic_evidence")==dynamic["evidence_sha256"],
                "SOURCE_FINALIZER_EXISTING_DYNAMIC_PROOF_CHANGED")
    if state.get("signed_native_contract") is None:
        blob=base64.b64encode(json.dumps(
            v4,separators=(",",":")).encode()).decode()
        stored=legacy.wp_manager_command([
            "mad4b","native-313-proof","ingest","--payload="+blob])
        require(isinstance(stored,dict) and stored.get("verified") is True and
                stored.get("state")=="INDEPENDENT_NATIVE_HOST_PROOF_STORED"
                and stored.get("job_id")==job,
                "SOURCE_FINALIZER_CENTRAL_NATIVE_V4_NOT_STORED")
    else:
        require(state.get("signed_native_contract")==
                "mad4b.native-313-independent-host-proof.v4" and
                state.get("signed_native_receipt")==v4["native_receipt_sha256"] and
                state.get("signed_native_plan")==plan["plan_sha256"] and
                state.get("signed_native_evidence")==dynamic["evidence_sha256"],
                "SOURCE_FINALIZER_EXISTING_NATIVE_V4_CHANGED")
    finish={
        "contract":"mad4b.broker-wpcli-native-finish.v1","host_id":HOST,
        "job_id":job,"target_id":HOST,
        "grant_id":auth["grant_id"],"transport_id":auth["transport_id"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "environment":plan["environment"],
        "command_sha256":dynamic["command_sha256"],
        "native_receipt_sha256":v4["native_receipt_sha256"],
        "expected_readback_sha256":digest,"readback_sha256":digest,
        "output_sha256":digest,"exit_code":0,
        "issued":int(time.time()),"expires":int(time.time())+65,
        "nonce":secrets.token_hex(16),
    }
    names=["contract","host_id","job_id","target_id","grant_id",
           "transport_id","site_uuid","origin","environment",
           "command_sha256","native_receipt_sha256",
           "expected_readback_sha256","readback_sha256","output_sha256",
           "exit_code","issued","expires","nonce"]
    require(list(finish)==names,"SOURCE_ORIGINAL313_FINISH_FIELDS_CHANGED")
    raw="\n".join(str(finish[key]) for key in names).encode()
    finish["signature"]=base64.b64encode(broker.sign(raw)).decode()
    # Existing original313 durable fence, with source-specific receipt name.
    stored=legacy.store_original_finish_once(
        RECEIPTS/(job+"-native-source313-finish.json"),finish)
    require(stored.get("expires",0)>int(time.time())+8 and
            stored.get("native_receipt_sha256")==v4["native_receipt_sha256"] and
            stored.get("command_sha256")==dynamic["command_sha256"],
            "SOURCE_ORIGINAL313_PREVIOUS_FINISH_EXPIRED_OR_CHANGED")
    blob=base64.b64encode(json.dumps(
        stored,separators=(",",":")).encode()).decode()
    completed=legacy.wp_manager_command([
        "mad4b","broker-remote-ssh","wpcli-finish","--payload="+blob])
    require(isinstance(completed,dict) and completed.get("verified") is True
            and completed.get("job_id")==job
            and completed.get("state")=="WPCLI_NATIVE_EXECUTION_READBACK_VERIFIED"
            and completed.get("native_receipt_sha256")==v4["native_receipt_sha256"],
            "SOURCE_ORIGINAL313_FINAL_SIGNED_RECEIPT_NOT_VERIFIED")
    return {"contract":"mad4b.native-source.original313-finalizer.v1",
            "state":completed["state"],"operation_id":operation,"job_id":job,
            "original_313_certified":True,"already_certified":False,
            "host_execution_replayed":False,"new_grant_created":False,
            "new_original305_job_created":False}


def finalize(job:str,plan_sha:str,*,read_only:bool)->dict:
    # The first-party Registry and its evidence/Admission modules are located
    # at the enrolled root, not in the finalizer's provider subdirectory.
    before=list(sys.path)
    try:
        sys.path.insert(0,str(INDEX))
        observed=readback_approved_original(job,plan_sha)
        if read_only:
            return {"contract":"mad4b.native-source.original313-finalizer.v1",
                    "state":"EXACT_SOURCE_INDEPENDENT_POSTIMAGE_READY_UNSIGNED",
                    "operation_id":observed["operation_id"],
                    "job_id":job,"signed_original_313":False,
                    "original_313_certified":False,"host_execution_replayed":False}
        # Central Original313 must explicitly acknowledge this operation
        # before the existing independent signing key is even read.
        legacy=original_finalizer_module()
        first_party_wordpress_313_ready(legacy,observed["operation_id"])
        signer=legacy.native_signer(observed["index"])
        now=int(time.time())
        dynamic=make_signed_dynamic_proof(observed,signer,issued=now)
        native=make_signed_native_v4(dynamic,observed,signer,issued=now)
        return finalization_once(observed,dynamic,native,legacy=legacy)
    finally:
        sys.path[:]=before


if __name__=="__main__":
    arg=argparse.ArgumentParser()
    arg.add_argument("--job",required=True)
    arg.add_argument("--plan-sha256",required=True)
    arg.add_argument("--read-only-preflight",action="store_true")
    options=arg.parse_args()
    try:
        result=finalize(options.job,options.plan_sha256,
                        read_only=options.read_only_preflight)
        print(json.dumps(result,sort_keys=True))
    except Exception as exc:
        # Never echo private signer bytes, grant body, ticket, or raw receipt.
        print("ORIGINAL313_NATIVE_SOURCE_HOLD:"+type(exc).__name__,file=sys.stderr)
        raise SystemExit(2)
