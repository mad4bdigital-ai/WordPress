#!/usr/bin/env python3
"""Readiness-only original Broker extension for the enrolled Central Host.

This calls the EXISTING original Broker key API; no key is created, exported,
read into logs, or exposed as a general signer. Only a fixed, short-lived
Host readiness assertion can be signed, never a job, Master approval, grant,
Original312 proof, or Original313 final receipt.
"""
from __future__ import annotations
import argparse
import base64
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import stat
import subprocess
import time
from cryptography.hazmat.primitives.serialization import Encoding, PublicFormat

import original305_host_unsigned_readiness_request as handoff

ROOT=Path('/opt/mad4b-company-transport')
BROKER=ROOT/'broker-v1/native-vps-client-principal.py'
PUBLISHER_POLICY=Path('/etc/mad4b/native-source-publisher-policy.v2.json')
LOCK=Path('/opt/mad4b-company-transport/native-313-independent/host-source-v1/original305-broker-readiness-refresh.lock')
WP_OPTION='mad4b_original_host_source_readiness_v1'
CONTRACT=handoff.CONTRACT

class BrokerReadinessDenied(ValueError):pass

def require(test,reason):
    if not test:raise BrokerReadinessDenied(reason)

def canonical(payload):
    return json.dumps(payload,sort_keys=True,separators=(',',':'),
                      ensure_ascii=False).encode('utf-8')

def original_broker():
    require(os.geteuid()==0,'FIRSTPARTY_ORIGINAL_BROKER_ROOT_REQUIRED')
    require(BROKER.is_file() and not BROKER.is_symlink() and
            BROKER.stat().st_uid==0 and not BROKER.stat().st_mode&0o077,
            'ORIGINAL_BROKER_CODE_NOT_ROOT_OWNED')
    spec=importlib.util.spec_from_file_location('mad4b_original_broker_readiness',BROKER)
    require(spec is not None and spec.loader is not None,'ORIGINAL_BROKER_IMPORT_FAILED')
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    require(all(callable(getattr(module,name,None)) for name in
        ('authority','original_signer','check_original_wordpress_signer',
         'host_fingerprint','verify_original_policy_readback','original_central_wp')),
        'ORIGINAL_BROKER_FIRSTPARTY_API_INCOMPLETE')
    return module

def firstparty_public_readback(broker)->dict:
    """Check the existing Broker identity without requesting a private signer.

    A public-key comparison proves infrastructure identity, NOT that a
    Master Admin ticket or Host release grant has been issued.
    """
    policy_sha=broker.authority()
    fingerprint=broker.host_fingerprint()
    require(broker.verify_original_policy_readback(policy_sha,fingerprint) is True,
            'ORIGINAL_BROKER_PUBLIC_HOST_LEDGER_NOT_CURRENT')
    policy=json.loads(PUBLISHER_POLICY.read_bytes())
    require(policy.get('enabled') is True and
            policy.get('central_approval_scope')=='tours-central:staging',
            'ORIGINAL_BROKER_HOST_PUBLISHER_POLICY_NOT_ENROLLED')
    pinned=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    require(len(pinned)==32,'ORIGINAL_BROKER_HOST_PUBLIC_PIN_INVALID')
    php="""$a=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
$b=base64_decode((string)get_option('mad4b_central_broker_signing_public_v1',''),true);
echo wp_json_encode(['home'=>get_option('home'),
 'environment'=>wp_get_environment_type(),
 'broker'=>is_string($a)?base64_encode($a):null,
 'original'=>is_string($b)?base64_encode($b):null]);"""
    try:
        result=subprocess.run(broker.original_central_wp()+['eval',php],
                              text=True,capture_output=True,timeout=32)
    except (OSError,subprocess.SubprocessError) as exc:
        raise BrokerReadinessDenied(
            'ORIGINAL_BROKER_PUBLIC_CENTRAL_WORDPRESS_READ_FAILED') from exc
    require(result.returncode==0,
            'ORIGINAL_BROKER_PUBLIC_CENTRAL_WORDPRESS_READ_FAILED')
    try:
        decoded=[json.loads(line) for line in result.stdout.splitlines()
                 if line.startswith('{')]
    except ValueError as exc:
        raise BrokerReadinessDenied('ORIGINAL_BROKER_PUBLIC_READBACK_NOT_JSON') from exc
    require(len(decoded)==1 and
            decoded[0].get('home')=='https://tours.dev.mad4b.com' and
            decoded[0].get('environment')=='staging',
            'ORIGINAL_BROKER_PUBLIC_CENTRAL_SCOPE_NOT_CURRENT')
    try:
        a=base64.b64decode(decoded[0]['broker'],validate=True)
        b=base64.b64decode(decoded[0]['original'],validate=True)
    except (ValueError,TypeError,KeyError) as exc:
        raise BrokerReadinessDenied('ORIGINAL_BROKER_PUBLIC_WORDPRESS_PINS_INVALID') from exc
    require(a==b==pinned,'ORIGINAL_BROKER_PUBLIC_CENTRAL_PINS_DIVERGED')
    return {'state':'ORIGINAL_BROKER_PUBLIC_HOST_IDENTITY_VERIFIED',
            'private_key_read':False,'signature_created':False,
            'wordpress_updated':False,'host_release_authorized':False}


def sign_ready_payload(broker, unsigned:dict)->dict:
    """Short-lived domain separated attestation with ORIGINAL Broker only."""
    require(unsigned.get('contract')=='mad4b.original305-unsigned-readiness-handoff.v1'
            and unsigned.get('signed') is False
            and unsigned.get('publication_authorized') is False,
            'HOST_READINESS_UNTRUSTED_HANDOFF')
    p=unsigned.get('payload')
    require(isinstance(p,dict) and p.get('contract')==CONTRACT
            and p.get('operation')=='host.source.release'
            and p.get('target_scope')=='host'
            and p.get('command_format')=='host.source.ref.v1'
            and p.get('host_id')=='srv2030908'
            and p.get('ready') is True
            and type(p.get('issued_epoch')) is int
            and type(p.get('expires_epoch')) is int
            and 0<p['expires_epoch']-p['issued_epoch']<=180,
            'HOST_READINESS_SIGNED_SCOPE_NOT_EXACT')
    exact_fields={
        'contract','operation','provider','authority_family','target_scope',
        'command_format','evidence_contract','host_id','source_registry_sha256',
        'owner_catalog_sha256','worker_sha256','ready','issued_epoch',
        'expires_epoch','nonce'}
    require(set(p)==exact_fields and
            p['provider']=='original_native_host_source' and
            p['authority_family']=='original_305' and
            p['evidence_contract']==CONTRACT and
            isinstance(p['nonce'],str) and len(p['nonce'])==32 and
            all(ch in '0123456789abcdef' for ch in p['nonce']) and
            all(isinstance(p[field],str) and len(p[field])==64 and
                all(ch in '0123456789abcdef' for ch in p[field])
                for field in ('source_registry_sha256',
                              'owner_catalog_sha256','worker_sha256')),
            'HOST_READINESS_CONTRACT_FIELDS_NOT_EXACT')
    require(hashlib.sha256(canonical(p)).hexdigest()==unsigned.get('payload_sha256'),
            'HOST_READINESS_HANDOFF_SHA_MISMATCH')
    # The original Broker must still have a valid original Central Host
    # policy, registered WP public key and currently approved original slot.
    policy_sha=broker.authority()
    key=broker.original_signer()
    broker.check_original_wordpress_signer(key)
    fingerprint=broker.host_fingerprint()
    require(broker.verify_original_policy_readback(policy_sha,fingerprint) is True,
            'ORIGINAL_BROKER_WP_LEDGER_OR_GRANT_NOT_CURRENT')
    policy=json.loads(PUBLISHER_POLICY.read_bytes())
    require(policy.get('enabled') is True and
            policy.get('central_approval_scope')=='tours-central:staging',
            'SOURCE_PUBLISHER_NOT_ENROLLED_FOR_CENTRAL')
    public=key.public_key().public_bytes(Encoding.Raw,PublicFormat.Raw)
    bound=base64.b64decode(policy['broker_public_key_b64'],validate=True)
    require(len(public)==32 and bound==public,
            'ORIGINAL_BROKER_PUBLISHER_SIGNING_PUBLIC_NOT_PINNED')
    # Refuse stale or modified Host infrastructure; a previously signed
    # value can never be replayed as a current readiness state by this job.
    fresh=handoff.unsigned_input()
    fields=('source_registry_sha256','owner_catalog_sha256','worker_sha256')
    require(all(fresh['payload'][field]==p[field] for field in fields),
            'HOST_READINESS_ORIGINAL_SOURCE_CHANGED_DURING_SIGNING')
    require(int(time.time())<p['expires_epoch']-25,
            'HOST_READINESS_EXPIRED_BEFORE_BROKER_SIGNATURE')
    domain=(CONTRACT+'\n').encode('utf-8')
    signed=dict(p)
    signed['signature']=base64.b64encode(key.sign(domain+canonical(p))).decode('ascii')
    return signed

def write_wp_readiness(broker, signed:dict):
    """Native WP plugin verifies the Broker signature before storing it."""
    encoded=base64.b64encode(canonical(signed)).decode('ascii')
    php='''$v=json_decode(base64_decode('''+repr(encoded)+'''),true);
if(!is_array($v) || !class_exists('MAD4B_Original_Native_Operation_Providers_V1',false))
  {echo wp_json_encode(['state'=>'DENY_MISSING_PROVIDER']);exit(14);}
if(!class_exists('MAD4B_Central_VPS_Broker_V1',false)
   || get_option('home')!=='https://tours.dev.mad4b.com'
   || wp_get_environment_type()!=='staging')
  {echo wp_json_encode(['state'=>'DENY_ORIGINAL_CENTRAL_SCOPE_DRIFT']);exit(19);}
$k=base64_decode((string)get_option(MAD4B_Central_VPS_Broker_V1::PUBKEY,''),true);
if(!is_string($k)||strlen($k)!==SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES)
  {echo wp_json_encode(['state'=>'DENY_ORIGINAL_BROKER_PUBLIC_KEY']);exit(15);}
$signature=base64_decode((string)($v['signature']??''),true);
$payload=$v;unset($payload['signature']);ksort($payload,SORT_STRING);
if(!is_string($signature)||strlen($signature)!==SODIUM_CRYPTO_SIGN_BYTES
   || ($payload['contract']??'')!=='mad4b.original-host-source-readiness.v1'
   || ($payload['operation']??'')!=='host.source.release'
   || ($payload['target_scope']??'')!=='host'
   || ($payload['host_id']??'')!=='srv2030908'
   || ($payload['ready']??null)!==true
   || !is_int($payload['issued_epoch']??null)
   || !is_int($payload['expires_epoch']??null)
   || $payload['issued_epoch']>time()+15
   || $payload['expires_epoch']<=time()+20
   || $payload['expires_epoch']>$payload['issued_epoch']+180
   || !sodium_crypto_sign_verify_detached($signature,
      'mad4b.original-host-source-readiness.v1'."\\n".
      wp_json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$k))
  {echo wp_json_encode(['state'=>'DENY_ORIGINAL_SIGNED_READINESS']);exit(16);}
$old=get_option('mad4b_original_host_source_readiness_v1',false);
if(is_array($old) && (int)($old['issued_epoch']??0)>=$payload['issued_epoch'])
 {echo wp_json_encode(['state'=>'DENY_STALE_BROKER_ATTESTATION']);exit(17);}
$ok=update_option('mad4b_original_host_source_readiness_v1',$v,false);
$actual=get_option('mad4b_original_host_source_readiness_v1',false);
$ready=MAD4B_Original_Native_Operation_Providers_V1::legacy_admitted(
 'host.source.release','host','host.source.ref.v1');
if(!is_array($actual)||($actual['nonce']??'')!==$payload['nonce'] || !$ready)
 {echo wp_json_encode(['state'=>'DENY_POST_WRITE_READBACK']);exit(18);}
echo wp_json_encode(['state'=>'ORIGINAL305_SIGNED_HOST_PROVIDER_READY',
 'readback_verified'=>true,'host_release_authorized'=>false,
 'expires_epoch'=>$payload['expires_epoch']]);'''
    proc=subprocess.run(broker.original_central_wp()+['eval',php],
        capture_output=True,text=True,timeout=42)
    if proc.returncode:
        raise BrokerReadinessDenied('ORIGINAL_WORDPRESS_SIGNED_READINESS_WRITE_DENIED')
    try:
        rows=[json.loads(line) for line in proc.stdout.splitlines() if line.startswith('{')]
    except ValueError as exc:
        raise BrokerReadinessDenied('ORIGINAL_WORDPRESS_READBACK_INVALID_JSON') from exc
    require(len(rows)==1 and rows[0].get('state')=='ORIGINAL305_SIGNED_HOST_PROVIDER_READY'
            and rows[0].get('readback_verified') is True
            and rows[0].get('host_release_authorized') is False,
            'ORIGINAL_WORDPRESS_HOST_READINESS_NOT_VERIFIED')
    return rows[0]

def run(*,refresh=False):
    require(os.geteuid()==0,'ORIGINAL_HOST_READINESS_ROOT_REQUIRED')
    if not refresh:
        # A read-only health check never competes for the exclusive original
        # Broker renewer lock. The signer and WordPress Option stay untouched.
        handoff.unsigned_input()
        original=original_broker()
        public=firstparty_public_readback(original)
        return {'state':'ORIGINAL_BROKER_HOST_PUBLIC_PREFLIGHT_VERIFIED_UNSIGNED',
                'signed':False,'wp_readiness_changed':False,
                'public_identity_verified':public['state']==
                    'ORIGINAL_BROKER_PUBLIC_HOST_IDENTITY_VERIFIED',
                'host_release_authorized':False}
    require(LOCK.parent.is_dir() and not LOCK.parent.is_symlink() and
            LOCK.parent.stat().st_uid==0 and LOCK.parent.stat().st_gid==0 and
            stat.S_IMODE(LOCK.parent.stat().st_mode)==0o700,
            'ORIGINAL_BROKER_LOCK_DIR_NOT_ROOT_PRIVATE')
    fd=os.open(LOCK,os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW,0o600)
    try:
        metadata=os.fstat(fd)
        require(stat.S_ISREG(metadata.st_mode) and metadata.st_uid==0 and
                metadata.st_gid==0 and metadata.st_nlink==1 and
                stat.S_IMODE(metadata.st_mode)==0o600,
                'ORIGINAL_BROKER_REFRESH_LOCK_NOT_ROOT_PRIVATE')
        fcntl.flock(fd,fcntl.LOCK_EX|fcntl.LOCK_NB)
    except (BlockingIOError,BrokerReadinessDenied) as exc:
        os.close(fd)
        if isinstance(exc,BrokerReadinessDenied):raise
        raise BrokerReadinessDenied('ORIGINAL_BROKER_HOST_REFRESH_CONCURRENT_DEFER') from exc
    try:
        unsigned=handoff.unsigned_input()
        original=original_broker()
        signed=sign_ready_payload(original,unsigned)
        result=write_wp_readiness(original,signed)
        return {'state':result['state'],
                'original_broker_readiness_signature_verified':True,
                'expires_epoch':result['expires_epoch'],
                'master_approval_issued':False,'host_release_authorized':False,
                'host_files_modified':False}
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)

if __name__=='__main__':
    p=argparse.ArgumentParser()
    mode=p.add_mutually_exclusive_group(required=True)
    mode.add_argument('--check',action='store_true')
    mode.add_argument('--refresh',action='store_true')
    args=p.parse_args()
    print(json.dumps(run(refresh=args.refresh),sort_keys=True))
