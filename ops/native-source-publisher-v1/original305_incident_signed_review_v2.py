#!/usr/bin/env python3
"""Original305 historical HOLD review request and Master-signature verification.

NO issuer, WordPress mutation, HOLD alteration, approval creation, or timer
activation. This documents a future *separately authorized* incident decision.
"""
from __future__ import annotations
import argparse
import base64
from datetime import datetime,timezone
import hashlib
import json
import os
from pathlib import Path
import re
import stat

from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PublicKey

HOST = Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1')
HOLD = HOST/'original305-readiness-ambiguous-write-HOLD.json'
INDEX = Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled/index.json')
SUPERVISOR = HOST/'original305_readiness_supervisor_v7.py'
SERVICE = Path('/etc/systemd/system/mad4b-original-broker-host-readiness.service')
POLICY = Path('/etc/mad4b/native-source-publisher-policy.v2.json')
REQUEST_CONTRACT = 'mad4b.original305-incident-closure-review-request.v2'
APPROVAL_CONTRACT = 'mad4b.original305-incident-closure-master-approval.v2'
ACTION = 'review_legacy_ambiguous_signed_write'
ABILITY = 'mad4b/original305-incident-review'
INDEX_PIN='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'
SUPERVISOR_PIN='dd6695c759031b37a7b1d3497c4764ac6bd4f6c1dfc53c6743a544d484f89023'
SERVICE_PIN='8cc78ec5143f0b1aa45e8a7e776138d093d33bce90d74f3cfef734221b45c471'
HEX64=re.compile(r'^[a-f0-9]{64}$')

class IncidentReviewDenied(ValueError):pass

def require(ok:bool,code:str)->None:
    if not ok:raise IncidentReviewDenied(code)

def canonical(value:dict)->bytes:
    return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False).encode('utf-8')

def digest(data:bytes)->str:return hashlib.sha256(data).hexdigest()

def root_read(path:Path,*,mode_private:bool=False,max_bytes:int=100_000)->bytes:
    fd=os.open(path,os.O_RDONLY|os.O_NOFOLLOW)
    try:
        s=os.fstat(fd)
        require(stat.S_ISREG(s.st_mode) and s.st_uid==0 and s.st_gid==0
                and s.st_nlink==1 and not s.st_mode&0o022
                and (not mode_private or not s.st_mode&0o077)
                and 0<s.st_size<=max_bytes,'INCIDENT_ROOT_FILE_UNTRUSTED')
        with os.fdopen(os.dup(fd),'rb') as f:return f.read(max_bytes+1)
    finally:os.close(fd)

def plan()->dict:
    require(os.geteuid()==0,'INCIDENT_ROOT_REQUIRED')
    raw=root_read(HOLD,mode_private=True,max_bytes=4096)
    try:marker=json.loads(raw)
    except (ValueError,TypeError):
        raise IncidentReviewDenied('INCIDENT_LEGACY_HOLD_IDENTITY_CHANGED') from None
    require(isinstance(marker,dict) and
            marker.get('contract')=='mad4b.original305-enrolled-readiness-supervisor.v1' and
            marker.get('state')=='AMBIGUOUS_SIGNED_WRITE_HOLD' and
            marker.get('reason_code')=='ISSUER_SIGNED_RESULT_UNCONFIRMED' and
            marker.get('automatic_retry_allowed') is False,
            'INCIDENT_LEGACY_HOLD_IDENTITY_CHANGED')
    register=root_read(INDEX)
    current_source=root_read(SUPERVISOR,mode_private=True,max_bytes=250_000)
    current_service=root_read(SERVICE)
    require(digest(register)==INDEX_PIN and digest(current_source)==SUPERVISOR_PIN
            and digest(current_service)==SERVICE_PIN,
            'INCIDENT_ENROLLED_SOURCE_CHANGED')
    require(b'ExecStart=/usr/bin/python3 /opt/mad4b-company-transport/native-313-independent/host-source-v1/original305_readiness_supervisor_v7.py' in current_service,
            'INCIDENT_SERVICE_NOT_BOUND_TO_REVIEW_SOURCE')
    p={
      'contract':REQUEST_CONTRACT,
      'operation_id':'host.source.release',
      'action':ACTION,
      'approval_ability':ABILITY,
      'legacy_hold_sha256':digest(raw),
      'registry_sha256':digest(register),
      'supervisor_sha256':digest(current_source),
      'service_unit_sha256':digest(current_service),
      'state':'REQUIRES_FIRSTPARTY_SIGNED_MASTER_REVIEW',
      'host_release_authorized':False,
    }
    p['request_sha256']=digest(canonical(p))
    return p

def public_master_key()->bytes:
    p=json.loads(root_read(POLICY,mode_private=True))
    require(p.get('contract')=='mad4b.native-source-publisher-policy.v2' and
            p.get('enabled') is True,'INCIDENT_MASTER_POLICY_NOT_ENROLLED')
    try:
        master=base64.b64decode(p['master_approval_public_key_b64'],validate=True)
        broker=base64.b64decode(p['broker_public_key_b64'],validate=True)
    except (KeyError,ValueError,TypeError) as e:
        raise IncidentReviewDenied('INCIDENT_MASTER_PUBLIC_PIN_INVALID') from None
    require(len(master)==32 and len(broker)==32 and master!=broker,
            'INCIDENT_MASTER_PUBLIC_PIN_INVALID')
    return master

def verify_approval(approval:dict,request:dict,public_key:bytes,*,now:int|None=None)->dict:
    fields={'contract','request_sha256','legacy_hold_sha256','registry_sha256',
            'supervisor_sha256','service_unit_sha256','issuer','approval_ability','action',
            'issued_epoch','expires_epoch','ticket_id','reviewed_by','signature'}
    require(isinstance(approval,dict) and set(approval)==fields,
            'INCIDENT_MASTER_APPROVAL_SHAPE_INVALID')
    expected={
       'contract':APPROVAL_CONTRACT,'request_sha256':request['request_sha256'],
       'legacy_hold_sha256':request['legacy_hold_sha256'],
       'registry_sha256':request['registry_sha256'],
       'supervisor_sha256':request['supervisor_sha256'],
       'service_unit_sha256':request['service_unit_sha256'],
       'issuer':'mad4b-master-admin','approval_ability':ABILITY,'action':ACTION,
    }
    require(all(approval.get(k)==v for k,v in expected.items()),
            'INCIDENT_MASTER_APPROVAL_BINDING_CHANGED')
    start=approval.get('issued_epoch');expiry=approval.get('expires_epoch')
    current=int(datetime.now(timezone.utc).timestamp()) if now is None else now
    require(type(start) is int and type(expiry) is int and
            start-30<=current<expiry and 0<expiry-start<=900,
            'INCIDENT_MASTER_APPROVAL_EXPIRED_OR_TOO_BROAD')
    require(isinstance(approval.get('ticket_id'),str) and
            bool(re.fullmatch(r'[a-zA-Z0-9_.:-]{8,90}',approval['ticket_id'])) and
            isinstance(approval.get('reviewed_by'),str) and
            bool(re.fullmatch(r'[a-zA-Z0-9_.:-]{3,100}',approval['reviewed_by'])),
            'INCIDENT_MASTER_REVIEW_TICKET_INVALID')
    payload={k:v for k,v in approval.items() if k!='signature'}
    try:
        sig=base64.b64decode(approval['signature'],validate=True)
        require(len(sig)==64,'INCIDENT_MASTER_SIGNATURE_SIZE_INVALID')
        Ed25519PublicKey.from_public_bytes(public_key).verify(
            sig,(APPROVAL_CONTRACT+'\n').encode()+canonical(payload))
    except Exception:
        raise IncidentReviewDenied('INCIDENT_MASTER_SIGNATURE_INVALID') from None
    return {'contract':'mad4b.original305-signed-review-verification.v2',
            'signature_verified':True,
            'legacy_hold_sha256':request['legacy_hold_sha256'],
            'request_sha256':request['request_sha256'],
            'approved_incident_action':ACTION,
            'incident_marker_changed':False,
            'timer_enabled':False,
            'host_release_authorized':False,
            'review_verified_not_executed':True,
            'original_wordpress_ticket_admission_verified':False,
            'incident_closed':False}

if __name__=='__main__':
    p=argparse.ArgumentParser()
    g=p.add_mutually_exclusive_group(required=True)
    g.add_argument('--plan',action='store_true')
    g.add_argument('--verify-approval',type=Path)
    args=p.parse_args()
    try:
        request=plan()
        if args.plan:result=request
        else:
            # Approval is a root-owned private source artifact, never an
            # arbitrary path read or untrusted upload used as authority.
            receipt=json.loads(root_read(args.verify_approval,
                                         mode_private=True,max_bytes=16_384))
            result=verify_approval(receipt,request,public_master_key())
        print(json.dumps(result,sort_keys=True))
    except (OSError,ValueError,KeyError,TypeError,json.JSONDecodeError) as e:
        print(json.dumps({'state':'ORIGINAL305_INCIDENT_REVIEW_NOT_VERIFIED',
                          'error_code':str(e) if isinstance(e,IncidentReviewDenied) else
                           'INCIDENT_REVIEW_INVALID_INPUT',
                          'host_release_authorized':False},sort_keys=True))
        raise SystemExit(3) from None
