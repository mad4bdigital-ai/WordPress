#!/usr/bin/env python3
"""Additive dynamic 306 worker adapter, gated by original first-party install.

The old 306 Worker may retain all legacy operations. Once FIRST-PARTY
installed, it can call digest_table() + execute_claimed(). Neither reads
arbitrary commands from Central nor executes an unclaimed original Job.
"""
from __future__ import annotations

import importlib.util
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
from typing import Any

import admission
import registry

INSTALLED_REGISTRY = Path(
    "/opt/mad4b-company-transport/native-313-independent/"
    "dynamic-operation-registry-v1/enrolled"
)
INSTALLED_PLANS = Path(
    "/var/lib/mad4b/dynamic-operation-registry/plans"
)
RESERVATIONS = Path(
    "/opt/mad4b-company-transport/native-313-independent/reserved"
)
HEX = re.compile(r"^[a-f0-9]{64}$")


def indexed_plans(index: registry.Registry, plan_store: Path) -> dict[str, dict]:
    """Return signed-command digests computed from *enrolled* immutable plans."""
    store = Path(plan_store)
    registry.require(store.is_absolute() and store.is_dir() and
                     not store.is_symlink() and store.stat().st_uid == 0 and
                     not store.stat().st_mode & 0o022,
                     "FIRST_PARTY_PLAN_STORE_NOT_INSTALLED")
    paths = sorted(store.glob("*.json"))
    registry.require(len(paths) <= 256, "DYNAMIC_PLAN_REGISTRY_LIMIT_EXCEEDED")
    rows: dict[str, dict] = {}
    for path in paths:
        digest=path.stem
        registry.require(HEX.fullmatch(digest) is not None,
                         "DYNAMIC_PLAN_FILENAME_INVALID")
        for operation, manifest in index.manifests.items():
            # The contract validator below filters mismatched plans.
            raw=registry.checked_json(path,store)
            if raw.get("contract") != manifest.get("plan_contract"):
                continue
            plan=admission.checked_plan(store,digest,manifest)
            sha=index.command_sha256(operation,plan["plan_sha256"])
            registry.require(sha not in rows,
                             "DYNAMIC_COMMAND_DIGEST_COLLISION")
            rows[sha]={"operation":operation,"plan":plan}
    return rows


def digest_table(index: registry.Registry, plan_store: Path) -> dict[str,str]:
    return {digest:row["operation"] for digest,row in
            indexed_plans(index,plan_store).items()}


def select(index: registry.Registry, store: Path,
           original_job: dict, original_pending: dict) -> dict:
    cmd=original_job.get("command_sha256")
    registry.require(isinstance(cmd,str) and HEX.fullmatch(cmd),
                     "DYNAMIC_JOB_COMMAND_MISSING")
    table=indexed_plans(index,store)
    registry.require(cmd in table, "DYNAMIC_OPERATION_NOT_REGISTERED")
    row=table[cmd]
    return admission.resolve_claimed(index,row["operation"],row["plan"],
                                     original_job,original_pending)


def receipt_only_evidence(index: registry.Registry, candidate: dict,
                          manifest: dict, plan: dict) -> dict:
    """Verify a *new* signed recovery grant, then independently reread history.

    This operation NEVER re-applies code, never sends WordPress commands,
    cannot create a 305/312 grant, and cannot certify an Original 313 receipt.
    Only the enclosing Original 306 signed Claim + fresh Original 312 authority
    can reach this function. All referenced source is First-party SHA pinned.
    """
    operation='company.code.release.receipt.reconcile'
    registry.require(candidate['operation_id']==operation and
                     candidate['plan_sha256']==plan['plan_sha256'],
                     'RECOVERY_ORIGINAL_306_OPERATION_NOT_MATCHED')
    deps={row['path']:row['sha256'] for row in manifest.get('dependencies',[])}
    source=index.root/'receipt-reconcile-v1/signed_recovery_admission.py'
    digest=deps.get('receipt-reconcile-v1/signed_recovery_admission.py')
    registry.require(isinstance(digest,str) and
                     registry.sha(registry.protected_file(
                         source,index.root,maximum=2_000_000))==digest,
                     'RECOVERY_FRESH_SIGNED_GRANT_VERIFIER_UNPINNED')
    root=str(source.parent)
    if root not in sys.path:sys.path.insert(0,root)
    import recovery_plan, signed_recovery_admission
    registry.require(Path(recovery_plan.__file__)==source.parent/'recovery_plan.py'
                     and Path(signed_recovery_admission.__file__)==source,
                     'RECOVERY_ADMISSION_IMPORTED_FROM_UNKNOWN_SOURCE')
    receipt=signed_recovery_admission.root_preclaim(
        plan,candidate['job_id'],candidate['command_sha256'])
    registry.require(receipt.get('state')=='CURRENT_SIGNED_RECEIPT_ONLY_GRANT_VERIFIED'
                     and receipt.get('new_grant_signed_by_existing_broker') is True
                     and receipt.get('original_313_certified') is False
                     and receipt.get('production_mutation_allowed') is False,
                     'RECOVERY_CURRENT_BROKER_AUTHORITY_NOT_VERIFIED')
    # The full original historical receipt, its signatures, current Git
    # postimage and the independent evidence source are verified again.
    handler=index.root/manifest['handler']['path']
    verifier=index.root/manifest['verifier']['path']
    registry.require(registry.sha(registry.protected_file(
                    handler,index.root,maximum=2_000_000))==manifest['handler']['sha256']
                     and registry.sha(registry.protected_file(
                    verifier,index.root,maximum=2_000_000))==manifest['verifier']['sha256'],
                     'RECOVERY_READBACK_HANDLERS_CHANGED')
    import host_handler, independent_readback
    registry.require(Path(host_handler.__file__)==handler and
                     Path(independent_readback.__file__)==verifier,
                     'RECOVERY_HOST_VERIFIER_NOT_PINNED')
    observation=host_handler.readback(plan)
    independently=independent_readback.verify(plan,observation)
    registry.require(independently.get('result_status')==
                     'RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313'
                     and independently.get('original_313_certified') is False
                     and independently.get('no_production_mutation') is True
                     and observation.get('job_replayed') is False
                     and observation.get('new_recovery_grant_verified') is False,
                     'RECOVERY_READBACK_CANNOT_CERTIFY_OR_REPLAY')
    return {'contract':'mad4b.original306.receipt-only-evidence.v1',
            'state':'RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313',
            'job_id':candidate['job_id'],
            'original_job_id':plan['original_job_id'],
            'plan_sha256':plan['plan_sha256'],
            'readback_sha256':observation['readback_sha256'],
            'original_313_certified':False,'production_mutation':False,
            'job_replayed':False}


def execute_claimed(original_job: dict, original_pending: dict) -> dict:
    """Installed 306 hook only, never exposed as a public WordPress Ability."""
    registry.require(os.geteuid() == 0,"ORIGINAL_ROOT_EXECUTOR_REQUIRED")
    registry.require(INSTALLED_REGISTRY.exists() and
                     INSTALLED_PLANS.exists(),
                     "FIRST_PARTY_DYNAMIC_REGISTRY_NOT_INSTALLED")
    index=registry.Registry(INSTALLED_REGISTRY)
    candidate=select(index,INSTALLED_PLANS,original_job,original_pending)
    authority=admission.fresh_original_312(
        candidate["job_id"],candidate["command_sha256"])
    checked=admission.verify_claimed_312(candidate,authority)
    operation=checked["operation_id"]
    m=index.resolve(operation)
    handler=Path(checked["handler_path"])
    registry.require(registry.sha(registry.protected_file(
        handler,index.root,maximum=2_000_000)) ==
        m["handler"]["sha256"],"HANDLER_CHANGED_AFTER_ADMISSION")
    receipt_plan=None
    if operation=='company.code.release.receipt.reconcile':
        # Recheck the fresh signed Broker Grant and read-only Host evidence
        # BEFORE creating an irreversible 306 reservation.
        receipt_plan=indexed_plans(index,INSTALLED_PLANS)[candidate['command_sha256']]['plan']
        receipt_only_evidence(index,candidate,m,receipt_plan)
    # Original job is reserved BEFORE execution, so timeout can never replay
    # an uncertain WordPress mutation. No operator may delete this as a retry.
    lock=RESERVATIONS
    registry.require(lock.is_dir() and not lock.is_symlink() and
                     lock.stat().st_uid==0 and not lock.stat().st_mode & 0o077,
                     "ORIGINAL_RESERVATION_STORE_UNTRUSTED")
    reservation=lock/(candidate["job_id"]+".reserved")
    fd=os.open(reservation,os.O_CREAT|os.O_EXCL|os.O_WRONLY|
               getattr(os,"O_NOFOLLOW",0),0o600)
    with os.fdopen(fd,"w") as stream:
        stream.write("dynamic:"+operation+" "+candidate["command_sha256"]+"\n")
        stream.flush();os.fsync(stream.fileno())
    if operation=='company.code.release.receipt.reconcile':
        # Revalidate postimage and grant after the reservation, because the
        # historical site content could drift between first read and claim.
        result=receipt_only_evidence(index,candidate,m,receipt_plan)
        registry.require(result.get('job_id')==candidate['job_id'] and
                         result.get('original_313_certified') is False and
                         result.get('job_replayed') is False,
                         'RECOVERY_READBACK_BOUND_TO_WRONG_JOB')
        return {'contract':'mad4b.dynamic-306-worker-result.v1',
                'state':'HOST_EXECUTED_PENDING_INDEPENDENT_ORIGINAL_313',
                'job_id':candidate['job_id'],'operation_id':operation,
                'receipt_only_readback_verified':True,
                'receipt_only_evidence_sha256':result['readback_sha256'],
                'original_312_verified':True,
                'original_313_certified':False,
                'grant_created':False,'central_313_receipt_created':False,
                'code_reapplied':False,'job_replayed':False,
                'production_mutation':False}
    # No shell, no argv sourced from the Job. Handler validates its own
    # exact broker release grant and pinned WordPress release policy again.
    p=subprocess.run([sys.executable,str(handler),
                      "--job",candidate["job_id"],
                      "--plan-sha256",candidate["plan_sha256"]],
                     capture_output=True,text=True,timeout=320,check=False)
    if p.returncode:
        raise registry.RegistryDenied(
            "DYNAMIC_HANDLER_FAILED_RECEIPT_RECONCILIATION_REQUIRED")
    try:
        result=json.loads(p.stdout.strip().splitlines()[-1])
    except (IndexError,ValueError) as exc:
        raise registry.RegistryDenied("DYNAMIC_HOST_RESULT_INVALID") from exc
    registry.require(result.get("state") ==
                     "HOST_APPLIED_PENDING_INDEPENDENT_ORIGINAL_313" and
                     result.get("job_id")==candidate["job_id"] and
                     result.get("plan_sha256")==candidate["plan_sha256"] and
                     result.get("original_313_certified") is False,
                     "DYNAMIC_HOST_READBACK_REQUIRED")
    return {
        "contract":"mad4b.dynamic-306-worker-result.v1",
        "state":"HOST_EXECUTED_PENDING_INDEPENDENT_ORIGINAL_313",
        "job_id":candidate["job_id"],"operation_id":operation,
        "original_312_verified":True,
        "original_313_certified":False,
        "grant_created":False,
        "central_313_receipt_created":False,
    }
