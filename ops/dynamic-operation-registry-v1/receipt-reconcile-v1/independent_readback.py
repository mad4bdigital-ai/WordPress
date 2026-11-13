#!/usr/bin/env python3
"""Independent readback comparing exact immutable receipt-only plan evidence."""
from __future__ import annotations
import hashlib
import recovery_plan

def verify(plan:dict,observation:dict)->dict:
    expected=recovery_plan.require_exact(plan)
    if not isinstance(observation,dict):raise recovery_plan.Denied('RECOVERY_READBACK_NOT_OBJECT')
    digest=hashlib.sha256(recovery_plan.canonical(expected)).hexdigest()
    if not (observation.get('contract')=='mad4b.company-release.receipt-only-readback.v1'
        and observation.get('result_status')=='RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313'
        and observation.get('operation_id')==recovery_plan.OPERATION
        and observation.get('readback_sha256')==digest
        and observation.get('recovery_plan_sha256')==expected['plan_sha256']
        and observation.get('original_job_id')==expected['original_job_id']
        and observation.get('original_plan_sha256')==expected['original_plan_sha256']
        and observation.get('site_uuid')==expected['site_uuid']
        and observation.get('company')==expected['company']
        and observation.get('environment')==expected['environment']
        and observation.get('file_count')==len(expected['exact_postimage_files'])
        and observation.get('source_grant_sha256')==expected['original_signed_artifact_hashes']['original_grant_sha256']
        and observation.get('host_receipt_sha256')==expected['original_signed_artifact_hashes']['original_host_receipt_sha256']
        and observation.get('source_reservation_sha256')==expected['original_signed_artifact_hashes']['original_306_reservation_sha256']
        and observation.get('source_finish_attempt_sha256')==expected['original_signed_artifact_hashes']['original_finish_attempt_sha256']
        and observation.get('production_mutation') is False
        and observation.get('job_replayed') is False
        and observation.get('original_313_certified') is False
        and observation.get('new_recovery_grant_verified') is False
        and observation.get('old_grant_reauthorized') is False
        and observation.get('current_job_execution_eligible') is False):
        raise recovery_plan.Denied('INDEPENDENT_RECEIPT_ONLY_READBACK_INVALID')
    return {
       'contract':'mad4b.independent-receipt-only-readback.v1',
       'result_status':'RECEIPT_ONLY_POSTIMAGE_VERIFIED_PENDING_ORIGINAL_313',
       'historical_source_and_postimage_verified':True,
       'original_313_certified':False,'new_recovery_grant_verified':False,
       'no_production_mutation':True,
       'recovery_plan_sha256':expected['plan_sha256'],
       'original_job_id':expected['original_job_id']
    }
