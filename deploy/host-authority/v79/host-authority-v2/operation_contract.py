"""Bounded Host Authority issuer/revoker plan contract. No private key handling."""
import hashlib,json,re

OPERATIONS=('company.host-authority.anchor.issue','company.host-authority.anchor.revoke')
CONTRACTS={'company.host-authority.anchor.issue':'mad4b.host-authority.anchor-issue.plan.v2',
           'company.host-authority.anchor.revoke':'mad4b.host-authority.anchor-revoke.plan.v2'}
HEX64=re.compile(r'^[0-9a-f]{64}$')
COMPANY=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=re.compile(r'^[a-z][a-z0-9_-]{1,39}$')
UUID=re.compile(r'^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$')
HOST=re.compile(r'^hosting-[123]$')
SSH=re.compile(r'^u[0-9]{9}$')

class Denied(ValueError):pass

def need(x,msg):
 if not x:raise Denied(msg)

def canonical(v):return json.dumps(v,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()

def verify_plan(p,operation):
 need(type(p) is dict and operation in OPERATIONS,'INVALID_PLAN_OR_OPERATION')
 need(p.get('operation_id')==operation and p.get('contract')==CONTRACTS[operation], 'PLAN_OPERATION_CONTRACT_MISMATCH')
 for k,matcher in [('company',COMPANY),('environment',ENV),('site_uuid',UUID),('target_host_id',HOST),('ssh_principal',SSH)]:
  need(type(p.get(k)) is str and matcher.fullmatch(p[k]) is not None,'PLAN_SCOPE_INVALID:'+k)
 need(type(p.get('origin')) is str and p['origin'].startswith('https://') and len(p['origin'])<=256,'PLAN_ORIGIN_INVALID')
 for k in ('grant_revision','anchor_revision'):
  need(type(p.get(k)) is int and p[k]>0,'PLAN_REVISION_INVALID:'+k)
 for k in ('policy_sha256','profile_digest','host_key_sha256','ssh_root_sha256','registry_sha256'):
  need(type(p.get(k)) is str and HEX64.fullmatch(p[k]) is not None,'PLAN_DIGEST_INVALID:'+k)
 if operation.endswith('.revoke'):
  need(type(p.get('target_anchor_nonce')) is str and re.fullmatch('[0-9a-f]{32}',p['target_anchor_nonce']) is not None,'REVOKE_ANCHOR_NONCE_REQUIRED')
 need(type(p.get('plan_sha256')) is str and HEX64.fullmatch(p['plan_sha256']) is not None,'PLAN_SHA_MISSING')
 content={k:v for k,v in p.items() if k not in ('plan_sha256','changed_count')}
 need(hashlib.sha256(canonical(content)).hexdigest()==p['plan_sha256'],'PLAN_SHA_CHANGED')
 return {'operation_id':operation,'company':p['company'],'environment':p['environment'],
         'site_uuid':p['site_uuid'],'plan_sha256':p['plan_sha256'],'write_authorized':False}
