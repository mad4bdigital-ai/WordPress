#!/usr/bin/env python3
"""Root-only read-only classification of pending Host Anchor v2 Broker Jobs.

This is NOT an Original312 issuer, a Job claim, an execution admission or an
Original313 authority receipt. It verifies the exact typed queue/Registry
contract against the immutable root plan; all results are HOLD until separate
original owner, signed source readiness and independent original313 gates.

Not a CLI or arbitrary-job interface. The root Worker must supply only the
actual original306 Broker pending() row and read-only WordPress original Job.
"""
from __future__ import annotations
import importlib.util
from pathlib import Path
import hashlib
import re
import time

ISSUE='company.host-authority.anchor.issue'
REVOKE='company.host-authority.anchor.revoke'
OPS=frozenset((ISSUE,REVOKE))
PLAN_SOURCE_SHA='8438e3874905d75568bfc1c5c78bf5769a982834cae06a68d6aa111d080f6130'
HEX32=re.compile(r'[0-9a-f]{32}\Z')
HEX64=re.compile(r'[0-9a-f]{64}\Z')
SITE=re.compile(r'[a-z][a-z0-9-]{2,47}\Z')

class QueuedV2Denied(ValueError):pass

def need(v,reason):
    if not v:raise QueuedV2Denied(reason)

def inspect(index, original_pending, original_job, root_plan, *, now=None):
    """Prove shape/scope/plan; always nonauthorizing regardless of readiness."""
    from registry import Registry
    instant=int(time.time()) if now is None else now
    need(type(index) is Registry and type(original_pending) is dict
         and type(original_job) is dict and type(root_plan) is dict,
         'ORIGINAL306_V91_REAL_ROOT_ORIGINALS_REQUIRED')
    j=original_job
    op=j.get('operation_id')
    need(op in OPS and op==j.get('operation')
         and op==root_plan.get('operation_id'),
         'ORIGINAL306_V91_ANCHOR_TYPED_OPERATION_REQUIRED')
    m=index.resolve(op)
    need(m.get('source_family')=='mad4b.original-host-authority-anchor.v2'
         and m.get('resource_kind')=='host_anchor'
         and m.get('target_scope')=='host'
         and m.get('job_type')=='signed_remote_hostinger_wpcli_v2'
         and m.get('plan_contract')==root_plan.get('contract')
         and m.get('execution_requires_signed_originals') is True
         and m.get('command',{}).get('format')=='host.anchor.ref.v2',
         'ORIGINAL306_V91_PINNED_PROVIDER_REQUIRED')
    need(j.get('job_type')=='signed_remote_hostinger_wpcli_v2'
         and j.get('principal_type')=='signed_host_principal_v2'
         and j.get('target_scope')=='host'
         and j.get('command_format')=='host.anchor.ref.v2'
         and j.get('state')=='QUEUED_FOR_VPS_BROKER_CLAIM'
         and j.get('executed') is False
         and j.get('broker_host_id')=='srv2030908'
         and j.get('target_host_id') in ('hosting-2','hosting-3'),
         'ORIGINAL306_V91_TYPED_QUEUED_JOB_INVALID')
    for key in ('job_id','company','site_uuid','environment',
                'target_host_id','broker_host_id','expires_epoch'):
        need(type(j.get(key)) in (str,int)
             and j.get(key)==original_pending.get(key),
             'ORIGINAL306_V91_PENDING_ORIGINAL_MISMATCH:'+key)
    need(type(j.get('job_id')) is str and HEX32.fullmatch(j['job_id'])
         and type(j.get('company')) is str and SITE.fullmatch(j['company'])
         and type(j.get('expires_epoch')) is int
         and j['expires_epoch']>=instant+90,
         'ORIGINAL306_V91_QUEUE_EXPIRY_OR_ID_INVALID')
    for key,root_field in (
        ('company','company'),('environment','environment'),
        ('site_uuid','site_uuid'),('origin','origin'),
        ('target_host_id','target_host_id'),
        ('target_ssh_principal','ssh_principal'),
    ):
        need(type(j.get(key)) is str and
             j[key]==root_plan.get(root_field),
             'ORIGINAL306_V91_SOURCE_OR_PLAN_DRIFT:'+key)
    digest=j.get('plan_sha256')
    need(type(digest) is str and HEX64.fullmatch(digest)
         and digest==root_plan.get('plan_sha256')
         and type(j.get('command_sha256')) is str
         and HEX64.fullmatch(j['command_sha256'])
         and index.command_sha256(op,digest)==j['command_sha256'],
         'ORIGINAL306_V91_EXACT_COMMAND_PLAN_CHANGED')
    module_path=index.root/'host-anchor-v2/operation_plan_contract.py'
    need(module_path.is_file() and not module_path.is_symlink()
         and hashlib.sha256(module_path.read_bytes()).hexdigest()==PLAN_SOURCE_SHA,
         'ORIGINAL306_V91_PINNED_PLAN_VERIFIER_CHANGED')
    spec=importlib.util.spec_from_file_location(
        '_mad4b_host_v91_exact_plan',module_path)
    need(spec is not None and spec.loader is not None,
         'ORIGINAL306_V91_PINNED_PLAN_VERIFIER_UNAVAILABLE')
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    checked=module.verify_plan(root_plan,op)
    need(checked.get('plan_sha256')==digest
         and checked.get('execution_authorized') is False,
         'ORIGINAL306_V91_ROOT_PLAN_NOT_VALID')
    return {
        'contract':'mad4b.original306.host-v2-queued-observer.v1',
        'state':'ORIGINAL_HOST_V2_QUEUED_PLAN_VERIFIED__SIGNED_ADMISSION_HELD',
        'operation_id':op,
        'job_id':j['job_id'],
        'plan_sha256':digest,
        'command_sha256':j['command_sha256'],
        'root_provider_registered':True,
        'manifest_execution_enabled':m['execution_enabled'] is True,
        'original305_issuer_verified':False,
        'original312_job_signed':False,
        'execution_authorized':False,
        'original313_certified':False,
        'anchor_issued':False,
        'anchor_revoked':False,
        'eligible_to_claim':False,
    }
