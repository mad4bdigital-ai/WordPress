#!/usr/bin/env python3
"""First-party Original 306 CAS preclaim admission, no job claim or write.

Source-only until provider-specific Original 313 finalization is enrolled.
The Original 306 Worker invokes this module from a SHA-pinned provider manifest
before signing a 306 Claim. It reads Central Original 305 and finalizer
operational readiness independently and checks the exact Broker-signed,
root-only operation grant. Neither a stored plan nor a 312 status alone grants
permission to execute.
"""
from __future__ import annotations
import base64
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import stat
import subprocess
import time
from typing import Callable

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

ROOT=Path("/opt/mad4b-company-transport/native-313-independent")
GRANTS=Path("/var/lib/mad4b/company-resource-cas/grants")
BROKER_PUB=Path("/opt/mad4b-company-transport/broker-v1/host-ed25519-public.base64")
CENTRAL_READER=ROOT/"full-wpcli-v1/original-option-wpcli-executor.py"
OPERATION="company.resource.cas.apply"
GRANT_CONTRACT="mad4b.company-resource-cas.authorization.v1"
FIELDS=[
    "contract","issuer","action","original_job_id","operation_id",
    "company","environment","site_uuid","origin","grant_id","transport_id",
    "plan_sha256","source_plan_sha256","command_sha256","resource_key",
    "meta_key","expected_before_sha256","expected_after_sha256",
    "expected_company_preservation_sha256","issued_epoch","expires_epoch","nonce",
]
SHA=re.compile(r"^[a-f0-9]{64}$")
JOB=re.compile(r"^[a-f0-9]{32}$")


class Hold(ValueError):
    pass


def require(ok,code:str):
    if not ok:
        raise Hold(code)


def root_regular(path:Path,max_size:int=20000)->bytes:
    # A source plan or public WordPress option never replaces a protected
    # host grant/key/handler file, and symlinks cannot escape the policy root.
    st=path.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0
            and not (st.st_mode&0o077) and not path.is_symlink()
            and 0<st.st_size<=max_size,
            "ORIGINAL_ROOT_EXACT_SIGNED_GRANT_NOT_TRUSTED")
    return path.read_bytes()


def central_read(job:str,operation:str)->dict:
    """Read-only original WordPress state from the existing trusted CLI."""
    require(JOB.fullmatch(job) is not None
            and re.fullmatch(r"[a-z][a-z0-9_.-]{2,79}",operation) is not None,
            "EXACT_306_JOB_OPERATION_REQUIRED")
    st=CENTRAL_READER.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0
            and not(st.st_mode&0o022) and not CENTRAL_READER.is_symlink(),
            "ORIGINAL_CENTRAL_READER_NOT_TRUSTED")
    spec=importlib.util.spec_from_file_location(
        "mad4b_original_306_cas_readonly",CENTRAL_READER)
    require(spec is not None and spec.loader is not None,
            "ORIGINAL_CENTRAL_READER_NOT_LOADABLE")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    code=(
      '$id="'+job+'";$op="'+operation+'";'
      '$j=get_option(MAD4B_Central_VPS_Broker_V1::JOB.$id,false);'
      '$registered=class_exists("MAD4B_Dynamic_WP_313_Readback_V1",false)'
      '&&MAD4B_Dynamic_WP_313_Readback_V1::operation_operational_ready($op);'
      '$n313=class_exists("MAD4B_Native_WPCLI_Receipt_Verifier_V1",false)'
      '&&MAD4B_Native_WPCLI_Receipt_Verifier_V1::supports_dynamic_v4();'
      'echo wp_json_encode(["job"=>is_array($j)?['
      '"job_id"=>$j["job_id"]??null,"company"=>$j["company"]??null,'
      '"environment"=>$j["environment"]??null,'
      '"site_uuid"=>$j["site_uuid"]??null,'
      '"canonical_origin"=>$j["canonical_origin"]??null,'
      '"operation"=>$j["operation"]??null,'
      '"target_host_id"=>$j["target_host_id"]??null,'
      '"broker_host_id"=>$j["broker_host_id"]??null,'
      '"target_scope"=>$j["target_scope"]??null,'
      '"job_type"=>$j["job_type"]??null,'
      '"command_format"=>$j["command_format"]??null,'
      '"command_sha256"=>$j["command_sha256"]??null,'
      '"grant_id"=>$j["grant_id"]??null,'
      '"transport_id"=>$j["transport_id"]??null,'
      '"domain_intent"=>$j["domain_intent"]??null,'
      '"expires_epoch"=>$j["expires_epoch"]??null,'
      '"state"=>$j["state"]??null,"executed"=>$j["executed"]??null'
      ']:[],"cas_finalizer_enrolled"=>$registered,'
      '"native_dynamic_313_supported"=>$n313]);'
    )
    return module.central_json(["eval",code],timeout=30)


def verify_grant(job:str,sha:str,plan:dict,original:dict,
                 *,now:int|None=None)->dict:
    import registry
    instant=int(time.time()) if now is None else now
    require(JOB.fullmatch(job) is not None and SHA.fullmatch(sha) is not None,
            "EXACT_ORIGINAL_306_JOB_AND_COMMAND_REQUIRED")
    doc=json.loads(root_regular(GRANTS/(job+".json"),8192))
    require(isinstance(doc,dict) and set(doc)==set(FIELDS)|{"signature"},
            "SIGNED_CAS_RELEASE_GRANT_SCHEMA_INVALID")
    expected={
        "contract":GRANT_CONTRACT,
        "issuer":"mad4b-company-broker","action":"apply",
        "original_job_id":job,"operation_id":OPERATION,
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "grant_id":original["grant_id"],
        "transport_id":original["transport_id"],
        "plan_sha256":plan["plan_sha256"],
        "source_plan_sha256":plan["source_plan_sha256"],
        "command_sha256":sha,
        "resource_key":plan["resource_type"]+":"+plan["resource_id"],
        "meta_key":plan["meta_key"],
        "expected_before_sha256":plan["expected_before_sha256"],
        "expected_after_sha256":plan["expected_after_sha256"],
        "expected_company_preservation_sha256":
            plan["expected_company_preservation_sha256"],
    }
    require(all(doc.get(k)==v for k,v in expected.items())
            and type(doc.get("issued_epoch")) is int
            and type(doc.get("expires_epoch")) is int
            and instant-300<=doc["issued_epoch"]<=instant+10
            and instant+100<doc["expires_epoch"]<=doc["issued_epoch"]+300
            and isinstance(doc.get("nonce"),str)
            and JOB.fullmatch(doc["nonce"]) is not None,
            "ORIGINAL_306_SIGNED_CAS_RELEASE_GRANT_SCOPE_OR_TIME_CHANGED")
    public=base64.b64decode(root_regular(BROKER_PUB,128).strip(),validate=True)
    signature=base64.b64decode(doc["signature"],validate=True)
    require(len(public)==32 and len(signature)==64,
            "BROKER_CAS_GRANT_SIGNING_KEY_INVALID")
    try:
        Ed25519PublicKey.from_public_bytes(public).verify(
            signature,registry.canonical({field:doc[field] for field in FIELDS}))
    except Exception as error:
        raise Hold("BROKER_CAS_RELEASE_GRANT_SIGNATURE_INVALID") from error
    return {"contract":GRANT_CONTRACT,
            "state":"SIGNED_CAS_RELEASE_GRANT_EXACT_ORIGINAL_305_BOUND",
            "original_job_id":job,"command_sha256":sha,
            "verified":True,"grant_issued":False,"original_313_certified":False}


def verify_claimable(index,plan:dict,job:str,sha:str,
                     *,reader:Callable[[str,str],dict]=central_read,
                     grant_verifier:Callable=verify_grant,
                     now:int|None=None)->dict:
    """Read-only verifier. Never calls a 306 Claim or 313 signer."""
    instant=int(time.time()) if now is None else now
    require(JOB.fullmatch(job) is not None and SHA.fullmatch(sha) is not None,
            "ORIGINAL_306_CAS_JOB_AND_SHA_REQUIRED")
    manifest=index.resolve(OPERATION)
    require(manifest.get("operation_id")==OPERATION
            and manifest.get("plan_contract")==plan.get("contract")
            and index.command_sha256(OPERATION,plan.get("plan_sha256"))==sha
            and manifest.get("cas_scope",{}).get("commercial_pricing_allowed") is False
            and plan.get("resource_type")=="wordpress.post"
            and plan.get("post_type") in manifest["cas_scope"]["allowed_post_types"]
            and plan.get("meta_key") in manifest["cas_scope"]["allowed_meta_keys"],
            "ORIGINAL_306_REGISTERED_CAS_PROVIDER_SCOPE_OR_COMMAND_DRIFT")
    import registry
    canonical={k:v for k,v in plan.items()
               if k not in ("plan_sha256","changed_count")}
    require(registry.sha(registry.canonical(canonical))==plan["plan_sha256"],
            "ORIGINAL_306_CAS_PLAN_PREIMAGE_TAMPERED")
    # The Central read is a fresh verified WP-CLI Original 305 Job, not
    # a caller-provided "ready" boolean.
    response=reader(job,OPERATION)
    require(isinstance(response,dict)
            and response.get("cas_finalizer_enrolled") is True
            and response.get("native_dynamic_313_supported") is True
            and isinstance(response.get("job"),dict),
            "ORIGINAL_313_EXACT_CAS_FINALIZER_NOT_ENROLLED")
    original=response["job"]
    require(all(original.get(k)==v for k,v in (
        ("job_id",job),("company",plan["company"]),
        ("environment",plan["environment"]),("site_uuid",plan["site_uuid"]),
        ("canonical_origin",plan["origin"]),
        ("operation","company.remote.wpcli.full"),
        ("target_host_id","srv2030908"),
        ("broker_host_id","srv2030908"),("target_scope","wordpress"),
        ("job_type","signed_vps_wpcli"),
        ("command_format","wpcli.argv.json.v1"),
        ("command_sha256",sha),
        # Original 305 authorizes the delegation lane, not the provider ID.
        # Exact CAS operation and resource are independently bound by the
        # source-pinned plan, command SHA and operation-specific Broker grant.
        ("domain_intent","operations.write"),
        ("state","QUEUED_FOR_VPS_BROKER_CLAIM"),
        ("executed",False)))
        and type(original.get("expires_epoch")) is int
        and original["expires_epoch"]>instant+360
        and isinstance(original.get("grant_id"),str)
        and JOB.fullmatch(original["grant_id"]) is not None
        and isinstance(original.get("transport_id"),str)
        and JOB.fullmatch(original["transport_id"]) is not None,
        "ORIGINAL_306_EXACT_305_QUEUED_CAS_JOB_CHANGED")
    grant=grant_verifier(job,sha,plan,original,now=instant)
    require(grant.get("verified") is True and
            grant.get("original_job_id")==job and
            grant.get("command_sha256")==sha
            and grant.get("original_313_certified") is False,
            "ORIGINAL_306_OPERATION_SPECIFIC_SIGNED_GRANT_INVALID")
    return {
        "contract":"mad4b.original306.dynamic-provider-preclaim.v1",
        "state":"REGISTERED_PROVIDER_ORIGINAL_305_EXACT_SIGNED_PRECLAIM",
        "operation_id":OPERATION,
        "job_id":job,
        "command_sha256":sha,
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],
        "original_313_finalizer_enrolled":True,
        "provider_release_grant_verified":True,
        "new_grant_issued":False,
        "new_job_issued":False,
        "original_313_certified":False
    }
