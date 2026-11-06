"""Federated parent/child task planning; no transport or execution."""
from datetime import datetime, timezone
import copy
import re
from uuid import uuid4
from typing import Callable

from kernel import Company, FCPError, check_message, sha


def plan_child(parent_id: str, company: Company, capability: str,
               scope_digest: str, sequence: int, locales: list[str],
               registry_verify: Callable[[Company], bool]) -> dict:
    """Deterministic child operation, unique delivery and company-specific locale plan."""
    if not isinstance(parent_id,str) or re.fullmatch(
       r"[A-Za-z0-9][A-Za-z0-9._:-]{2,127}",parent_id) is None:
        raise FCPError("PARENT_ID_INVALID")
    if not callable(registry_verify) or registry_verify(company) is not True:
        raise FCPError("REGISTRY_BINDING_UNVERIFIED")
    if company.mode=="local":
        raise FCPError("CENTRAL_DISPATCH_DISABLED")
    if capability not in company.capabilities:
        raise FCPError("CAPABILITY_UNAVAILABLE")
    if not isinstance(scope_digest,str) or re.fullmatch(
       r"sha256:[a-f0-9]{64}",scope_digest) is None:
        raise FCPError("SCOPE_DIGEST_INVALID")
    if (not isinstance(locales,list) or not locales or len(locales)>16
        or len(set(locales))!=len(locales)
        or not all(isinstance(x,str) and re.fullmatch(
           r"[a-z]{2}(?:-[A-Z]{2})?",x) for x in locales)):
        raise FCPError("LOCALES_INVALID")
    if type(sequence) is not int or sequence<0:
        raise FCPError("SEQUENCE_INVALID")
    child_id=sha([parent_id,company.company_id,company.environment,
                  capability,scope_digest,locales])
    idem=sha([company.company_id,company.environment,child_id,"read"])
    message={
        "contract":"company.task.dispatch.v1",
        "company_id":company.company_id,
        "environment":company.environment,
        "site_uuid":company.site_uuid,
        "message_id":"msg-"+uuid4().hex,
        "delivery_id":"delivery-"+uuid4().hex,
        "sent_at":datetime.now(timezone.utc).isoformat(timespec="seconds"),
        "sequence_number":sequence,
        "parent_operation_id":parent_id,"child_operation_id":child_id,
        "requested_capability":capability,"idempotency_key":idem,
        "payload":{
            "execution_mode":company.mode,
            "local_admission_required":True,
            "local_skill_resolution":True,
            "result_delivery":"durable_outbox",
            "requested_scope_digest":scope_digest,
            "locales":locales,
            "status":"PLANNED",
        }
    }
    check_message(message)
    return {"dispatch":message,"locales":locales,
            "state":"PLANNED","actual_execution_verified":False}


def retry_delivery(planned: dict, sequence: int) -> dict:
    """Resend same logical operation using new transport delivery identity."""
    if not isinstance(planned,dict) or "dispatch" not in planned:
        raise FCPError("PLAN_INVALID")
    observation=planned.get("execution_boundary")
    if not isinstance(observation,dict):
        raise FCPError("EXECUTION_BOUNDARY_REQUIRED_FOR_RETRY")
    if observation.get("state")!="TRANSIENT_TRANSPORT_DELIVERY_FAILED_BEFORE_HOST":
        raise FCPError("EXECUTION_BOUNDARY_NOT_RETRYABLE")
    if (type(observation.get("host_journal_events")) is not int
            or observation["host_journal_events"] != 0
            or observation.get("operator_receipt_present") is not False):
        raise FCPError("EXECUTION_OUTCOME_REQUIRES_RECONCILIATION")
    msg=copy.deepcopy(planned["dispatch"])
    msg["delivery_id"]="delivery-"+uuid4().hex
    msg["message_id"]="msg-"+uuid4().hex
    if type(sequence) is not int or sequence<=msg["sequence_number"]:
        raise FCPError("DELIVERY_SEQUENCE_NOT_ADVANCING")
    msg["sequence_number"]=sequence
    msg["sent_at"]=datetime.now(timezone.utc).isoformat(timespec="seconds")
    check_message(msg)
    return msg


def verify_child_receipt(dispatch: dict, receipt: dict, external_verify) -> dict:
    """No Verified outcome unless exact local identity and native 313 evidence match."""
    check_message(dispatch)
    if not isinstance(receipt,dict):
        raise FCPError("RECEIPT_INVALID")
    for field in ("company_id","environment","site_uuid",
                  "parent_operation_id","child_operation_id"):
        if receipt.get(field)!=dispatch[field]:
            raise FCPError("RECEIPT_IDENTITY_MISMATCH")
    valid=(type(receipt.get("broker_stage")) is int
           and receipt["broker_stage"]==313
           and receipt.get("evidence_state")=="Verified"
           and isinstance(receipt.get("readback_digest"),str)
           and re.fullmatch(r"[a-f0-9]{64}",receipt["readback_digest"])
           and receipt["readback_digest"]==receipt.get("result_digest")
           and callable(external_verify)
           and external_verify(dispatch,receipt) is True)
    return {
        "company_id":dispatch["company_id"],
        "environment":dispatch["environment"],
        "child_operation_id":dispatch["child_operation_id"],
        "status":"COMPLETED" if valid else "UNVERIFIED",
        "evidence":"Verified" if valid else "Unverified",
        "receipt_reference":receipt.get("receipt_reference","") if valid else "",
        "verified_receipt_check":bool(valid),
    }


def aggregate(parent_id: str, child_results: list[dict]) -> dict:
    """Expose partial results without waiting for all companies."""
    known=("PLANNED","QUEUED","RUNNING","COMPLETED","FAILED",
           "REJECTED","UNREACHABLE","UNVERIFIED")
    rows=[]
    for item in child_results:
        status=item.get("status","UNVERIFIED")
        evidence=item.get("evidence","Unverified")
        rows.append({
            "company_id":item.get("company_id",""),
            "environment":item.get("environment",""),
            "child_operation_id":item.get("child_operation_id",""),
            "status":status if status in known else "UNVERIFIED",
            "evidence":evidence if evidence in
                       ("Observed","Verified","Unverified","Stale") else "Unverified",
        })
    complete=bool(rows) and all(x["status"] in (
        "COMPLETED","FAILED","REJECTED") for x in rows)
    verified=bool(rows) and all(x["status"]=="COMPLETED"
        and x["evidence"]=="Verified" for x in rows) and all(
        item.get("verified_receipt_check") is True for item in child_results)
    return {
        "contract":"mad4b.faore.fleet-report.v1",
        "parent_operation_id":parent_id,
        "children":rows,
        "complete":complete,
        "verified":verified,
        "company_data_included":False,
    }
