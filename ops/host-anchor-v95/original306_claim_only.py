#!/usr/bin/env python3
"""Original306 claimed first Host Anchor Job admission: no Anchor execution.

The only authorized result of this verifier is a claim-only reservation.
All authoritative documents must come from fresh WordPress root reads and
the SHA-verified Root Registry plan. Never accept user-supplied dictionaries
as a separate permission, or infer a signed Original313 certificate.
"""
from __future__ import annotations
import hashlib
import importlib.util
import re
import time
from pathlib import Path

REGISTRY_ROOT=Path("/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled")
PLAN_ROOT=Path("/var/lib/mad4b/dynamic-operation-registry/plans")
REVISION_ROOT=Path("/var/lib/mad4b/original-host-v2/revisions")
CHECKPOINT_ROOT=Path("/var/lib/mad4b/original-host-v2/checkpoints")
ENROLLMENT_ROOT=Path("/var/lib/mad4b/original-host-v2/enrollments")
RESERVED_ROOT=Path("/opt/mad4b-company-transport/native-313-independent/reserved")
V91_REL="host-anchor-v91/original306_queued_observer.py"
V91_SHA="1867e73dab8e7c966461ec026ca7c8119d557e9369c56446c4fd5a7db5c264d3"
ISSUE="company.host-authority.anchor.issue"
HEX32=re.compile(r"[0-9a-f]{32}\Z")
HEX64=re.compile(r"[0-9a-f]{64}\Z")

class FirstClaimHold(ValueError):
    pass

def need(ok,reason):
    if not ok:raise FirstClaimHold(reason)

def verify_against_originals(index,pending,job,plan,request,grant,parent,transport,site,*,now=None):
    from registry import Registry, protected_file
    current=int(time.time()) if now is None else now
    need(type(index) is Registry and index.root==REGISTRY_ROOT
         and all(type(x) is dict for x in
                 (pending,job,plan,request,grant,parent,transport,site)),
         "FIRST_CLAIM_ORIGINAL_ROOT_INPUTS_REQUIRED")
    modulepath=REGISTRY_ROOT/V91_REL
    source=protected_file(modulepath,REGISTRY_ROOT)
    need(hashlib.sha256(source).hexdigest()==V91_SHA,
         "FIRST_CLAIM_V91_SOURCE_CHANGED")
    spec=importlib.util.spec_from_file_location("original_host_v95_v91_observer",modulepath)
    need(spec is not None and spec.loader is not None,"FIRST_CLAIM_OBSERVER_NOT_INSTALLED")
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
    inspected=m.inspect(index,pending,job,plan,now=current)
    need(inspected.get("state")=="ORIGINAL_HOST_V2_QUEUED_PLAN_VERIFIED__SIGNED_ADMISSION_HELD"
         and inspected.get("execution_authorized") is False,
         "FIRST_CLAIM_IMMUTABLE_PLAN_AND_QUEUE_REQUIRED")
    need(job.get("company")=="all-royal-egypt"
         and job.get("environment")=="staging"
         and job.get("target_host_id")=="hosting-2"
         and job.get("operation_id")==ISSUE
         and job.get("operation")==ISSUE
         and job.get("authority_mode")=="bootstrap"
         and job.get("host_v2_bootstrap_only") is True
         and job.get("state")=="QUEUED_FOR_VPS_BROKER_CLAIM"
         and job.get("executed") is False
         and type(job.get("central_principal_id")) is int
         and job["central_principal_id"]>0,
         "FIRST_CLAIM_STAGING_OWNER_BOOTSTRAP_ONLY")
    for field in ("company","environment","site_uuid","target_host_id",
                  "target_ssh_principal","command_sha256","grant_id",
                  "transport_id","central_principal_id","grant_mode",
                  "operation","command_format"):
        need(job.get(field)==request.get(field),
             "FIRST_CLAIM_SOURCE_JOB_MISMATCH:"+field)
    need(request.get("request_id")==job["job_id"]
         and request.get("status")=="ADMITTED_PENDING_INDEPENDENT_HOST_AUTHORITY"
         and request.get("execution_performed") is False
         and request.get("target_scope")=="host"
         and request.get("host_v2_bootstrap_only") is True
         and request.get("host_anchor_identity")=={
           "authority_mode":"bootstrap","operation_id":ISSUE,
           "plan_sha256":job["plan_sha256"],
           "site_uuid":job["site_uuid"],"target_host_id":"hosting-2"},
         "FIRST_CLAIM_ACTUAL_OWNER_REQUEST_REQUIRED")
    need(grant.get("grant_id")==job["grant_id"]
         and grant.get("state")=="CENTRAL_OPERATION_SCOPE_GRANTED"
         and grant.get("operation")==ISSUE
         and grant.get("company")==job["company"]
         and grant.get("environment")==job["environment"]
         and grant.get("site_uuid")==job["site_uuid"]
         and grant.get("central_principal_id")==job["central_principal_id"]
         and grant.get("transport_id")==job["transport_id"]
         and grant.get("target_host_id")=="hosting-2"
         and grant.get("grant_mode")=="persistent"
         and grant.get("principal_type")=="signed_host_principal_v2"
         and grant.get("host_v2_bootstrap_only") is True
         and grant.get("host_v2_execution_authorized") is False,
         "FIRST_CLAIM_DISTINCT_OWNER_GRANT_REQUIRED")
    need(parent.get("grant_id")==grant.get("original328_parent_grant_id")
         and parent.get("company")==job["company"]
         and parent.get("environment")==job["environment"]
         and parent.get("site_uuid")==job["site_uuid"]
         and parent.get("central_principal_id")==job["central_principal_id"]
         and parent.get("transport_id")==job["transport_id"]
         and parent.get("operation")=="company.remote.wpcli.full"
         and parent.get("state")=="CENTRAL_OPERATION_SCOPE_GRANTED"
         and transport.get("transport_id")==job["transport_id"]
         and transport.get("company")==job["company"]
         and transport.get("environment")==job["environment"]
         and transport.get("site_uuid")==job["site_uuid"]
         and transport.get("host_id")=="hosting-2"
         and transport.get("state")=="REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED"
         and site.get("original328_active") is True,
         "FIRST_CLAIM_ORIGINAL328_ACTIVE_PARENT_REQUIRED")
    anchor=site.get("anchor_readback")
    need(site.get("anchor_option_present") is False
         and type(anchor) is dict
         and anchor.get("contract")=="mad4b.original318.host-anchor-current-readback.v2"
         and anchor.get("source")=="original318_fresh_wp_readback"
         and anchor.get("company")=="all-royal-egypt"
         and anchor.get("environment")=="staging"
         and anchor.get("state")=="MISSING_OR_INVALID"
         and anchor.get("revision")==0
         and anchor.get("readback_verified") is False
         and anchor.get("write_authorized") is False,
         "FIRST_CLAIM_NO_PREVIOUS_ANCHOR_REQUIRED")
    need(plan.get("authority_mode")=="bootstrap"
         and plan.get("grant_revision")==0 and plan.get("anchor_revision")==0
         and plan.get("requested_anchor_revision")==1,
         "FIRST_CLAIM_REVISION_ZERO_REQUIRED")
    name="all-royal-egypt--staging.json"
    for directory,filename in (
        (REVISION_ROOT,name),(CHECKPOINT_ROOT,name),
        (ENROLLMENT_ROOT,name),(RESERVED_ROOT,job["job_id"]+".reserved")):
        need(not (directory/filename).exists()
             and not (directory/filename).is_symlink(),
             "FIRST_CLAIM_ROOT_AUTHORITY_ALREADY_EXISTS")
    return {
      "contract":"mad4b.original306.host-first-claim-only.v1",
      "state":"VERIFIED_ORIGINAL305_OWNER_BOOTSTRAP__CLAIM_ONLY",
      "job_id":job["job_id"],"company":job["company"],
      "environment":job["environment"],
      "plan_sha256":job["plan_sha256"],
      "original328_active":True,
      "non_authorizing_signed_broker_claim_allowed":True,
      "execution_authorized":False,"anchor_issued":False,
      "original313_certified":False,
    }
