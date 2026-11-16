#!/usr/bin/env python3
"""Original306 FIRST-PARTY Host resource claim selector; NO claims or keys.

Imported only by the existing root worker when a registry-pinned Host provider,
independent Host312 signer, root publisher policy and owner catalog are all
registered. The existing worker retains exclusive original306 Claim signing.
"""
from __future__ import annotations
import hashlib
import json
from pathlib import Path
import re
import time

import native_host_source_ownership as owner

HEX32=re.compile(r'^[a-f0-9]{32}$')
HOST_RESOURCE='mad4b.native-host-source-release.v1'
FORMAT='host.source.ref.v1'
JOB_TYPE='signed_vps_host_source'

class HostLaneDenied(ValueError):pass

def require(ok,why):
    if not ok:raise HostLaneDenied(why)


def queued_host_claim(index, catalog, policy, plan, job, pending,
                      *, now=None):
    """Select an existing WP-original306 Host Job without signing any Claim.

    The original broker claim must be signed using the pinned original306
    signer AFTER this read-only decision. Original Host312 / Master Admin /
    Broker release Grant / Original313 remain independent later stages.
    """
    instant=int(time.time()) if now is None else now
    op=job.get('operation') if isinstance(job,dict) else None
    manifest=index.resolve(op)
    require(manifest.get('source_family')==HOST_RESOURCE and
        manifest.get('target_scope')=='host' and
        manifest.get('resource_kind')=='host_root_file' and
        manifest.get('job_type')==JOB_TYPE and
        manifest.get('command',{}).get('format')==FORMAT and
        manifest.get('plan_contract')==owner.PLAN,
        'ORIGINAL306_HOST_PROVIDER_NOT_INDEPENDENTLY_ENROLLED')
    key=getattr(index,'host_resource_312_public_key',None)
    require(isinstance(key,bytes) and len(key)==32 and
        key!=getattr(index,'independent_verifier_public_key',None),
        'ORIGINAL312_HOST_SIGNER_NOT_INDEPENDENTLY_ENROLLED')
    require(isinstance(catalog,dict) and catalog.get('enabled') is True and
        isinstance(policy,dict) and policy.get('enabled') is True,
        'HOST_SOURCE_ROOT_POLICIES_NOT_ENROLLED')
    owner.catalog_data_validate(catalog)
    owner.verify_host_plan(plan,catalog)
    require(isinstance(pending,dict) and isinstance(job,dict) and
        isinstance(job.get('job_id'),str) and HEX32.fullmatch(job['job_id']) and
        pending.get('job_id')==job['job_id'] and
        pending.get('company')==job.get('company') and
        pending.get('environment')==job.get('environment') and
        pending.get('site_uuid')==job.get('site_uuid') and
        pending.get('target_host_id')==job.get('target_host_id') and
        pending.get('broker_host_id')==job.get('broker_host_id'),
        'ORIGINAL306_HOST_JOB_NOT_IN_ORIGINAL_PENDING_QUEUE')
    require(job.get('contract')=='mad4b.central-vps-broker.v1' and
        job.get('job_type')==JOB_TYPE and job.get('target_scope')=='host' and
        job.get('command_format')==FORMAT and
        job.get('state')=='QUEUED_FOR_VPS_BROKER_CLAIM' and
        job.get('executed') is False and
        job.get('independent_host_permission_verified') is False and
        job.get('owner_id')==plan['owner_id'] and
        job.get('owner_catalog_sha256')==plan['owner_catalog_sha256'] and
        job.get('plan_sha256')==plan['plan_sha256'] and
        job.get('scope')==plan['scope'] and
        job.get('host_id')==plan['scope'].split(':',1)[1] and
        job.get('target_host_id')==job['host_id'] and
        job.get('broker_host_id')==job['host_id'] and
        job.get('command_sha256')==index.command_sha256(op,plan['plan_sha256']) and
        job.get('company')=='tours-central' and
        job.get('canonical_origin')=='https://tours.dev.mad4b.com' and
        isinstance(job.get('site_uuid'),str) and
        job.get('environment')=='staging' and
        type(job.get('expires_epoch')) is int and
        instant+180<job['expires_epoch']<=instant+3600,
        'ORIGINAL306_HOST_CANONICAL_RESOURCE_NOT_BOUND')
    # This status is non-authorizing. The *original* signed Broker claim is
    # still absent. A preclaim can never be substituted for signed312 proof.
    return {
        'contract':'mad4b.original306.host-source-queued-preclaim.v1',
        'state':'EXACT_ENROLLED_HOST_JOB_ELIGIBLE_FOR_ORIGINAL306_SIGNED_CLAIM',
        'operation_id':op,'job_id':job['job_id'],
        'plan_sha256':plan['plan_sha256'],
        'owner_catalog_sha256':plan['owner_catalog_sha256'],
        'host_id':job['host_id'],
        'original306_claim_signed':False,
        'original312_host_proof_verified':False,
        'master_approval_verified':False,
        'broker_grant_verified':False,
        'original313_signed_receipt':False,
        'publication_authorized':False,
    }
