#!/usr/bin/env python3
"""Call the original WordPress authority registry through authenticated VPS broker.
Signed-only WordPress preflight; no user impersonation, grant issuance,\ncommand retrieval, or SSH execution.
"""
import argparse
import base64
import hashlib
import json
from pathlib import Path
import re
import secrets
import subprocess
import time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey

ROOT=Path('/opt/mad4b-company-transport/broker-v1')
KEY=ROOT/'host-ed25519-private.pem'
CONTRACT='mad4b.broker-exact-ssh-authority.v1'
HOST='srv2030908'
WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',
    '/usr/local/bin/wp','--path=/var/www/html','--skip-themes','mad4b','broker-authority']
HEX32=re.compile(r'^[a-f0-9]{32}$')
HEX64=re.compile(r'^[a-f0-9]{64}$')
TARGETS={'hosting-1','hosting-2','hosting-3','srv2030908'}
def verify(job,target,sha):
    if not HEX32.fullmatch(job) or not HEX64.fullmatch(sha) or target not in TARGETS:
        raise ValueError('INVALID_EXACT_AUTHORITY_SCOPE')
    nonce=secrets.token_hex(16)
    issued=int(time.time()); expires=issued+40
    fields=[CONTRACT,'verify',HOST,job,target,sha,nonce,str(issued),str(expires)]
    key=serialization.load_pem_private_key(KEY.read_bytes(),password=None)
    if not isinstance(key,Ed25519PrivateKey):raise RuntimeError('BROKER_SIGNER_INVALID')
    sig=base64.b64encode(key.sign('\n'.join(fields).encode())).decode()
    args=['verify','--job='+job,'--target='+target,'--command_sha256='+sha,
          '--nonce='+nonce,'--issued='+str(issued),'--expires='+str(expires),
          '--signature='+sig]
    p=subprocess.run(WP+args,capture_output=True,text=True,timeout=40,check=False)
    if p.returncode:raise RuntimeError('CENTRAL_SIGNED_AUTHORITY_PREFLIGHT_REJECTED')
    row=json.loads(p.stdout.strip().splitlines()[-1])
    if row.get('contract')!=CONTRACT or not isinstance(row.get('eligible'),bool):
        raise RuntimeError('CENTRAL_AUTHORITY_CONTRACT_INVALID')
    if row.get('eligible') is True:
        if not(row.get('verified') is True and row.get('job_id')==job
           and row.get('target_id')==target and row.get('command_sha256')==sha
           and row.get('central_scope_only') is True
           and row.get('shell_executed') is False
           and isinstance(row.get('expires_epoch'),int)
           and row['expires_epoch']>time()):
            raise RuntimeError('CENTRAL_SCOPE_RECEIPT_MISMATCH')
    return row
def selftest():
    # Every invalid test must fail before reaching WordPress.
    for job,target,sha in [('!','hosting-1','0'*64),('0'*32,'unregistered-host','0'*64),('0'*32,'hosting-1','g'*64)]:
        try:verify(job,target,sha)
        except ValueError:pass
        else:raise AssertionError('INVALID_SCOPE_ACCEPTED')
    response=verify('0'*32,'hosting-1',hashlib.sha256(b'printf MAD4B_BROKER_DIAGNOSTIC').hexdigest())
    assert response['eligible'] is False and response['verified'] is False
    assert response['shell_executed'] is False
    local=verify('0'*32,'srv2030908',hashlib.sha256(b'MAD4B_UNKNOWN_LOCAL_JOB').hexdigest())
    assert local['eligible'] is False and local['verified'] is False
    assert local['shell_executed'] is False
    print(json.dumps({'contract':CONTRACT,'selftest':'PASS','assertions':7,
         'signed_central_preflight_round_trip':True,
         'unknown_job_denied':True,'production_mutation':False}))
def main():
    if len(__import__('sys').argv)<2:raise SystemExit('status|selftest|verify')
    p=argparse.ArgumentParser()
    p.add_argument('action',choices=['status','selftest','verify'])
    p.add_argument('--job',default='')
    p.add_argument('--target',default='')
    p.add_argument('--command-sha256',default='')
    a=p.parse_args()
    if a.action=='selftest':selftest()
    elif a.action=='status':
        x=subprocess.run(WP+['status'],capture_output=True,text=True,timeout=35,check=True)
        print(x.stdout.strip().splitlines()[-1])
    else: print(json.dumps(verify(a.job,a.target,a.command_sha256),sort_keys=True))
if __name__=='__main__':main()
