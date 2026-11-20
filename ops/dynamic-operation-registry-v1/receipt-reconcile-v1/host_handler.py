#!/usr/bin/env python3
"""Source-only receipt-only operation, never a code apply or 313 certifier."""
from __future__ import annotations
import hashlib,json
import recovery_plan

OPERATION='company.code.release.receipt.reconcile'


def readback(plan:dict)->dict:
    validated=recovery_plan.require_exact(plan)
    raw=recovery_plan.canonical(validated)
    return {
      'contract':'mad4b.company-release.receipt-only-readback.v1',
      'operation_id':OPERATION,
      'result_status':'RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313',
      'company':validated['company'],'environment':validated['environment'],
      'site_uuid':validated['site_uuid'],
      'original_job_id':validated['original_job_id'],
      'original_plan_sha256':validated['original_plan_sha256'],
      'recovery_plan_sha256':validated['plan_sha256'],
      'readback_sha256':hashlib.sha256(raw).hexdigest(),
      'file_count':len(validated['exact_postimage_files']),
      'source_grant_sha256':validated['original_signed_artifact_hashes']['original_grant_sha256'],
      'host_receipt_sha256':validated['original_signed_artifact_hashes']['original_host_receipt_sha256'],
      'source_reservation_sha256':validated['original_signed_artifact_hashes']['original_306_reservation_sha256'],
      'source_finish_attempt_sha256':validated['original_signed_artifact_hashes']['original_finish_attempt_sha256'],
      'original_313_certified':False,'new_recovery_grant_verified':False,
      'old_grant_reauthorized':False,'current_job_execution_eligible':False,
      'production_mutation':False,'job_replayed':False,
    }


def execute(plan:dict,*,authority:dict|None=None)->dict:
    """Do not run this source-only candidate as a 306/313 execution endpoint.

    A separately signed *current* recovery authority and Central receipt-only
    Finalizer are still needed. Missing original 312 expiry is not a reason
    to mint a local success certificate.
    """
    result=readback(plan)
    if not isinstance(authority,dict) or authority.get('contract')!='mad4b.original-recovery-312-bound-authority.v1' or authority.get('verified') is not True:
        return {**result,'state':'WAIT_CURRENT_SIGNED_RECOVERY_AUTHORITY'}
    # Even a claimed authority input cannot be trusted by this source-only
    # candidate; only the actual original Central verifier may accept it.
    return {**result,'state':'WAIT_INDEPENDENT_CENTRAL_RECOVERY_VERIFIER'}
