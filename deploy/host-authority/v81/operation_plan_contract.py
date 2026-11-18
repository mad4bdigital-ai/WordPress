#!/usr/bin/env python3
"""Source-only v81 plan contract: first-anchor zero revision, same manifest type.

No first-party registry registration or execution authority. The existing v79
manifest requires its plan_contract v2; this verifier keeps that contract
stable while adding an explicit signed-in-plan bootstrap discriminator.
"""
from __future__ import annotations
import hashlib
import json
import re

ISSUE='company.host-authority.anchor.issue'
REVOKE='company.host-authority.anchor.revoke'
CONTRACTS={ISSUE:'mad4b.host-authority.anchor-issue.plan.v2',
           REVOKE:'mad4b.host-authority.anchor-revoke.plan.v2'}
UUID=re.compile(r'[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}\Z')
COMPANY=re.compile(r'[a-z][a-z0-9-]{2,47}\Z')
ENV=re.compile(r'[a-z][a-z0-9_-]{1,39}\Z')
HOST=re.compile(r'hosting-[123]\Z')
SSH=re.compile(r'u[0-9]{9}\Z')
HEX32=re.compile(r'[a-f0-9]{32}\Z')
HEX64=re.compile(r'[a-f0-9]{64}\Z')

class PlanDenied(ValueError):pass

def require(ok,code):
 if not ok:raise PlanDenied(code)

def canonical(value):
 return json.dumps(value,sort_keys=True,separators=(',',':'),
                   ensure_ascii=False).encode('utf-8')

def verify_plan(plan, operation_id):
 require(type(plan) is dict and operation_id in CONTRACTS,
         'HOST_PLAN_OPERATION_INVALID')
 require(plan.get('operation_id')==operation_id
         and plan.get('contract')==CONTRACTS[operation_id],
         'HOST_PLAN_MANIFEST_CONTRACT_CHANGED')
 for f,pat in [('company',COMPANY),('environment',ENV),('site_uuid',UUID),
               ('target_host_id',HOST),('ssh_principal',SSH)]:
  require(type(plan.get(f)) is str and pat.fullmatch(plan[f]) is not None,
          'HOST_PLAN_SCOPE_INVALID:'+f)
 require(type(plan.get('origin')) is str
         and re.fullmatch(r'https://[a-z0-9.-]{4,240}',plan['origin']) is not None,
         'HOST_PLAN_ORIGIN_INVALID')
 for f in ('policy_sha256','profile_digest','host_key_sha256',
           'ssh_root_sha256','registry_sha256'):
  require(type(plan.get(f)) is str and HEX64.fullmatch(plan[f]) is not None,
          'HOST_PLAN_DIGEST_INVALID:'+f)
 mode=plan.get('authority_mode','operational')
 require(mode in ('operational','bootstrap'),
         'HOST_PLAN_AUTHORITY_MODE_UNKNOWN')
 if mode=='bootstrap':
  require(operation_id==ISSUE
          and plan.get('authority_mode')=='bootstrap'
          and type(plan.get('grant_revision')) is int
          and plan['grant_revision']==0
          and type(plan.get('anchor_revision')) is int
          and plan['anchor_revision']==0
          and type(plan.get('requested_anchor_revision')) is int
          and plan['requested_anchor_revision']==1
          and type(plan.get('enrollment_id')) is str
          and HEX32.fullmatch(plan['enrollment_id']) is not None
          and type(plan.get('enrollment_nonce')) is str
          and HEX32.fullmatch(plan['enrollment_nonce']) is not None
          and 'target_anchor_nonce' not in plan,
          'HOST_BOOTSTRAP_FIRST_ANCHOR_EXACT_PLAN_REQUIRED')
 else:
  require(type(plan.get('grant_revision')) is int
          and plan['grant_revision']>0
          and type(plan.get('anchor_revision')) is int
          and plan['anchor_revision']>0
          and not any(f in plan for f in ('enrollment_id','enrollment_nonce',
                                         'requested_anchor_revision')),
          'HOST_OPERATION_CURRENT_REVISIONS_REQUIRED')
  if operation_id==REVOKE:
   require(type(plan.get('target_anchor_nonce')) is str
           and HEX32.fullmatch(plan['target_anchor_nonce']) is not None,
           'HOST_REVOKE_ANCHOR_NONCE_REQUIRED')
 require(type(plan.get('plan_sha256')) is str
         and HEX64.fullmatch(plan['plan_sha256']) is not None,
         'HOST_PLAN_SHA_MISSING')
 payload={k:v for k,v in plan.items() if k not in ('plan_sha256','changed_count')}
 require(hashlib.sha256(canonical(payload)).hexdigest()==plan['plan_sha256'],
         'HOST_PLAN_CONTENT_CHANGED')
 return {'operation_id':operation_id,'contract':plan['contract'],
         'authority_mode':mode,'company':plan['company'],
         'environment':plan['environment'],'site_uuid':plan['site_uuid'],
         'plan_sha256':plan['plan_sha256'],
         'first_anchor_bootstrap':mode=='bootstrap',
         'execution_authorized':False,'anchor_issued':False}
