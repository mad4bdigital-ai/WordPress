#!/usr/bin/env python3
"""Verify the EXISTING independent Original313 authority's Host receipt.

NO SIGNER. NO issuer, job claim, publication, key generation or Finalizer
registration. Re-reads installed root-owned Owner and actual Host postimages,
then verifies an independent first-party 313 Ed25519 signature.
"""
from __future__ import annotations

import base64
import re
import time
from pathlib import Path

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

import native_host_source_ownership as owner
import native_host_source_readback as readback
import native_source_publisher as publisher

CONTRACT='mad4b.native-host-source-original313-receipt.v1'
EXPECTED={
    'contract','issuer','state','operation_id','host_id','job_id',
    'owner_id','owner_catalog_sha256','plan_sha256','git_commit',
    'output_sha256','file_count','signed_master_broker_verified',
    'issued_epoch','expires_epoch','nonce','signature'
}
HEX32=re.compile(r'^[a-f0-9]{32}$')


class Host313ReceiptDenied(owner.HostSourceDenied):
    pass


def reject(reason:str):
    raise Host313ReceiptDenied(reason)


def verify_original313_receipt(plan:dict, receipt_root:Path, job_nonce:str,
                               signed_receipt:dict, *, now:int|None=None)->dict:
    """Only a SHA-pinned existing Original313 key may attest real Host bytes."""
    instant=int(time.time()) if now is None else now
    index=publisher.original_registry()
    key=getattr(index,'independent_verifier_public_key',None)
    if not isinstance(key,bytes) or len(key)!=32:
        reject('HOST313_FIRST_PARTY_INDEPENDENT_SIGNER_NOT_ENROLLED')
    policy=publisher.checked_policy()
    try:
        master=base64.b64decode(policy['master_approval_public_key_b64'],validate=True)
        broker=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    except (KeyError,TypeError,ValueError) as exc:
        raise Host313ReceiptDenied('HOST313_ROOT_APPROVAL_SIGNERS_INVALID') from exc
    host312=getattr(index,'host_resource_312_public_key',None)
    if (not isinstance(host312,bytes) or len(host312)!=32 or
        len(master)!=32 or len(broker)!=32 or
        len({key,host312,master,broker})!=4):
        reject('HOST313_SIGNER_NOT_INDEPENDENT_OF_312_MASTER_BROKER')
    if not isinstance(signed_receipt,dict) or set(signed_receipt)!=EXPECTED:
        reject('HOST313_SIGNED_RECEIPT_SHAPE_INVALID')
    r=signed_receipt
    try:
        manifest=index.resolve(r.get('operation_id'))
    except (KeyError,ValueError,TypeError) as exc:
        raise Host313ReceiptDenied('HOST313_FIRST_PARTY_RESOURCE_NOT_ENROLLED') from exc
    if (manifest.get('source_family')!='mad4b.native-host-source-release.v1' or
        manifest.get('plan_contract')!=plan.get('contract') or
        manifest.get('target_scope')!='host' or
        manifest.get('resource_kind')!='host_root_file' or
        manifest.get('job_type')!='signed_vps_host_source'):
        reject('HOST313_ENROLLED_RESOURCE_CONTRACT_CHANGED')
    if (r['contract']!=CONTRACT or
        r['issuer']!='mad4b-original313-independent' or
        r['state']!='HOST_SOURCE_RELEASE_POSTIMAGE_VERIFIED' or
        r['owner_id']!=plan.get('owner_id') or
        r['owner_catalog_sha256']!=plan.get('owner_catalog_sha256') or
        r['plan_sha256']!=plan.get('plan_sha256') or
        r['git_commit']!=plan.get('git_commit') or
        r.get('host_id')!=plan.get('scope','').removeprefix('host:') or
        r.get('nonce')!=job_nonce or
        r['signed_master_broker_verified'] is not True or
        not isinstance(r['job_id'],str) or not HEX32.fullmatch(r['job_id']) or
        not isinstance(r['nonce'],str) or not HEX32.fullmatch(r['nonce']) or
        not isinstance(r['output_sha256'],str) or
        not owner.SHA.fullmatch(r['output_sha256']) or
        type(r['file_count']) is not int or r['file_count']<=0 or
        type(r['issued_epoch']) is not int or
        type(r['expires_epoch']) is not int or
        not instant-45<=r['issued_epoch']<=instant or
        not instant+10<r['expires_epoch']<=instant+300):
        reject('HOST313_SIGNED_RECEIPT_NOT_BOUND_OR_EXPIRED')
    # Signature must verify BEFORE accepting the receipt's statements.
    try:
        public=Ed25519PublicKey.from_public_bytes(key)
        signature=base64.b64decode(r['signature'],validate=True)
        public.verify(signature,(CONTRACT+'\n').encode()+owner.canon(
            {k:v for k,v in r.items() if k!='signature'}))
    except (InvalidSignature,ValueError,TypeError) as exc:
        raise Host313ReceiptDenied('HOST313_INDEPENDENT_SIGNATURE_INVALID') from exc
    catalog=owner.checked_host_catalog()
    observed=readback.independent_readback(plan,catalog,receipt_root,job_nonce)
    if (observed.get('state')!='HOST_READBACK_VERIFIED_PENDING_ORIGINAL_313' or
        observed.get('original_313_signed_receipt') is not False or
        observed.get('plan_sha256')!=plan['plan_sha256'] or
        observed.get('output_sha256')!=r['output_sha256'] or
        observed.get('file_count')!=r['file_count']):
        reject('HOST313_POSTIMAGE_NOT_INDEPENDENTLY_VERIFIED')
    # This reports verification of a PROVIDED originally signed receipt,
    # never claims that the verifier itself has issued or published one.
    return {
        'contract':'mad4b.native-host-source-original313-verified.v1',
        'state':'SIGNED_ORIGINAL313_POSTIMAGE_VERIFIED',
        'job_id':r['job_id'],'plan_sha256':plan['plan_sha256'],
        'owner_id':plan['owner_id'],
        'output_sha256':observed['output_sha256'],
        'original_313_receipt_signature_verified':True,
        'receipt_generated_by_this_process':False,
        'host_files_modified_by_this_process':False,
    }
