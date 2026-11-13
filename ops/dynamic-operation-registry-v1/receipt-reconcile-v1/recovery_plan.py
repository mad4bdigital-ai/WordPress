#!/usr/bin/env python3
"""Read-only immutable receipt reconciliation request; NOT a new authorization.

This binds old signed execution evidence to a fresh exact Git file postimage.
Its hash can be input to a future distinct signed 305/306/312 operation. No
historical credential is renewed and no Central 313 receipt is issued here.
"""
from __future__ import annotations
import hashlib,json,re
from pathlib import Path
import historical_audit as historical

CONTRACT='mad4b.company-code-release.receipt-reconcile-plan.v1'
OPERATION='company.code.release.receipt.reconcile'
HEX32=re.compile(r'^[a-f0-9]{32}$')
HEX64=re.compile(r'^[a-f0-9]{64}$')

class Denied(RuntimeError):pass

def require(ok,code):
    if not ok:raise Denied(code)

def sha(data:bytes)->str:return hashlib.sha256(data).hexdigest()

def canonical(obj:dict)->bytes:
    return json.dumps(obj,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()

def load_exact_plan(job:str,sha_plan:str)->dict:
    require(isinstance(job,str) and HEX32.fullmatch(job) is not None and
            isinstance(sha_plan,str) and HEX64.fullmatch(sha_plan) is not None,
            'RECEIPT_ONLY_EXACT_IDENTIFIERS_INVALID')
    result=historical.audit_exact(job,sha_plan)
    require(result.get('state')=='HISTORICAL_SIGNED_APPLY_POSTIMAGE_VERIFIED__313_UNCERTIFIED' and
            result.get('original_313_certified') is False and
            result.get('job_replayed') is False and
            result.get('exact_live_git_postimage_verified') is True,
            'HISTORICAL_PROOF_NOT_INDEPENDENTLY_VERIFIED')
    original=historical.file_json(historical.PLAN_DIR/(sha_plan+'.json'))
    grant=historical.file_json(historical.GRANT_DIR/(job+'.json'))
    paths={
        'original_plan_file_sha256':historical.PLAN_DIR/(sha_plan+'.json'),
        'original_grant_sha256':historical.GRANT_DIR/(job+'.json'),
        'original_host_receipt_sha256':Path('/var/lib/mad4b/company-code-release/receipts')/(grant['nonce']+'.json'),
        'original_306_reservation_sha256':historical.RESERVATIONS/(job+'.reserved'),
        'original_finish_attempt_sha256':historical.FINISH_DIR/(job+'-dynamic313-finish.json')
    }
    exact_hashes={name:sha(historical.trusted_read(path)) for name,path in paths.items()}
    require(exact_hashes['original_plan_file_sha256']==sha(historical.trusted_read(paths['original_plan_file_sha256'])),
            'ORIGINAL_PLAN_HASH_UNVERIFIED')
    current=[]
    # Live WordPress files are ordinarily owned by www-data (not root).
    # Use the exact enrolled Code Release safe source reader, not the
    # root-only audit-artifact reader intended for private receipts.
    release=historical.load_module(historical.HANDLER,'receipt_only_safe_git_reader')
    for row in original['files']:
        target=Path(original['target_root'])/row['target']
        release.no_link(target)
        actual=sha(release.safe_regular(target))
        require(actual==row['source_sha256'],'CURRENT_POSTIMAGE_DRIFT')
        current.append({'target':row['target'],'expected_sha256':row['source_sha256']})
    content={
        'contract':CONTRACT,'operation_id':OPERATION,
        'company':original['company'],'environment':original['environment'],
        'site_uuid':original['site_uuid'],'origin':original['origin'],
        'original_job_id':job,'original_plan_sha256':sha_plan,
        'original_git_commit':original['git_commit'],
        'original_signed_artifact_hashes':exact_hashes,
        'exact_postimage_files':current,
        'action':'receipt_only_readback','production_mutation_allowed':False,
        'old_grant_reauthorized':False,'original_313_certified':False
    }
    return {**content,'plan_sha256':sha(canonical(content))}

def require_exact(request:dict)->dict:
    require(isinstance(request,dict) and request.get('contract')==CONTRACT and
            request.get('operation_id')==OPERATION and
            request.get('action')=='receipt_only_readback' and
            request.get('production_mutation_allowed') is False and
            request.get('old_grant_reauthorized') is False and
            request.get('original_313_certified') is False,
            'RECEIPT_ONLY_CONTRACT_REJECTED')
    expected={k:v for k,v in request.items() if k!='plan_sha256'}
    require(sha(canonical(expected))==request.get('plan_sha256'),
            'RECEIPT_ONLY_PLAN_HASH_MISMATCH')
    current=load_exact_plan(request['original_job_id'],request['original_plan_sha256'])
    require(canonical(current)==canonical(request),
            'RECEIPT_ONLY_ORIGINAL_EVIDENCE_OR_POSTIMAGE_CHANGED')
    return current
