#!/usr/bin/env python3
"""Fail-closed preclaim of NEW, signed, read-only historical receipt authority.

The old grant is historical evidence ONLY. This verifier never mints grants,
claims/reserves jobs, alters WordPress, updates Central 313, or executes DDL.
A fresh Original 305/306/312 job from the actual Broker is still required.
"""
from __future__ import annotations
import base64
import datetime as dt
import hashlib
import json
import os
import re
import stat
from pathlib import Path
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

OP='company.code.release.receipt.reconcile'
CONTRACT='mad4b.company-code-release.receipt-recovery-authority.v1'
POLICY=Path('/etc/mad4b/company-code-release-policy.json')
GRANTS=Path('/var/lib/mad4b/company-code-receipt-recovery/grants')
HEX32=re.compile(r'^[0-9a-f]{32}$')
HEX64=re.compile(r'^[0-9a-f]{64}$')
FIELDS={
 'contract','action','issuer','job_id','operation_id','company',
 'environment','site_uuid','origin','plan_sha256','command_sha256',
 'original_job_id','original_plan_sha256','historical_grant_sha256',
 'historical_host_receipt_sha256','original_305_grant_id',
 'original_305_transport_id','production_mutation_allowed',
 'schema_ddl_allowed','old_grant_reauthorized','original_313_certified',
 'nonce','issued_at','expires_at','signature'
}

class Denied(RuntimeError): pass

def require(ok:bool,code:str):
    if not ok:raise Denied(code)

def trusted(path:Path,root:Path,max_size=25000)->bytes:
    require(path.is_absolute() and path.is_relative_to(root)
            and not path.is_symlink() and path.is_file(),
            'RECOVERY_SIGNED_GRANT_FILE_UNTRUSTED')
    for node in (root,path.parent,path):
        require(node.exists() and not node.is_symlink() and node.stat().st_uid==0
                and node.stat().st_mode&0o077==0,
                'RECOVERY_SIGNED_GRANT_NOT_ROOT_PRIVATE')
    size=path.lstat()
    require(stat.S_ISREG(size.st_mode) and 16<size.st_size<max_size,
            'RECOVERY_SIGNED_GRANT_SIZE_INVALID')
    return path.read_bytes()

def signed_message(doc:dict)->bytes:
    raw={k:v for k,v in doc.items() if k!='signature'}
    return (CONTRACT+'\n').encode()+json.dumps(raw,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode()

def parse_utc(value:str)->dt.datetime:
    require(isinstance(value,str),'RECOVERY_TIMESTAMP_INVALID')
    try: moment=dt.datetime.fromisoformat(value.replace('Z','+00:00'))
    except ValueError as exc:raise Denied('RECOVERY_TIMESTAMP_INVALID') from exc
    require(moment.tzinfo is not None,'RECOVERY_TIMESTAMP_NOT_UTC')
    return moment.astimezone(dt.timezone.utc)

def verify_document(plan:dict,job_id:str,command_sha256:str,doc:dict,
                    key_bytes:bytes,*,now:dt.datetime|None=None)->dict:
    require(isinstance(plan,dict) and isinstance(doc,dict),
            'RECOVERY_PLAN_AND_GRANT_REQUIRED')
    require(isinstance(job_id,str) and HEX32.fullmatch(job_id) is not None
            and isinstance(command_sha256,str) and HEX64.fullmatch(command_sha256) is not None,
            'RECOVERY_JOB_OR_COMMAND_ID_INVALID')
    require(set(doc)==FIELDS,'RECOVERY_SIGNED_GRANT_EXACT_FIELDS_REQUIRED')
    require(doc.get('contract')==CONTRACT
            and doc.get('action')=='receipt_only_readback'
            and doc.get('issuer')=='mad4b-company-broker'
            and doc.get('operation_id')==OP
            and doc.get('job_id')==job_id
            and doc.get('job_id')!=plan.get('original_job_id'),
            'RECOVERY_SIGNED_GRANT_OPERATION_MISMATCH')
    for k in ('company','environment','site_uuid','origin'):
        require(doc.get(k)==plan.get(k),'RECOVERY_SCOPE_CHANGED_'+k.upper())
    require(doc.get('plan_sha256')==plan.get('plan_sha256')
            and doc.get('command_sha256')==command_sha256
            and doc.get('original_job_id')==plan.get('original_job_id')
            and doc.get('original_plan_sha256')==plan.get('original_plan_sha256')
            and doc.get('historical_grant_sha256')==plan.get('original_signed_artifact_hashes',{}).get('original_grant_sha256')
            and doc.get('historical_host_receipt_sha256')==plan.get('original_signed_artifact_hashes',{}).get('original_host_receipt_sha256'),
            'RECOVERY_ORIGINAL_EVIDENCE_SCOPE_CHANGED')
    require(doc.get('production_mutation_allowed') is False
            and doc.get('schema_ddl_allowed') is False
            and doc.get('old_grant_reauthorized') is False
            and doc.get('original_313_certified') is False,
            'RECOVERY_GRANT_CANNOT_PERMIT_MUTATIONS')
    require(isinstance(doc.get('nonce'),str) and HEX32.fullmatch(doc['nonce']) is not None,
            'RECOVERY_GRANT_NONCE_INVALID')
    require(isinstance(doc.get('original_305_grant_id'),str)
            and isinstance(doc.get('original_305_transport_id'),str)
            and 1<=len(doc['original_305_grant_id'])<=128
            and 1<=len(doc['original_305_transport_id'])<=128,
            'RECOVERY_ORIGINAL_305_TRANSPORT_MISSING')
    issue=parse_utc(doc['issued_at']);expiry=parse_utc(doc['expires_at'])
    instant=now or dt.datetime.now(dt.timezone.utc)
    require(instant.tzinfo is not None,'RECOVERY_CLOCK_INVALID')
    instant=instant.astimezone(dt.timezone.utc)
    require(issue<=instant<expiry and (instant-issue)<=dt.timedelta(minutes=2)
            and dt.timedelta(seconds=120)<=(expiry-issue)<=dt.timedelta(minutes=15)
            and (expiry-instant)>=dt.timedelta(seconds=120),
            'RECOVERY_NEW_SIGNED_GRANT_NOT_FRESH')
    try:
        key=Ed25519PublicKey.from_public_bytes(key_bytes)
        signature=base64.b64decode(doc['signature'],validate=True)
        key.verify(signature,signed_message(doc))
    except (InvalidSignature,TypeError,ValueError,KeyError) as exc:
        raise Denied('RECOVERY_BROKER_SIGNATURE_INVALID') from exc
    return {'contract':'mad4b.company-code-release.receipt-only-preclaim.v1',
            'state':'CURRENT_SIGNED_RECEIPT_ONLY_GRANT_VERIFIED',
            'operation_id':OP,'job_id':job_id,'plan_sha256':plan['plan_sha256'],
            'command_sha256':command_sha256,
            'new_grant_signed_by_existing_broker':True,
            'old_grant_reauthorized':False,
            'new_original_312_verified':False,
            'production_mutation_allowed':False,
            'schema_ddl_allowed':False,
            'original_313_certified':False}

def root_preclaim(plan:dict,job_id:str,command_sha256:str,
                  *,grant_root:Path=GRANTS,policy_file:Path=POLICY)->dict:
    require(os.geteuid()==0,'RECOVERY_FIRST_PARTY_ROOT_REQUIRED')
    require(isinstance(job_id,str) and HEX32.fullmatch(job_id) is not None,
            'RECOVERY_NEW_JOB_ID_INVALID')
    from recovery_plan import require_exact
    require_exact(plan)
    # The original issuer is trusted only via the existing first-party Broker
    # public key; no private key or caller-supplied trusted key path accepted.
    require(policy_file==POLICY,'RECOVERY_BROKER_TRUST_PATH_CHANGED')
    policy=json.loads(trusted(policy_file,policy_file.parent,max_size=250000))
    require(policy.get('contract')=='mad4b.company-code-release-policy.v1',
            'RECOVERY_EXISTING_BROKER_POLICY_NOT_ENROLLED')
    try:key=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    except (KeyError,ValueError,TypeError) as exc:
        raise Denied('RECOVERY_BROKER_PUBLIC_KEY_INVALID') from exc
    doc=json.loads(trusted(grant_root/(job_id+'.json'),grant_root))
    return verify_document(plan,job_id,command_sha256,doc,key)
