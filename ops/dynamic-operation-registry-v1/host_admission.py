#!/usr/bin/env python3
"""Original Host-resource 306/312 admission (source-only; no issuer or writes).

A first-party 306 worker must pass an actual claimed Job and its independently
protected pending record. An original312 HOST issuer must sign a separate,
resource-bound short-lived proof. This module never claims or executes jobs.
"""
from __future__ import annotations

import base64
import json
import re
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
from cryptography.exceptions import InvalidSignature

import registry

HOST='srv2030908'
FORMAT='host.source.ref.v1'
OPERATION='host.source.release'
JOBTYPE='signed_vps_host_source'
PROOF='mad4b.original312.host-source-proof.v1'
HEX32=re.compile(r'^[a-f0-9]{32}$')


def claimed_host_job(index, operation_id:str, plan:dict, job:dict,
                     pending:dict, *, now:int|None=None)->dict:
    """Strict metadata mapping only. Does NOT authorize an operation."""
    manifest=index.resolve(operation_id)
    registry.require(manifest.get('command',{}).get('format')==FORMAT and
                     manifest.get('target_scope')=='host' and
                     manifest.get('resource_kind')=='host_root_file' and
                     manifest.get('job_type')==JOBTYPE and
                     manifest.get('source_family')=='mad4b.native-host-source-release.v1',
                     'HOST306_REGISTERED_PROVIDER_NOT_HOST_RESOURCE')
    registry.require(isinstance(plan,dict) and
                     plan.get('contract')==manifest['plan_contract'] and
                     isinstance(plan.get('plan_sha256'),str) and
                     registry.SHA.fullmatch(plan['plan_sha256']) is not None and
                     registry.sha(registry.canonical({k:v for k,v in plan.items()
                              if k!='plan_sha256'}))==plan['plan_sha256'] and
                     isinstance(plan.get('owner_id'),str) and
                     isinstance(plan.get('scope'),str) and
                     plan['scope'].startswith('host:') and
                     isinstance(plan.get('owner_catalog_sha256'),str) and
                     registry.SHA.fullmatch(plan['owner_catalog_sha256']) is not None,
                     'HOST306_PLAN_CANONICAL_IDENTITY_INVALID')
    registry.require(isinstance(job,dict) and isinstance(pending,dict) and
                     isinstance(job.get('job_id'),str) and
                     HEX32.fullmatch(job['job_id']) is not None and
                     job.get('job_id')==pending.get('job_id') and
                     job.get('owner_id')==plan['owner_id'] and
                     job.get('owner_catalog_sha256')==plan['owner_catalog_sha256'] and
                     job.get('plan_sha256')==plan['plan_sha256'] and
                     job.get('host_id')==HOST and
                     job.get('broker_host_id')==HOST and
                     job.get('target_host_id')==HOST and
                     job.get('target_scope')=='host' and
                     job.get('operation')==operation_id and
                     job.get('job_type')==JOBTYPE and
                     job.get('command_format')==FORMAT and
                     job.get('command_sha256')==index.command_sha256(operation_id,plan['plan_sha256']) and
                     job.get('state')=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY' and
                     job.get('executed') is False and
                     pending.get('contract')=='mad4b.central-vps-broker.v1' and
                     pending.get('state')=='BROKER_CLAIMED_PENDING_INDEPENDENT_HOST_AUTHORITY' and
                     pending.get('host_id')==HOST and
                     pending.get('production_mutation_performed') is False and
                     type(job.get('claimed_epoch')) is int and
                     pending.get('claimed_epoch')==job['claimed_epoch'] and
                     isinstance(pending.get('nonce'),str) and
                     HEX32.fullmatch(pending['nonce']) is not None,
                     'HOST306_ORIGINAL_SIGNED_CLAIM_NOT_BOUND')
    instant=int(time.time()) if now is None else now
    registry.require(type(job.get('expires_epoch')) is int and
                     job['expires_epoch']>instant+120 and
                     job['expires_epoch']<=instant+3600 and
                     instant-3600<job['claimed_epoch']<=instant,
                     'HOST306_CLAIM_NOT_CURRENT')
    return {
       'contract':'mad4b.original306.host-source-admission.v1',
       'state':'HOST_CLAIM_MATCHED_NEEDS_FIRST_PARTY_312',
       'job_id':job['job_id'],'operation_id':operation_id,
       'plan_sha256':plan['plan_sha256'],
       'owner_id':plan['owner_id'],
       'scope':plan['scope'],
       'owner_catalog_sha256':plan['owner_catalog_sha256'],
       'command_sha256':job['command_sha256'],
       'host_id':HOST,'original_306_claim_nonce':pending['nonce'],
       'original_312_verified':False,
       'execution_authorized':False,
    }


def verify_first_party_host312(index, candidate:dict, proof:dict,
                               *, now:int|None=None)->dict:
    """Only cryptographically verified, ORIGINAL-enrolled 312 key is valid.

    This function does NOT check Master approval or separately issued Broker
    grant, and cannot authorize execution even after a genuine 312 signature.
    """
    key=getattr(index,'host_resource_312_public_key',None)
    registry.require(isinstance(key,bytes) and len(key)==32,
                     'HOST312_ORIGINAL_SIGNER_NOT_ENROLLED')
    registry.require(isinstance(candidate,dict) and
                     candidate.get('contract')=='mad4b.original306.host-source-admission.v1' and
                     candidate.get('state')=='HOST_CLAIM_MATCHED_NEEDS_FIRST_PARTY_312' and
                     candidate.get('execution_authorized') is False and
                     candidate.get('original_312_verified') is False,
                     'HOST312_ORIGINAL_CLAIM_REQUIRED')
    required={'contract','issuer','operation','target_scope','command_format',
              'job_type','host_id','owner_id','scope','owner_catalog_sha256',
              'plan_sha256','command_sha256','job_id','nonce','issued_epoch',
              'expires_epoch','signature'}
    registry.require(isinstance(proof,dict) and set(proof)==required and
                     proof.get('contract')==PROOF and
                     proof.get('issuer')=='mad4b-original312-host-authority' and
                     proof.get('operation')==candidate.get('operation_id') and
                     proof.get('target_scope')=='host' and
                     proof.get('command_format')==FORMAT and
                     proof.get('job_type')==JOBTYPE and
                     proof.get('host_id')==HOST and
                     all(proof.get(k)==candidate.get(k) for k in (
                       'owner_id','scope','owner_catalog_sha256',
                       'plan_sha256','command_sha256','job_id')) and
                     isinstance(proof.get('nonce'),str) and
                     HEX32.fullmatch(proof['nonce']) is not None and
                     proof['nonce']==candidate.get('original_306_claim_nonce') and
                     type(proof.get('issued_epoch')) is int and
                     type(proof.get('expires_epoch')) is int,
                     'HOST312_SIGNED_RESOURCE_CONTRACT_INVALID')
    instant=int(time.time()) if now is None else now
    registry.require(instant-45<=proof['issued_epoch']<=instant and
                     instant+100<proof['expires_epoch']<=instant+300 and
                     proof['expires_epoch']-proof['issued_epoch']<=300,
                     'HOST312_SIGNED_PROOF_EXPIRED_OR_NOT_FRESH')
    body={k:v for k,v in proof.items() if k!='signature'}
    try:
        signature=base64.b64decode(proof['signature'],validate=True)
        Ed25519PublicKey.from_public_bytes(key).verify(
            signature,(PROOF+'\n').encode()+registry.canonical(body))
    except (ValueError,TypeError,InvalidSignature) as exc:
        raise registry.RegistryDenied('HOST312_INDEPENDENT_SIGNATURE_INVALID') from exc
    return {**candidate,
            'state':'HOST312_SIGNATURE_VALID_NEEDS_MASTER_BROKER_AND313',
            'original_312_verified':True,
            'execution_authorized':False,
            'master_approval_verified':False,
            'broker_release_grant_verified':False,
            'original_313_signed_receipt':False,
            'host312_signed_nonce':proof['nonce'],
            'expires_epoch':proof['expires_epoch']}
