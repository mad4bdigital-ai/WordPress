#!/usr/bin/env python3
"""Manifest-registered, read-only Original306 Native Source Preclaim candidate.

No Claim, reservation, signing, approval, job creation or mutation. The first
party 306 Worker must load this file through its SHA-pinned provider registry.
An immutable original CAS plan is selected by a *separate signed association*,
not by extra plan fields or a request-provided operation ID.
"""
from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import re
import stat
import time
from typing import Callable

JOB=re.compile(r"^[a-f0-9]{32}$")
SHA=re.compile(r"^[a-f0-9]{64}$")
SOURCE_REL="native-source-publisher-v1/native_source_publisher.py"
CENTRAL_REL="executor-time-cas-v1/preclaim.py"
GRANTS=Path("/var/lib/mad4b/native-source-publisher/grants")
APPROVALS=Path("/var/lib/mad4b/native-source-publisher/approvals")


class PreclaimDenied(ValueError):
    pass


def require(test:bool, reason:str) -> None:
    if not test:
        raise PreclaimDenied(reason)


def root_private_json(path:Path,parent:Path)->dict:
    require(parent.is_absolute() and parent.is_dir() and not parent.is_symlink()
            and parent.stat().st_uid==0 and not(parent.stat().st_mode & 0o077),
            "ORIGINAL_FIRST_PARTY_PRIVATE_STORE_REQUIRED")
    require(path.parent==parent and path.is_file() and not path.is_symlink(),
            "ORIGINAL_FIRST_PARTY_SIGNED_RECORD_MISSING")
    meta=path.stat()
    require(stat.S_ISREG(meta.st_mode) and meta.st_uid==0
            and not(meta.st_mode & 0o077) and 0<meta.st_size<=200_000,
            "ORIGINAL_FIRST_PARTY_SIGNED_RECORD_UNTRUSTED")
    value=json.loads(path.read_bytes())
    require(isinstance(value,dict),"ORIGINAL_SIGNED_RECORD_SHAPE_INVALID")
    return value


def pinned_dependency(index,manifest:dict,relative:str):
    """Load only a manifest-declared SHA-pinned first-party source module."""
    import registry
    records=manifest.get("dependencies",[])
    pinned=[item for item in records if item.get("path")==relative]
    require(len(pinned)==1 and
            isinstance(pinned[0].get("sha256"),str) and
            SHA.fullmatch(pinned[0]["sha256"]) is not None,
            "FIRST_PARTY_REQUIRED_DEPENDENCY_NOT_ENROLLED")
    path=index.root/relative
    raw=registry.protected_file(path,index.root,maximum=2_000_000)
    require(registry.sha(raw)==pinned[0]["sha256"],
            "FIRST_PARTY_DEPENDENCY_SOURCE_DRIFT")
    spec=importlib.util.spec_from_file_location(
        "_mad4b_signed_source_preclaim_"+pinned[0]["sha256"][:18],path)
    require(spec is not None and spec.loader is not None,
            "FIRST_PARTY_DEPENDENCY_NOT_IMPORTABLE")
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    require(Path(module.__file__)==path and
            registry.sha(registry.protected_file(path,index.root,maximum=2_000_000))==pinned[0]["sha256"],
            "FIRST_PARTY_DEPENDENCY_CHANGED_AFTER_LOAD")
    return module


def signed_operation(index,plan:dict)->tuple[str,dict]:
    """Use the existing Registry's first-party hook & independent issuer."""
    import registry
    matching={op:m for op,m in index.manifests.items()
              if m.get("plan_contract")==plan.get("contract")}
    sources={op:m for op,m in matching.items()
             if m.get("source_family")=="mad4b.native-source-release.v2"}
    require(bool(sources),"SOURCE_FAMILY_PROVIDER_NOT_ENROLLED")
    anchors={(
        m.get("hooks",{}).get("operation_association",{}).get("path"),
        m.get("hooks",{}).get("operation_association",{}).get("sha256"),
        m.get("hooks",{}).get("operation_association",{}).get("entrypoint"),
    ) for m in sources.values()}
    require(len(anchors)==1 and None not in next(iter(anchors)),
            "SOURCE_ASSOCIATION_HOOK_CONFLICT")
    trust=getattr(index,"operation_association_authority",None)
    require(isinstance(trust,dict) and isinstance(trust.get("store"),Path)
            and isinstance(trust.get("issuer_public_key_b64"),str),
            "SOURCE_SIGNED_ASSOCIATION_ISSUER_NOT_ENROLLED")
    callback=registry.load_provider_hook(index,next(iter(sorted(sources))),"operation_association")
    proof=callback(plan,index,trust["store"],trust["issuer_public_key_b64"])
    require(isinstance(proof,dict) and
            proof.get("state")=="SIGNED_PLAN_ASSOCIATION_VERIFIED_ONLY" and
            proof.get("plan_sha256")==plan.get("plan_sha256") and
            proof.get("original313_signed_receipt_issued") is False and
            proof.get("authority_issued") is False and
            proof.get("job_claimed") is False and
            proof.get("operation_id") in sources,
            "SOURCE_SIGNED_OPERATION_NOT_EXCLUSIVE")
    operation=proof["operation_id"]
    manifest=index.resolve(operation)
    require(proof.get("manifest_sha256")==registry.sha(registry.canonical(manifest)),
            "SOURCE_SIGNED_MANIFEST_DRIFT")
    return operation,manifest


def verify_claimable(index,plan:dict,job:str,command_sha:str,
                     *,now:int|None=None,
                     operation_resolver:Callable=signed_operation,
                     load_source:Callable=pinned_dependency,
                     private_reader:Callable=root_private_json,
                     wp_reader:Callable|None=None)->dict:
    """Exact queued Original305 + finalizer + signed ticket/grant PRE Claim."""
    import registry
    instant=int(time.time()) if now is None else now
    require(isinstance(plan,dict) and isinstance(job,str) and JOB.fullmatch(job)
            and isinstance(command_sha,str) and SHA.fullmatch(command_sha),
            "ORIGINAL_SOURCE_JOB_PLAN_AND_COMMAND_REQUIRED")
    # The original 305 CAS plan must NEVER be extended to select an operation.
    require("source_binding" not in plan and "operation_id" not in plan,
            "ORIGINAL_CAS_PLAN_MUTATION_DENIED")
    digest=plan.get("plan_sha256")
    require(isinstance(digest,str) and SHA.fullmatch(digest) and
            registry.sha(registry.canonical({k:v for k,v in plan.items()
                   if k not in ("plan_sha256","changed_count")}))==digest,
            "ORIGINAL_SOURCE_CAS_PLAN_CHANGED")
    op,manifest=operation_resolver(index,plan)
    require(index.command_sha256(op,digest)==command_sha,
            "ORIGINAL_SOURCE_REGISTERED_COMMAND_CHANGED")
    publisher=load_source(index,manifest,SOURCE_REL)
    publisher.validate_manifest(manifest)
    policy=publisher.checked_policy()
    augmented={**plan,publisher.BINDING_KEY:publisher.operation_binding(digest,manifest)}
    # The derived binding lives in memory and must match the signed association
    # from the root-only first-party store, not a WordPress-supplied field.
    publisher.check_plan(augmented,policy,registry=index)
    publisher.native_runtime_binding(augmented,policy)
    if wp_reader is None:
        central=load_source(index,manifest,CENTRAL_REL)
        wp_reader=central.central_read
    response=wp_reader(job,op)
    require(isinstance(response,dict)
            and response.get("cas_finalizer_enrolled") is True
            and response.get("native_dynamic_313_supported") is True
            and isinstance(response.get("job"),dict),
            "ORIGINAL_SOURCE_SIGNED_FINALIZER_NOT_ENROLLED")
    original=response["job"]
    expected={"job_id":job,"company":plan["company"],
        "environment":plan["environment"],"site_uuid":plan["site_uuid"],
        "canonical_origin":plan["origin"],"operation":"company.remote.wpcli.full",
        "target_host_id":"srv2030908","broker_host_id":"srv2030908",
        "target_scope":"wordpress","job_type":"signed_vps_wpcli",
        "command_format":"wpcli.argv.json.v1","command_sha256":command_sha,
        "domain_intent":"operations.write","state":"QUEUED_FOR_VPS_BROKER_CLAIM",
        "executed":False}
    require(all(original.get(k)==v for k,v in expected.items()) and
            type(original.get("expires_epoch")) is int and
            original["expires_epoch"]>instant+360 and
            all(isinstance(original.get(k),str) and JOB.fullmatch(original[k])
                for k in ("grant_id","transport_id")),
            "ORIGINAL_SOURCE_305_JOB_NOT_EXACTLY_QUEUED")
    grant=private_reader(GRANTS/(job+".json"),GRANTS)
    require(grant.get("original_job_id")==job,
            "ORIGINAL_SOURCE_GRANT_NOT_JOB_BOUND")
    publisher.core.verify_grant(plan,publisher.company_policy(policy),grant,"apply")
    approval=private_reader(APPROVALS/(digest+".json"),APPROVALS)
    publisher.verify_master_approval(augmented,policy,approval)
    publisher.fresh_master_ticket_readback(augmented,policy,approval)
    return {"contract":"mad4b.original306.dynamic-provider-preclaim.v1",
            "state":"REGISTERED_PROVIDER_ORIGINAL_305_EXACT_SIGNED_PRECLAIM",
            "operation_id":op,"job_id":job,"command_sha256":command_sha,
            "company":plan["company"],"environment":plan["environment"],
            "site_uuid":plan["site_uuid"],
            "original_313_finalizer_enrolled":True,
            "provider_release_grant_verified":True,
            "new_grant_issued":False,"new_job_issued":False,
            "original_313_certified":False}
