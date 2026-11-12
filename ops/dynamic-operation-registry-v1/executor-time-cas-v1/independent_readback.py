#!/usr/bin/env python3
"""Independent Original 313 CAS postcondition *source adapter*.

This is not a signer. It evaluates the exact tenant WordPress postimage,
original job replay fence, site identity and preserved company digest.
The Original 313 finalizer must independently sign and persist the result.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re
import stat
import subprocess

CONTRACT="mad4b.company-resource-cas.original313-independent-readback.v1"
HOST_RECEIPTS=Path("/opt/mad4b-company-transport/native-313-independent/receipts")
JOB=re.compile(r"^[a-f0-9]{32}$")
SHA=re.compile(r"^[a-f0-9]{64}$")


class Hold(ValueError):
    pass


def require(ok,reason):
    if not ok:raise Hold(reason)


def verified_host_receipt(job:str,plan:dict)->dict:
    require(isinstance(job,str) and JOB.fullmatch(job) is not None,
            "ORIGINAL_313_JOB_ID_REQUIRED")
    path=HOST_RECEIPTS/(job+".cas.json")
    statdata=path.lstat()
    require(stat.S_ISREG(statdata.st_mode) and statdata.st_uid==0
            and not statdata.st_mode&0o077 and not path.is_symlink()
            and 100<statdata.st_size<5000,
            "ORIGINAL_313_ROOT_IMMUTABLE_CAS_RECEIPT_REQUIRED")
    raw=path.read_bytes()
    row=json.loads(raw)
    require(row.get("contract")=="mad4b.original-resource-cas-host-receipt.v1"
            and row.get("state")=="HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313"
            and row.get("job_id")==job
            and row.get("plan_sha256")==plan["plan_sha256"]
            and row.get("company")==plan["company"]
            and row.get("environment")==plan["environment"]
            and row.get("site_uuid")==plan["site_uuid"]
            and row.get("origin")==plan["origin"]
            and row.get("before_sha256")==plan["expected_before_sha256"]
            and row.get("after_sha256")==plan["expected_after_sha256"]
            and row.get("company_preservation_sha256")==
                plan["expected_company_preservation_sha256"]
            and row.get("resource_key")=="wordpress.post:"+plan["resource_id"]
            and row.get("original_313_certified") is False
            and row.get("target_cas_verified") is True,
            "HOST_CAS_RECEIPT_POSTIMAGE_SCOPE_CHANGED")
    return {"record":row,"native_receipt_sha256":hashlib.sha256(raw).hexdigest()}


WP_READBACK=(
  '$id=(string)getenv("MAD4B_VERIFY_CAS_RESOURCE");'
  '$job=(string)getenv("MAD4B_VERIFY_CAS_JOB");'
  '$row=MAD4B_Company_Exact_Resource_Evidence_V1::inspect("wordpress.post",$id);'
  '$once=get_option("mad4b_executor_cas_once_".$job,false);'
  'echo wp_json_encode(["resource"=>$row,"fence_sha256"=>$once],JSON_UNESCAPED_SLASHES);'
)


def exact_target_readback(
    job:str,plan:dict,container:str,actor:int,runner=subprocess.run
)->dict:
    require(isinstance(container,str) and
            re.fullmatch(r"[a-f0-9]{64}",container) is not None
            and type(actor) is int and actor>0,
            "ORIGINAL_313_EXACT_PINNED_TENANT_RUNTIME_REQUIRED")
    cmd=["/usr/bin/docker","exec","-u","33:33",
         "-e","MAD4B_VERIFY_CAS_RESOURCE="+plan["resource_id"],
         "-e","MAD4B_VERIFY_CAS_JOB="+job,
         container,"/usr/local/bin/wp","--path=/var/www/html",
         "--user="+str(actor),"eval",WP_READBACK]
    try:
        answer=runner(cmd,capture_output=True,timeout=38,check=False)
    except subprocess.TimeoutExpired as exc:
        raise Hold("ORIGINAL_313_TENANT_WP_READBACK_TIMEOUT") from exc
    require(answer.returncode==0 and not answer.stderr
            and 0<len(answer.stdout)<=6000,
            "ORIGINAL_313_TENANT_READBACK_UNAVAILABLE")
    try:
        data=json.loads(answer.stdout.strip().splitlines()[-1])
    except (ValueError,UnicodeDecodeError,IndexError) as exc:
        raise Hold("ORIGINAL_313_TENANT_READBACK_NOT_JSON") from exc
    require(isinstance(data,dict) and set(data)=={"resource","fence_sha256"},
            "ORIGINAL_313_READBACK_SHAPE_INVALID")
    observed=data["resource"]
    require(isinstance(observed,dict)
            and observed.get("state")=="EXACT_RESOURCE_READ_ONLY_OBSERVED"
            and observed.get("site_uuid")==plan["site_uuid"]
            and observed.get("canonical_origin")==plan["origin"]
            and observed.get("environment")==plan["environment"]
            and observed.get("resource_key")==
                "wordpress.post:"+plan["resource_id"]
            and observed.get("before_sha256")==plan["expected_after_sha256"]
            and observed.get("company_preservation_sha256")==
                plan["expected_company_preservation_sha256"]
            and data["fence_sha256"]==plan["new_meta_value_sha256"],
            "ORIGINAL_313_EXACT_POSTIMAGE_OR_REPLAY_FENCE_DRIFT")
    return {
        "contract":CONTRACT,
        "state":"INDEPENDENT_TARGET_CAS_POSTIMAGE_VERIFIED_UNSIGNED",
        "company":plan["company"],"environment":plan["environment"],
        "site_uuid":plan["site_uuid"],"origin":plan["origin"],
        "resource_key":observed["resource_key"],
        "before_sha256":plan["expected_before_sha256"],
        "after_sha256":plan["expected_after_sha256"],
        "company_preservation_sha256":
            plan["expected_company_preservation_sha256"],
        "original_313_signed":False
    }


def independent_verify(
    job:str,plan:dict,container:str,actor:int,runner=subprocess.run
)->dict:
    receipt=verified_host_receipt(job,plan)
    fresh=exact_target_readback(job,plan,container,actor,runner=runner)
    return {**fresh,
        "native_receipt_sha256":receipt["native_receipt_sha256"],
        "original_313_signed":False,
        "independent_source_readback_verified":True,
    }
