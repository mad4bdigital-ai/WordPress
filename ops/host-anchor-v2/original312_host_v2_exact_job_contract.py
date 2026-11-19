#!/usr/bin/env python3
"""SOURCE ONLY: cryptographic Original 312 v2 exact-job contract verifier.

Not wired to live Original 312/313. Verifying a signature is necessary but
not sufficient: issuer/grant/anchor/transport/revocation evidence MUST come
from a separate trusted readback at admission AND at execution time.
"""
from __future__ import annotations
import base64
import hashlib
import json
import re
import time
from cryptography.exceptions import InvalidSignature
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

CONTRACT='mad4b.original312.host-principal.exact-job.v2'
FIELDS=('contract','job_id','company','environment','site_uuid','origin',
 'target_host_id','ssh_principal','grant_id','grant_revision','anchor_revision',
 'operation','command_sha256','plan_sha256','nonce','issued_epoch','expires_epoch')
HEX32=re.compile(r'^[a-f0-9]{32}$')
HEX64=re.compile(r'^[a-f0-9]{64}$')
COMPANY=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=re.compile(r'^[a-z][a-z0-9_-]{1,39}$')
HOST=re.compile(r'^hosting-[123]$')
SSH=re.compile(r'^u[0-9]{9}$')
UUID=re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')

class JobDenied(ValueError):pass

def require(valid,code):
 if not valid:raise JobDenied(code)

def message(p):
 return '\n'.join(str(p[f]) for f in FIELDS).encode()

def verify_exact_job(proof:dict,public_key:bytes,claimed_job:dict,
                     authority:dict,*,now:int|None=None)->dict:
 """Pure verification: produces no execution eligibility or signed receipt.

 `authority` is an independently sourced immutable readback of the enrolled
 305/328 grant and the exact Host Anchor. Never accept it from the Job body.
 """
 instant=int(time.time()) if now is None else now
 require(type(proof) is dict and set(proof)==set(FIELDS)|{'signature'},'PROOF_SHAPE_INVALID')
 require(type(claimed_job) is dict and type(authority) is dict,'EVIDENCE_INVALID')
 require(proof['contract']==CONTRACT,'JOB_CONTRACT_INVALID')
 for f in ('job_id','grant_id','nonce'):
  require(type(proof[f]) is str and HEX32.fullmatch(proof[f]) is not None,'JOB_REFERENCE_INVALID:'+f)
 for f in ('command_sha256','plan_sha256'):
  require(type(proof[f]) is str and HEX64.fullmatch(proof[f]) is not None,'JOB_DIGEST_INVALID:'+f)
 require(type(proof['company']) is str and COMPANY.fullmatch(proof['company']) is not None,'COMPANY_INVALID')
 require(type(proof['environment']) is str and ENV.fullmatch(proof['environment']) is not None,'ENVIRONMENT_INVALID')
 require(type(proof['site_uuid']) is str and UUID.fullmatch(proof['site_uuid']) is not None,'SITE_UUID_INVALID')
 require(type(proof['origin']) is str and proof['origin'].startswith('https://') and ' ' not in proof['origin'],'ORIGIN_INVALID')
 require(type(proof['target_host_id']) is str and HOST.fullmatch(proof['target_host_id']) is not None,'HOST_INVALID')
 require(type(proof['ssh_principal']) is str and SSH.fullmatch(proof['ssh_principal']) is not None,'SSH_PRINCIPAL_INVALID')
 require(proof['operation'] in ('company.host-authority.anchor.issue','company.host-authority.anchor.revoke'),'OPERATION_UNREGISTERED')
 for f in ('grant_revision','anchor_revision','issued_epoch','expires_epoch'):
  require(type(proof[f]) is int,'JOB_TIME_OR_REVISION_TYPE_INVALID:'+f)
 require(proof['grant_revision']>0 and proof['anchor_revision']>0,'REVISION_INVALID')
 require(instant-60<=proof['issued_epoch']<=instant+10 and instant<proof['expires_epoch']<=proof['issued_epoch']+60,'PROOF_EXPIRED_OR_TOO_LONG')
 require(type(public_key) is bytes and len(public_key)==32,'BROKER_PUBLIC_KEY_INVALID')
 try:
  sig=base64.b64decode(proof['signature'],validate=True)
  require(len(sig)==64,'BROKER_SIGNATURE_LENGTH_INVALID')
  Ed25519PublicKey.from_public_bytes(public_key).verify(sig,message(proof))
 except (ValueError,InvalidSignature,TypeError) as exc:
  raise JobDenied('BROKER_SIGNATURE_INVALID') from exc
 # Original 306 Job is read from independent Original store, not from proof.
 for field,pfield in (('job_id','job_id'),('company','company'),('environment','environment'),
       ('site_uuid','site_uuid'),('origin','origin'),('target_host_id','target_host_id'),
       ('target_ssh_principal','ssh_principal'),('command_sha256','command_sha256'),
       ('grant_id','grant_id'),('operation_id','operation')):
  require(claimed_job.get(field)==proof[pfield],'ORIGINAL_JOB_SCOPE_OR_COMMAND_MISMATCH:'+field)
 require(claimed_job.get('principal_type')=='signed_host_principal_v2'
   and claimed_job.get('state')=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY'
   and claimed_job.get('executed') is False
   and claimed_job.get('plan_sha256')==proof['plan_sha256'], 'ORIGINAL_JOB_CLAIM_NOT_ELIGIBLE')
 # Pre-existing 305/328 records must be independently verified; a claimed
 # host policy or stale signed identity must not count as an active grant.
 require(authority.get('original305_signed_grant_verified') is True
   and authority.get('original328_signed_activation_verified') is True
   and authority.get('original318_anchor_v2_verified') is True
   and authority.get('grant_revoked') is False
   and authority.get('anchor_revoked') is False
   and authority.get('target_write_policy_authorized') is True
   and authority.get('scope_matches_current_host') is True,
   'INDEPENDENT_ORIGINAL_AUTHORITY_REQUIRED')
 for field,pfield in (('job_id','job_id'),('company','company'),('environment','environment'),
     ('site_uuid','site_uuid'),('origin','origin'),('target_host_id','target_host_id'),
     ('ssh_principal','ssh_principal'),('grant_id','grant_id'),
     ('grant_revision','grant_revision'),('anchor_revision','anchor_revision'),
     ('command_sha256','command_sha256')):
  require(authority.get(field)==proof[pfield],'AUTHORITY_GRANT_OR_ANCHOR_DRIFT:'+field)
 require(type(authority.get('expires_epoch')) is int
    and authority['expires_epoch']>instant,'ORIGINAL_GRANT_EXPIRED')
 return {'contract':CONTRACT,'state':'EXACT_SIGNATURE_AND_AUTHORITY_EVIDENCE_MATCHED__ORIGINAL_EXECUTION_ADMISSION_PENDING',
    'job_id':proof['job_id'],'company':proof['company'],'environment':proof['environment'],
    'site_uuid':proof['site_uuid'],'command_sha256':proof['command_sha256'],
    'signature_verified':True,'independent_authority_readback_matched':True,
    'execution_authorized':False,'original313_execution_certified':False,
    'grant_created':False,'production_mutated':False}
