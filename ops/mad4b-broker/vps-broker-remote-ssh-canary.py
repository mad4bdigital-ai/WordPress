#!/usr/bin/env python3
"""Exact-grant remote Registry SSH diagnostic via native Broker 312 and 313.
Only fixed nonmutating printf; never executes source-job command text.
Requires independently verified signed central scope and fresh, single-use local claim.
"""
import base64
import datetime as dt
import fcntl
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import sys
import time
from cryptography.hazmat.primitives import serialization
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
import registry_ssh_adapter as registry

BASE=Path('/opt/mad4b-company-transport/broker-v1')
RECEIPTS=BASE/'remote-ssh-diagnostic-receipts'
PRIVATE=BASE/'host-ed25519-private.pem'
CONTRACT='mad4b.broker-remote-ssh-diagnostic.v1'
HOST='srv2030908'
COMMAND='printf MAD4B_REGISTRY_SSH_READ_CANARY'
OUTPUT=b'MAD4B_REGISTRY_SSH_READ_CANARY'
COMMAND_SHA=hashlib.sha256(COMMAND.encode()).hexdigest()
OUTPUT_SHA=hashlib.sha256(OUTPUT).hexdigest()
WP=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php','/usr/local/bin/wp',
    '--path=/var/www/html','--skip-themes','--user=1']
HEX32=re.compile(r'^[a-f0-9]{32}$')

def wp(command):
    p=subprocess.run(WP+command,capture_output=True,text=True,timeout=42,check=False)
    if p.returncode or p.stderr.strip():raise RuntimeError('CENTRAL_WPCLI_DENIED_OR_UNAVAILABLE')
    try:return json.loads(p.stdout.strip().splitlines()[-1])
    except (ValueError,IndexError) as exc:raise RuntimeError('CENTRAL_READBACK_INVALID') from exc

def verify_site():
    checks=[(['option','get','home','--skip-plugins','--skip-themes'],'https://tours.dev.mad4b.com'),
        (['config','get','DB_NAME','--type=constant','--skip-plugins','--skip-themes'],'etg_commerce_staging')]
    for cmd,expected in checks:
        p=subprocess.run(WP[:6]+cmd,capture_output=True,text=True,timeout=25,check=False)
        if p.returncode or p.stdout.strip()!=expected:raise RuntimeError('CENTRAL_SITE_BINDING_FAILED')

def authority():
    module=BASE/'vps-broker-exact-authority.py'
    spec=importlib.util.spec_from_file_location('original_broker312',module)
    if spec is None or spec.loader is None:raise RuntimeError('CENTRAL_AUTHORITY_MODULE_MISSING')
    obj=importlib.util.module_from_spec(spec);spec.loader.exec_module(obj)
    return obj

def eligible_shape(claim, proof):
    target=claim.get('target_id');job=claim.get('job_id')
    if target not in registry.HOSTS or not isinstance(job,str) or not HEX32.fullmatch(job):return False
    if claim.get('command_sha256')!=COMMAND_SHA:return False
    if not isinstance(proof,dict) or proof.get('contract')!='mad4b.broker-exact-ssh-authority.v1':return False
    if (proof.get('eligible') is not True or proof.get('verified') is not True
            or proof.get('central_scope_only') is not True or proof.get('shell_executed') is not False):return False
    if proof.get('job_id')!=job or proof.get('target_id')!=target or proof.get('command_sha256')!=COMMAND_SHA:return False
    if proof.get('credential_ref')!='vault:registry/'+target:return False
    if proof.get('host_principal')!=registry.HOSTS[target]['username']:return False
    if not isinstance(proof.get('grant_id'),str) or not proof['grant_id']:return False
    if not isinstance(proof.get('expires_epoch'),int) or proof['expires_epoch']<=time.time()+65:return False
    if not isinstance(proof.get('company'),str) or not proof['company']:return False
    return True

def ssh_fixed(target):
    cfg=registry.HOSTS[target]
    base=registry.ROOT/target
    key,hostkey=base/'id_ed25519',base/'known_hosts_verified'
    registry.verify_local_file(key)
    registry.verify_local_file(hostkey,cfg['known_hosts_sha256'])
    # Only the literal, source-defined diagnostic can cross the SSH boundary.
    args=['/usr/bin/ssh','-F','/dev/null','-T','-o','BatchMode=yes','-o','IdentitiesOnly=yes',
      '-o','StrictHostKeyChecking=yes','-o','UserKnownHostsFile='+str(hostkey),
      '-o','GlobalKnownHostsFile=/dev/null','-o','ClearAllForwardings=yes',
      '-o','PermitLocalCommand=no','-o','ProxyCommand=none','-o','ConnectTimeout=8',
      '-o','LogLevel=ERROR','-i',str(key),'-p',str(cfg['port']),
      cfg['username']+'@'+cfg['hostname'],COMMAND]
    p=subprocess.run(args,capture_output=True,timeout=18,check=False)
    return p.returncode==0 and p.stdout==OUTPUT and p.stderr==b''

def record(path,row):
    tmp=path.with_suffix('.new')
    with tmp.open('x') as out:
        out.write(json.dumps(row,sort_keys=True)+'\n');out.flush();os.fsync(out.fileno())
    os.replace(tmp,path)

def audit_event(job,target,event):
    row={'contract':CONTRACT,'at':dt.datetime.now(dt.timezone.utc).isoformat(),
         'job_id':job,'target_id':target,'event':event,
         'production_mutation':False,'arbitrary_shell_allowed':False}
    with (BASE/'remote-ssh-diagnostic-audit.jsonl').open('a',encoding='utf-8') as f:
        f.write(json.dumps(row,sort_keys=True)+'\n');f.flush();os.fsync(f.fileno())

def run_one(claim,verifier):
    job=str(claim.get('job_id',''))
    target=claim.get('target_id')
    if not isinstance(target,str) or target not in registry.HOSTS or not HEX32.fullmatch(job):
        return {'state':'INVALID_CLAIM'}
    if claim.get('command_sha256')!=COMMAND_SHA:
        return {'state':'FIXED_DIAGNOSTIC_ONLY'}
    # Original 312 signs/verifies exact job+grant+transport+company+target;
    # this only consumes its response, never creates a grant or OAuth identity.
    try:proof=verifier.verify(job,target,COMMAND_SHA)
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired):
        return {'state':'CENTRAL_SIGNED_GRANT_NOT_VERIFIED'}
    if not eligible_shape(claim,proof):return {'state':'EXACT_GRANT_OR_TARGET_NOT_ELIGIBLE'}
    RECEIPTS.mkdir(mode=0o700,parents=True,exist_ok=True)
    path=RECEIPTS/(job+'.json')
    try:fd=os.open(path,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
    except FileExistsError:return {'state':'ALREADY_ATTEMPTED_NO_REPLAY'}
    with os.fdopen(fd,'w') as out:
        out.write(json.dumps({'state':'ATTEMPTED','job_id':job,'target_id':target,'at':int(time.time())}));out.flush();os.fsync(out.fileno())
    audit_event(job,target,'ATTEMPTED')
    # Execution is irreversible from an audit standpoint: never retry if uncertain.
    try:
        if not ssh_fixed(target):
            audit_event(job,target,'REMOTE_DIAGNOSTIC_FAILED_NO_RETRY')
            return {'state':'REMOTE_DIAGNOSTIC_FAILED_NO_RETRY'}
        if not ssh_fixed(target):
            audit_event(job,target,'INDEPENDENT_REMOTE_READBACK_FAILED_NO_RETRY')
            return {'state':'INDEPENDENT_REMOTE_READBACK_FAILED_NO_RETRY'}
        issued=int(time.time());expires=issued+40;nonce=secrets.token_hex(16)
        fields=[CONTRACT,'receipt',HOST,job,target,nonce,str(issued),str(expires),
                COMMAND_SHA,OUTPUT_SHA,OUTPUT_SHA]
        key=serialization.load_pem_private_key(PRIVATE.read_bytes(),password=None)
        if not isinstance(key,Ed25519PrivateKey):raise RuntimeError('HOST_SIGNER_INVALID')
        sig=base64.b64encode(key.sign('\n'.join(fields).encode())).decode()
        response=wp(['mad4b','broker-remote-ssh','receipt','--job='+job,'--target='+target,
            '--command_sha256='+COMMAND_SHA,'--output_sha256='+OUTPUT_SHA,
            '--readback_sha256='+OUTPUT_SHA,'--nonce='+nonce,
            '--issued='+str(issued),'--expires='+str(expires),'--signature='+sig])
        if (response.get('verified') is not True or response.get('job_id')!=job
                or response.get('target_id')!=target
                or response.get('independent_readback_verified') is not True):
            audit_event(job,target,'CENTRAL_RECEIPT_NOT_VERIFIED_NO_RETRY')
            return {'state':'CENTRAL_RECEIPT_NOT_VERIFIED_NO_RETRY'}
        record(path,{'state':'VERIFIED','job_id':job,'target_id':target,
                     'receipt_contract':CONTRACT,'at':int(time.time()),
                     'command_sha256':COMMAND_SHA,'output_sha256':OUTPUT_SHA,
                     'readback_sha256':OUTPUT_SHA})
        audit_event(job,target,'VERIFIED')
        return {'state':'VERIFIED','target_id':target}
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired):
        audit_event(job,target,'UNRESOLVED_EXECUTION_NO_RETRY')
        return {'state':'UNRESOLVED_EXECUTION_NO_RETRY'}

def selftest():
    proof={'contract':'mad4b.broker-exact-ssh-authority.v1','verified':True,'eligible':True,
         'central_scope_only':True,'shell_executed':False,'job_id':'a'*32,
         'target_id':'hosting-3','command_sha256':COMMAND_SHA,
         'credential_ref':'vault:registry/hosting-3',
         'host_principal':registry.HOSTS['hosting-3']['username'],
         'grant_id':'b'*32,'company':'egypt-tour-gates','expires_epoch':int(time.time())+180}
    claim={'job_id':'a'*32,'target_id':'hosting-3','command_sha256':COMMAND_SHA}
    assert eligible_shape(claim,proof)
    for bad in ({**proof,'eligible':False},{**proof,'target_id':'hosting-1'},
                {**proof,'command_sha256':'0'*64},{**proof,'expires_epoch':int(time.time())+2},
                {**proof,'credential_ref':'untrusted'},{**proof,'shell_executed':True},
                {**proof,'host_principal':'root'},{**proof,'central_scope_only':False}):
        assert not eligible_shape(claim,bad)
    assert not eligible_shape({**claim,'command_sha256':'0'*64},proof)
    print(json.dumps({'contract':CONTRACT,'selftest':'PASS','checks':10,
                     'ssh_or_wp_command_executed':False}))

def main():
    if len(sys.argv)!=2 or sys.argv[1] not in ('selftest','run-eligible'):
        raise SystemExit('usage: vps-broker-remote-ssh-canary.py selftest|run-eligible')
    if sys.argv[1]=='selftest':selftest();return
    if os.geteuid()!=0:raise SystemExit('REGISTERED_ROOT_HOST_REQUIRED')
    verify_site()
    with (BASE/'remote-ssh-canary.lock').open('a+') as lock:
        fcntl.flock(lock.fileno(),fcntl.LOCK_EX|fcntl.LOCK_NB)
        rows=wp(['mad4b','broker-authority','claimed','--limit=1'])
        if (rows.get('contract')!='mad4b.broker-exact-ssh-authority.v1'
                or not isinstance(rows.get('claimed'),list) or len(rows['claimed'])>1):
            raise RuntimeError('CENTRAL_CLAIM_INVENTORY_INVALID')
        verifier=authority()
        results=[run_one(row,verifier) for row in rows['claimed']]
        print(json.dumps({'contract':CONTRACT,'scanned':len(rows['claimed']),
                         'results':results,'production_mutation':False,'arbitrary_shell_allowed':False}))
if __name__=='__main__':main()
