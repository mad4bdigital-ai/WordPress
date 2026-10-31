#!/usr/bin/env python3
"""MAD4B fixed, no-target-write VPS Broker diagnostic execution.

Requires a fresh browser-issued and signed Broker claim, exact host/site/command
digest, separate host-side execution and readback. Never runs queued shell text.
Never retries a claimed operation after an uncertain execution.
"""
import base64
import datetime
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization

BASE = Path('/opt/mad4b-company-transport/broker-v1')
PRIVATE = BASE/'host-ed25519-private.pem'
AUDIT = BASE/'broker-events.jsonl'
CLAIMS = BASE/'diagnostic-receipts'
HOST = 'srv2030908'
SITE = 'b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b'
CONTRACT = 'mad4b.vps-broker.diagnostic-execute.v1'
COMMAND = 'printf MAD4B_BROKER_DIAGNOSTIC'
OUTPUT = b'MAD4B_BROKER_DIAGNOSTIC'
COMMAND_SHA = hashlib.sha256(COMMAND.encode()).hexdigest()
OUTPUT_SHA = hashlib.sha256(OUTPUT).hexdigest()
WP = ['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',
      '/usr/local/bin/wp','--path=/var/www/html','--skip-themes','--user=1',
      'mad4b','broker-diagnostic']

def wp(args):
    p = subprocess.run(WP + args, capture_output=True, text=True,
                       timeout=40, check=False)
    if p.returncode != 0:
        raise RuntimeError('WP_CLI_DENIED_OR_UNAVAILABLE')
    try:
        return json.loads(p.stdout.splitlines()[-1])
    except (ValueError, IndexError) as exc:
        raise RuntimeError('INVALID_WP_READBACK') from exc

def audit(event, job, state):
    row = {'contract':CONTRACT,
           'at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
           'event':event, 'job_id':job, 'company':'wayfatours',
           'state':state, 'production_mutation':False}
    with AUDIT.open('a',encoding='utf-8') as f:
        f.write(json.dumps(row,sort_keys=True)+'\n')
        f.flush()
        os.fsync(f.fileno())

def run_once(job):
    jobid = job['job_id']
    if not re.fullmatch(r'[a-f0-9]{32}',str(jobid)):
        return {'job_id':str(jobid)[:32],'state':'INVALID_ID'}
    if not (job.get('site_uuid') == SITE and job.get('environment') == 'production'
        and job.get('host_id') == HOST and job.get('command_sha256') == COMMAND_SHA
        and isinstance(job.get('expires_epoch'),int)
        and job['expires_epoch'] > int(time.time()) + 10):
        return {'job_id':jobid, 'state':'SCOPE_OR_GRANT_NOT_ELIGIBLE'}
    CLAIMS.mkdir(mode=0o700,parents=True,exist_ok=True)
    local = CLAIMS/(jobid+'.json')
    # Consume locally BEFORE host command; uncertain outcomes must not replay.
    try:
        fd = os.open(str(local),os.O_WRONLY|os.O_CREAT|os.O_EXCL,0o600)
    except FileExistsError:
        return {'job_id':jobid,'state':'ALREADY_ATTEMPTED_NO_REPLAY'}
    with os.fdopen(fd,'w') as f:
        f.write(json.dumps({'job_id':jobid,'state':'ATTEMPTED','at':int(time.time())}))
        f.flush();os.fsync(f.fileno())
    audit('DIAGNOSTIC_EXECUTION_ATTEMPTED',jobid,'ATTEMPTED')
    try:
        # Fixed, audited command. No untrusted queue byte is executed.
        execution=subprocess.run(['/bin/bash','--noprofile','--norc','-c',COMMAND],
                                 capture_output=True,timeout=6,check=False)
        readback=subprocess.run(['/usr/bin/printf','MAD4B_BROKER_DIAGNOSTIC'],
                                capture_output=True,timeout=6,check=False)
        if (execution.returncode != 0 or readback.returncode != 0
            or execution.stdout != OUTPUT or readback.stdout != OUTPUT
            or execution.stderr or readback.stderr):
            audit('DIAGNOSTIC_EXECUTION_UNVERIFIED',jobid,'FAILED')
            return {'job_id':jobid,'state':'EXECUTION_OR_READBACK_FAILED'}
        issued=int(time.time());expires=issued+40;nonce=secrets.token_hex(16)
        fields=[CONTRACT,'receipt',jobid,SITE,'production',HOST,nonce,issued,
                expires,OUTPUT_SHA,OUTPUT_SHA]
        key=serialization.load_pem_private_key(PRIVATE.read_bytes(),password=None)
        assert isinstance(key,Ed25519PrivateKey)
        signature=base64.b64encode(key.sign('\n'.join(map(str,fields)).encode())).decode()
        response=wp(['receipt','--job='+jobid,'--nonce='+nonce,
            '--issued='+str(issued),'--expires='+str(expires),
            '--output_sha256='+OUTPUT_SHA,'--readback_sha256='+OUTPUT_SHA,
            '--signature='+signature])
        if (response.get('job_id')!=jobid
            or response.get('state')!='DIAGNOSTIC_EXECUTION_READBACK_VERIFIED'
            or response.get('independent_readback_verified') is not True):
            raise RuntimeError('CENTRAL_RECEIPT_READBACK_MISMATCH')
        verified={'job_id':jobid,'state':'VERIFIED',
                  'output_sha256':OUTPUT_SHA,'readback_sha256':OUTPUT_SHA,
                  'receipt_contract':CONTRACT,'at':int(time.time())}
        temp=local.with_suffix('.tmp')
        with temp.open('w') as f:
            f.write(json.dumps(verified,sort_keys=True))
            f.flush();os.fsync(f.fileno())
        os.replace(temp,local)
        audit('DIAGNOSTIC_EXECUTION_READBACK_VERIFIED',jobid,'VERIFIED')
        return {'job_id':jobid,'state':'VERIFIED'}
    except (OSError,ValueError,TimeoutError,subprocess.TimeoutExpired,RuntimeError):
        audit('DIAGNOSTIC_EXECUTION_RECEIPT_UNCERTAIN',jobid,'RECONCILE')
        return {'job_id':jobid,'state':'UNKNOWN_REQUIRES_RECONCILIATION'}

def main():
    if os.geteuid()!=0 or not PRIVATE.is_file():
        raise SystemExit('AUTHORIZED_HOST_BROKER_REQUIRED')
    listing=wp(['claimed','--limit=10'])
    if listing.get('contract')!=CONTRACT or not isinstance(listing.get('eligible'),list):
        raise SystemExit('UNTRUSTED_CLAIMED_JOBS')
    results=[run_once(item) for item in listing['eligible']]
    print(json.dumps({'contract':CONTRACT,'checked':len(listing['eligible']),
                      'results':results,'company_production_mutation':False},sort_keys=True))
if __name__=='__main__':main()
