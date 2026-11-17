#!/usr/bin/env python3
"""Original313 bounded standing-delegation verifier (NO signing/issuing).

A Master-signed, root-private, revocable, exact-target certificate authorizes
only routine front-end asset replacements. A NEW, short-lived, separately
Master-signed plan decision and original Broker grant are still required for
every plan. The issuer is a first-party authority outside this source tree.
No PHP/MU/Host/security/payment/privilege path can use this lane.
"""
from __future__ import annotations
import base64
from datetime import datetime, timedelta, timezone
import hashlib
import json
import os
from pathlib import Path
import re
import stat

CERT_PATH=Path('/etc/mad4b/native-source-standing-delegation.v1.json')
CERT_CONTRACT='mad4b.native-source-standing-delegation.v1'
DECISION_CONTRACT='mad4b.native-source-delegated-plan-decision.v1'
HEX64=re.compile(r'^[a-f0-9]{64}$')
GIT40=re.compile(r'^[a-f0-9]{40}$')
UUID=re.compile(r'^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$')
ASSET=re.compile(r'^wp-content/plugins/[a-z][a-z0-9-]{2,80}/assets/(?:[a-zA-Z0-9_.-]+/){0,6}[a-zA-Z0-9_.-]+\.(?:css|js)$')
MAX_LIFETIME=timedelta(days=30)
MAX_FILES=10
MAX_BYTES=1024*1024
CERT_KEYS={'contract','delegation_id','company','environment','site_uuid','origin',
 'operation_id','provider','approval_ability','source_manifest_sha256','issued_at',
 'expires_at','allowed_targets','max_changed_files','max_total_bytes','signature'}
DECISION_KEYS={'contract','delegation_id','certificate_sha256','plan_sha256',
 'operation_id','operation_binding_sha256','approval_ability','provider','scope',
 'site_uuid','origin','git_commit','issued_at','expires_at','nonce','signature'}
class DelegationDenied(ValueError):
    pass

def require(condition:bool,reason:str)->None:
    if not condition:raise DelegationDenied(reason)

def canonical(payload:dict)->bytes:
    return json.dumps(payload,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode()

def signed_message(prefix:str,row:dict)->bytes:
    return (prefix+'\n').encode()+canonical({k:v for k,v in row.items() if k!='signature'})

def sha(raw:bytes)->str:return hashlib.sha256(raw).hexdigest()

def utc(value:str)->datetime:
    require(isinstance(value,str) and len(value)<=36,'DELEGATION_TIME_INVALID')
    try:
        result=datetime.fromisoformat(value.replace('Z','+00:00'))
        require(result.tzinfo is not None,'DELEGATION_TIMEZONE_REQUIRED')
        return result.astimezone(timezone.utc)
    except (ValueError,TypeError) as exc:raise DelegationDenied('DELEGATION_TIME_INVALID') from exc

def verify_signature(row:dict,prefix:str,public_key_b64:str)->None:
    from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey
    try:
        public=base64.b64decode(public_key_b64,validate=True)
        signature=base64.b64decode(row['signature'],validate=True)
        require(len(public)==32 and len(signature)==64,'STANDING_CRYPTO_FORMAT_INVALID')
        Ed25519PublicKey.from_public_bytes(public).verify(signature,signed_message(prefix,row))
    except Exception as exc:
        raise DelegationDenied('STANDING_SIGNATURE_INVALID') from exc

def read_enrolled_certificate(path:Path=CERT_PATH)->dict:
    # A site setting, environment variable or caller-supplied path must never
    # be able to select a new trusted cert in executable Original313.
    require(path==CERT_PATH,'STANDING_CERTIFICATE_PATH_NOT_ENROLLED')
    st=path.lstat()
    require(stat.S_ISREG(st.st_mode) and st.st_uid==0 and
            stat.S_IMODE(st.st_mode)&0o077==0 and st.st_size<=32_768,
            'STANDING_CERTIFICATE_NOT_ROOT_PRIVATE')
    fd=os.open(path,os.O_RDONLY|getattr(os,'O_NOFOLLOW',0))
    try:
        opened=os.fstat(fd)
        require(st.st_ino==opened.st_ino and st.st_dev==opened.st_dev
            and opened.st_uid==0 and stat.S_ISREG(opened.st_mode)
            and stat.S_IMODE(opened.st_mode)&0o077==0,
            'STANDING_CERTIFICATE_CHANGED_DURING_READ')
        raw=os.read(fd,32_769)
    finally:os.close(fd)
    require(len(raw)<=32_768,'STANDING_CERTIFICATE_TOO_LARGE')
    try:result=json.loads(raw)
    except (ValueError,TypeError) as exc:raise DelegationDenied('STANDING_CERTIFICATE_INVALID_JSON') from exc
    require(isinstance(result,dict),'STANDING_CERTIFICATE_INVALID_OBJECT')
    return result

def safe_asset_target(value:object)->bool:
    if not isinstance(value,str) or ASSET.fullmatch(value) is None:
        return False
    parts=value.split('/')
    if parts[3] in ('mad4b-site-control-plane','mad4b-platform','mad4b-commerce','woocommerce-travel-bridge'):
        return False
    # A lexical path which contains '..' is unsafe even if it matches the
    # character class of a normal assets directory.
    return all(segment not in ('.','..') and not segment.startswith('.')
               for segment in parts)


def verify_certificate(cert:dict,policy:dict,*,now:datetime|None=None)->None:
    require(isinstance(cert,dict) and set(cert)==CERT_KEYS,
        'STANDING_CERTIFICATE_SCHEMA_INVALID')
    require(cert['contract']==CERT_CONTRACT and isinstance(cert['delegation_id'],str)
       and UUID.fullmatch(cert['delegation_id']) is not None,'STANDING_CERTIFICATE_ID_INVALID')
    require(all(isinstance(cert.get(k),str) and cert[k] for k in (
       'company','environment','site_uuid','origin','operation_id','provider','approval_ability')),
       'STANDING_CERTIFICATE_IDENTITY_INVALID')
    require(cert['environment'] in ('staging','production')
      and UUID.fullmatch(cert['site_uuid']) is not None
      and re.fullmatch(r'[a-z][a-z0-9-]{1,63}',cert['company']) is not None
      and cert['origin'].startswith('https://') and cert['origin'].rstrip('/')==cert['origin']
      and isinstance(cert['source_manifest_sha256'],str)
      and HEX64.fullmatch(cert['source_manifest_sha256']) is not None,
      'STANDING_CERTIFICATE_SCOPE_INVALID')
    allowed=cert['allowed_targets']
    require(isinstance(allowed,list) and 1<=len(allowed)<=20 and
      all(safe_asset_target(x) for x in allowed)
      and len(set(allowed))==len(allowed),'STANDING_ALLOWED_ASSETS_INVALID')
    count=cert['max_changed_files'];bytes_limit=cert['max_total_bytes']
    require(type(count) is int and 1<=count<=MAX_FILES and
      type(bytes_limit) is int and 1<=bytes_limit<=MAX_BYTES,
      'STANDING_RELEASE_LIMIT_EXCEEDED')
    begin=utc(cert['issued_at']);expires=utc(cert['expires_at']);clock=now or datetime.now(timezone.utc)
    require(begin<=clock<expires and timedelta(0)<expires-begin<=MAX_LIFETIME,
      'STANDING_CERTIFICATE_EXPIRED_OR_NOT_YET_ACTIVE')
    verify_signature(cert,CERT_CONTRACT,policy['master_approval_public_key_b64'])

def verify_plan_and_decision(plan:dict,policy:dict,cert:dict,decision:dict,
                             *,now:datetime|None=None)->dict:
    verify_certificate(cert,policy,now=now)
    require(isinstance(plan,dict) and isinstance(decision,dict)
            and set(decision)==DECISION_KEYS and decision['contract']==DECISION_CONTRACT,
            'STANDING_PLAN_DECISION_SHAPE_INVALID')
    bound=plan.get('source_binding')
    require(isinstance(bound,dict),'STANDING_SOURCE_BINDING_REQUIRED')
    actual={
      'delegation_id':cert['delegation_id'],
      'certificate_sha256':sha(canonical(cert)),
      'plan_sha256':plan.get('plan_sha256'),
      'operation_id':bound.get('operation_id'),
      'operation_binding_sha256':bound.get('binding_sha256'),
      'approval_ability':bound.get('approval_ability'),
      'provider':bound.get('provider'),
      'scope':str(plan.get('company',''))+':'+str(plan.get('environment','')),
      'site_uuid':plan.get('site_uuid'),
      'origin':plan.get('origin'),
      'git_commit':plan.get('git_commit'),
    }
    require(all(decision.get(key)==value for key,value in actual.items())
       and isinstance(plan.get('git_commit'),str) and GIT40.fullmatch(plan['git_commit'])
       and isinstance(plan.get('plan_sha256'),str) and HEX64.fullmatch(plan['plan_sha256'])
       and bound.get('manifest_sha256')==cert['source_manifest_sha256']
       and all(plan.get(k)==cert[k] for k in ('company','environment','site_uuid','origin'))
       and all(bound.get(k)==cert[k] for k in ('operation_id','provider','approval_ability')),
       'STANDING_CERTIFICATE_AND_PLAN_SCOPE_MISMATCH')
    require(isinstance(decision.get('nonce'),str) and UUID.fullmatch(decision['nonce']),
        'STANDING_PLAN_DECISION_NONCE_INVALID')
    clock=now or datetime.now(timezone.utc)
    begin=utc(decision['issued_at']);expires=utc(decision['expires_at'])
    require(begin<=clock<expires and timedelta(0)<expires-begin<=timedelta(minutes=15)
       and expires<=utc(cert['expires_at']),
       'STANDING_PLAN_DECISION_EXPIRED_OR_TOO_LONG')
    rows=plan.get('files')
    require(isinstance(rows,list) and 1<=len(rows)<=cert['max_changed_files'],
       'STANDING_PLAN_TOO_MANY_FILES')
    permitted=set(cert['allowed_targets']);names=[];total=0
    for row in rows:
        require(isinstance(row,dict),'STANDING_PLAN_FILE_ROW_INVALID')
        target=row.get('target');src=row.get('source')
        require(safe_asset_target(target)
           and target in permitted and src==target and target not in names,
           'STANDING_PLAN_FILE_OUT_OF_SCOPE')
        require(isinstance(row.get('expected_before_sha256'),str)
           and HEX64.fullmatch(row['expected_before_sha256']) is not None
           and isinstance(row.get('source_sha256'),str)
           and HEX64.fullmatch(row['source_sha256']) is not None
           and row['expected_before_sha256']!=row['source_sha256'],
           'STANDING_EXISTING_FILE_UPDATE_ONLY')
        size=row.get('source_bytes')
        require(type(size) is int and 1<=size<=MAX_BYTES,
            'STANDING_FILE_SIZE_INVALID')
        total+=size;names.append(target)
    require(total<=cert['max_total_bytes']
       and type(plan.get('changed_count')) is int and plan['changed_count']==len(rows),
       'STANDING_PLAN_BATCH_LIMIT_INVALID')
    # Still a first-party, separately issued per-plan signature, not an
    # automatic approval created by the untrusted update candidate.
    verify_signature(decision,DECISION_CONTRACT,policy['master_approval_public_key_b64'])
    return {'contract':'mad4b.native-source-standing-admission.v1',
      'mode':'standing_master_delegation','delegation_id':cert['delegation_id'],
      'plan_sha256':plan['plan_sha256'],'changed_files':len(rows),'changed_bytes':total,
      'requires_new_manual_decision':False,
      'independent_broker_grant_still_required':True,
      'original312_and_original313_still_required':True,
      'release_executed':False}
