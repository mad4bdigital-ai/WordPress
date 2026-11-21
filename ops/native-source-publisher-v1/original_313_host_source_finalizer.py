#!/usr/bin/env python3
"""Original313 Host finalizer: independently sign verified, actual postimages.

Root-only and non-CLI. No caller-selected key, no WordPress account pins, no
Host mutation, approval or grant issuance. First-party handler must pass an
already executed exact original306/312 transaction and independently signed
Master/Broker approvals. Result is a ONE-TIME root-protected signed receipt.
"""
from __future__ import annotations
import base64
import hashlib
import json
import os
from pathlib import Path
import stat
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.serialization import Encoding,PublicFormat

import native_host_source_ownership as owner
import native_host_source_readback as readback
import native_host_source_final_receipt as receipt
import native_source_publisher as publisher

ORIGINAL313_KEY=Path('/opt/mad4b-company-transport/native-313-independent/root-only-verifier-ed25519.key')

class Host313FinalizeDenied(owner.HostSourceDenied):pass

def require(ok,why):
    if not ok:raise Host313FinalizeDenied(why)

def original313_signer():
    """Original root-enrolled independent signer; NEVER generates a key."""
    require(os.geteuid()==0,'HOST313_FINALIZER_ROOT_REQUIRED')
    keyfile=ORIGINAL313_KEY
    require(not keyfile.is_symlink() and keyfile.is_file(),
            'HOST313_FIRSTPARTY_SIGNER_NOT_ENROLLED')
    statinfo=keyfile.stat()
    require(stat.S_ISREG(statinfo.st_mode) and statinfo.st_uid==0 and
            statinfo.st_gid==0 and statinfo.st_nlink==1 and
            stat.S_IMODE(statinfo.st_mode)==0o600 and statinfo.st_size==32,
            'HOST313_SIGNER_NOT_ROOT_PRIVATE')
    key=Ed25519PrivateKey.from_private_bytes(keyfile.read_bytes())
    index=publisher.original_registry()
    original_public=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    require(original_public==index.independent_verifier_public_key,
            'HOST313_SIGNER_NOT_MATCHING_ORIGINAL_ROOT_REGISTRY')
    host_public=getattr(index,'host_resource_312_public_key',None)
    policy=publisher.checked_policy()
    master=base64.b64decode(policy['master_approval_public_key_b64'],validate=True)
    broker=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    require(all(isinstance(x,bytes) and len(x)==32 for x in (host_public,master,broker))
            and len({original_public,host_public,master,broker})==4,
            'HOST313_INDEPENDENT_ORIGINAL_SIGNERS_COLLIDE')
    return key


def finalize_signed_host_postimage(plan:dict, *, original_job:dict,
          original_pending:dict, original312_signed_proof:dict,
          signed_master_approval:dict, signed_broker_grant:dict,
          receipt_root:Path, nonce:str, now:int|None=None)->dict:
    """Finalize only AFTER current signed claims & real Host readback.

    No synthetic admission boolean can authorize signing. The original
    ticket is re-read from WP by the independent approval verifier.
    """
    require(os.geteuid()==0,'HOST313_FINALIZER_ROOT_REQUIRED')
    policy=publisher.checked_policy()
    root=Path(policy['receipt_dir'])
    require(Path(receipt_root)==root and not root.is_symlink(),
            'HOST313_RECEIPT_STORE_NOT_ORIGINAL_POLICY_ROOT')
    catalog=owner.checked_host_catalog()
    owner.verify_host_plan(plan,catalog)
    verified312=publisher.verify_original_host312_proof(
        plan,original_job,original_pending,original312_signed_proof)
    require(verified312.get('original_312_verified') is True and
            verified312.get('execution_authorized') is False and
            verified312.get('host312_signed_nonce')==nonce and
            verified312.get('job_id')==original_job.get('job_id') and
            original_pending.get('nonce')==nonce,
            'HOST313_GENUINE_ORIGINAL312_AND_NONCE_REQUIRED')
    approved=publisher.verify_original_host_master_and_broker(
        plan,verified312,signed_master_approval,signed_broker_grant)
    require(approved.get('master_approval_verified') is True and
            approved.get('broker_release_grant_verified') is True and
            approved.get('execution_authorized') is False,
            'HOST313_MASTER_ADMIN_AND_BROKER_SIGNATURES_REQUIRED')
    actual=readback.independent_readback(plan,catalog,root,nonce)
    require(actual.get('state')=='HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313' and
            actual.get('plan_sha256')==plan['plan_sha256'] and
            actual.get('owner_catalog_sha256')==plan['owner_catalog_sha256'] and
            actual.get('original_313_signed_receipt') is False and
            type(actual.get('file_count')) is int and actual['file_count']>0 and
            isinstance(actual.get('output_sha256'),str) and
            owner.SHA.fullmatch(actual['output_sha256']) is not None,
            'HOST313_ACTUAL_ROOT_POSTIMAGE_NOT_VERIFIED')
    signer=original313_signer()
    instant=int(time.time()) if now is None else now
    require(isinstance(nonce,str) and len(nonce)==32 and
            all(x in '0123456789abcdef' for x in nonce) and
            isinstance(original_job.get('expires_epoch'),int) and
            original_job['expires_epoch']>instant+25,
            'HOST313_JOB_OR_NONCE_EXPIRED')
    expiry=min(instant+120,original_job['expires_epoch']-10)
    require(expiry>instant+10,'HOST313_SIGNED_RECEIPT_EXPIRY_TOO_CLOSE')
    payload={
        'contract':receipt.CONTRACT,'issuer':'mad4b-original313-independent',
        'state':'HOST_SOURCE_RELEASE_POSTIMAGE_VERIFIED',
        'operation_id':verified312['operation_id'],
        'host_id':verified312['host_id'],
        'job_id':original_job['job_id'],
        'owner_id':plan['owner_id'],
        'owner_catalog_sha256':plan['owner_catalog_sha256'],
        'plan_sha256':plan['plan_sha256'],
        'git_commit':plan['git_commit'],
        'output_sha256':actual['output_sha256'],
        'file_count':actual['file_count'],
        'signed_master_broker_verified':True,
        'issued_epoch':instant,'expires_epoch':expiry,
        'nonce':nonce,
    }
    signed=(receipt.CONTRACT+'\n').encode()+owner.canon(payload)
    payload['signature']=base64.b64encode(signer.sign(signed)).decode()
    # Independently compare the certificate with a SECOND fresh readback.
    verified=receipt.verify_original313_receipt(
        plan,root,nonce,payload,now=instant)
    require(verified.get('original_313_receipt_signature_verified') is True and
            verified.get('output_sha256')==actual['output_sha256'],
            'HOST313_FINALIZER_INDEPENDENT_VERIFICATION_FAILED')
    # One-time write; never overwrite an issued original313 certificate.
    artifact=root/(nonce+'.host-313-signed.json')
    require(not artifact.exists() and not artifact.is_symlink(),
            'HOST313_RECEIPT_REPLAY_OR_ALREADY_FINALIZED')
    fd=os.open(artifact,os.O_WRONLY|os.O_CREAT|os.O_EXCL|os.O_NOFOLLOW,0o600)
    try:
        with os.fdopen(fd,'wb') as out:
            out.write(owner.canon(payload)+b'\n')
            out.flush();os.fsync(out.fileno())
    except BaseException:
        # Never retry signing blindly after an uncertain storage failure.
        raise
    return {'state':'SIGNED_ORIGINAL313_POSTIMAGE_VERIFIED',
            'job_id':payload['job_id'],'plan_sha256':payload['plan_sha256'],
            'output_sha256':actual['output_sha256'],
            'receipt_sha256':hashlib.sha256(artifact.read_bytes()).hexdigest(),
            'original_313_receipt_signature_verified':True,
            'host_files_modified_by_this_process':False,
            'new_master_approval_issued':False,
            'new_broker_grant_issued':False,
            'new_original312_proof_issued':False}
