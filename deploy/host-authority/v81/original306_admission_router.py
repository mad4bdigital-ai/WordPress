#!/usr/bin/env python3
"""Operation-contract-aware 306 claim classification, preserving legacy v1.

This is a source candidate for the pinned Original Worker, NOT a live installer.
No classifying step is an execution decision; Original 312 and fresh revocation
verification after one-time reservation remain mandatory.
"""
from __future__ import annotations
import re
from operation_plan_contract import verify_plan, PlanDenied
HOST_OPS=frozenset((
    "company.host-authority.anchor.issue",
    "company.host-authority.anchor.revoke",
))
JOB_V1="signed_vps_wpcli"
JOB_V2="signed_remote_hostinger_wpcli_v2"
SHA=re.compile(r"[a-f0-9]{64}\Z")
JOBID=re.compile(r"[a-f0-9]{32}\Z")

class RouteDenied(ValueError):pass

def need(ok,reason):
    if not ok:raise RouteDenied(reason)

def classify(job, operation_id, plan, expected_command_digest, *,
             registry_has_operation, original_broker_host_id):
    need(type(job) is dict and type(plan) is dict,
         "ORIGINAL306_CLAIM_EVIDENCE_MISSING")
    need(type(operation_id) is str and registry_has_operation is True,
         "ORIGINAL306_OPERATION_NOT_ENROLLED")
    need(type(expected_command_digest) is str
         and SHA.fullmatch(expected_command_digest) is not None
         and job.get("command_sha256")==expected_command_digest,
         "ORIGINAL306_COMMAND_DIGEST_MISMATCH")
    need(type(plan.get("plan_sha256")) is str
         and SHA.fullmatch(plan["plan_sha256"]) is not None,
         "ORIGINAL306_IMMUTABLE_PLAN_MISSING")
    need(type(job.get("job_id")) is str
         and JOBID.fullmatch(job["job_id"]) is not None
         and job.get("executed") is False
         and job.get("state")==
             "BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY",
         "ORIGINAL306_ORIGINAL_CLAIM_NOT_PENDING")
    for key in ("company","environment","site_uuid","origin"):
        need(type(plan.get(key)) is str and plan[key]
             and job.get(key)==plan[key],
             "ORIGINAL306_TENANT_SCOPE_MISMATCH:"+key)
    need(type(original_broker_host_id) is str and original_broker_host_id
         and job.get("broker_host_id")==original_broker_host_id,
         "ORIGINAL306_ORIGINAL_BROKER_HOST_DRIFT")
    job_type=job.get("job_type")
    if job_type==JOB_V1:
        need(operation_id not in HOST_OPS,
             "ORIGINAL306_HOST_V2_MUST_NEVER_FALL_BACK_TO_V1")
        need(job.get("operation")=="company.remote.wpcli.full"
             and job.get("target_scope")=="wordpress",
             "ORIGINAL306_V1_ORIGINAL_TARGET_DRIFT")
        route="original_legacy_v1"
    elif job_type==JOB_V2:
        try:
            plan_validated=verify_plan(plan,operation_id)
        except PlanDenied as exc:
            raise RouteDenied("ORIGINAL306_V2_PLAN_INVALID:"+str(exc)) from exc
        need(operation_id in HOST_OPS
             and job.get("operation_id")==operation_id
             and plan.get("operation_id")==operation_id
             and job.get("principal_type")=="signed_host_principal_v2"
             and job.get("target_scope")=="wordpress"
             and job.get("command_format")=="wpcli.argv.json.v1"
             and job.get("operation")=="company.remote.wpcli.full"
             and job.get("target_host_id")==plan.get("target_host_id")
             and job.get("target_ssh_principal")==plan.get("ssh_principal"),
             "ORIGINAL306_V2_TYPED_CLAIM_DRIFT")
        mode=plan_validated["authority_mode"]
        need(mode in ("bootstrap","operational"),
             "ORIGINAL306_UNKNOWN_AUTHORITY_MODE")
        if mode=="bootstrap":
            need(operation_id=="company.host-authority.anchor.issue"
                 and plan.get("grant_revision")==0
                 and plan.get("anchor_revision")==0,
                 "ORIGINAL306_FIRST_ANCHOR_BOOTSTRAP_REQUIRED")
            route="original_host_v2_first_anchor_bootstrap"
        else:
            need(type(plan.get("grant_revision")) is int
                 and plan["grant_revision"]>0
                 and type(plan.get("anchor_revision")) is int
                 and plan["anchor_revision"]>0,
                 "ORIGINAL306_CURRENT_GRANT_AND_ANCHOR_REQUIRED")
            route="original_host_v2_signed_operation"
    else:
        raise RouteDenied("ORIGINAL306_JOB_TYPE_NOT_SUPPORTED")
    return {
        "contract":"mad4b.original306.contract-route.v1",
        "operation_id":operation_id,"job_id":job["job_id"],
        "plan_sha256":plan["plan_sha256"],"command_sha256":expected_command_digest,
        "route":route,
        "state":"CLASSIFIED_PENDING_SIGNED_ORIGINAL312_AND_FRESH_REVOCATION",
        "execution_authorized":False,"original313_certified":False,
    }
