#!/usr/bin/env python3
"""Read-only broker -> Managed SSH readiness reconciliation.
No SSH, no grant issuance, no commands from queued jobs.
"""
import datetime as dt
import hashlib
import json
import os
import subprocess
from pathlib import Path
import sys
import tempfile
import time
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives import serialization
from cryptography.exceptions import InvalidSignature
from registry_ssh_adapter import observe as registry_ssh_observe

BROKER=Path('/opt/mad4b-company-transport/broker-v1')
NATIVE=Path('/opt/mad4b-company-provisioning/governed-execution/native-ssh-v1')
HOST='srv2030908'
REPORT=BROKER/'native-ssh-broker-reconcile.json'

def read_json(path):
    return json.loads(path.read_text(encoding='utf-8'))

def evaluate(broker_config,registration,module_digests,heartbeat_age,signer_ok,identity_enrolled=False,grant_verifier_enrolled=False):
    blockers=[]
    if broker_config.get('contract')!='mad4b.vps-broker-poller.v1' or broker_config.get('host_id')!=HOST:
        blockers.append('BROKER_HOST_BINDING_MISMATCH')
    if registration.get('contract')!='mad4b.native-managed-ssh-installation.v1':
        blockers.append('NATIVE_INSTALLATION_NOT_VERIFIED')
    expected=registration.get('runtime_modules_sha256')
    if not isinstance(expected,dict) or not expected or expected!=module_digests:
        blockers.append('NATIVE_SOURCE_DIGEST_MISMATCH')
    if not signer_ok: blockers.append('BROKER_SIGNATURE_UNAVAILABLE')
    if heartbeat_age is None or heartbeat_age>600: blockers.append('NO_FRESH_SIGNED_BROKER_HEARTBEAT')
    if not identity_enrolled: blockers.append('INDEPENDENT_SSH_IDENTITY_NOT_ENROLLED')
    if not grant_verifier_enrolled: blockers.append('CENTRAL_GRANT_VERIFIER_NOT_BOUND')
    return {'contract':'mad4b.broker-native-ssh-reconcile.v1',
            'host_id':HOST,'broker_connected':signer_ok and heartbeat_age is not None and heartbeat_age<=600,
            'native_files_integrity_verified':'NATIVE_SOURCE_DIGEST_MISMATCH' not in blockers,
            'host_ssh_execution_authorized':not blockers,'shell_execution_performed':False,
            'production_mutation':False,'blockers':blockers,
            'state':'READY_FOR_AUTHENTICATED_EXECUTION' if not blockers else 'WAITING_FOR_VERIFIED_HOST_AUTHORITY'}

def exact_scope_matches(claim, proof, authenticated_targets):
    """A signed WordPress grant preflight is not host execution authority."""
    return (
        isinstance(claim, dict) and isinstance(proof, dict)
        and proof.get('contract') == 'mad4b.broker-exact-ssh-authority.v1'
        and proof.get('verified') is True and proof.get('eligible') is True
        and proof.get('central_scope_only') is True
        and proof.get('shell_executed') is False
        and proof.get('job_id') == claim.get('job_id')
        and proof.get('target_id') == claim.get('target_id')
        and proof.get('command_sha256') == claim.get('command_sha256')
        and proof.get('target_id') in authenticated_targets
        and isinstance(proof.get('expires_epoch'), int)
        and proof['expires_epoch'] > time.time()
    )


def current_signed_target_scopes(registry):
    """Resolve actual claimed jobs via 312, never by trusting queued commands."""
    if registry.get('central_grant_verifier_bound') is not True:
        return [], None, None
    command = ['/usr/bin/docker', 'exec', '-u', 'www-data',
               'etg-commerce-staging-php', '/usr/local/bin/wp',
               '--path=/var/www/html', '--skip-themes', '--user=1',
               'mad4b', 'broker-authority', 'claimed', '--limit=10']
    try:
        response = subprocess.run(command, capture_output=True, text=True,
                                  check=False, timeout=40)
        if response.returncode or response.stderr.strip():
            return [], None, None
        listed = json.loads(response.stdout.strip().splitlines()[-1])
        if listed.get('contract') != 'mad4b.broker-exact-ssh-authority.v1':
            return [], None, None
        claims = listed.get('claimed')
        if not isinstance(claims, list) or len(claims) > 10:
            return [], None, None
    except (OSError, ValueError, IndexError, subprocess.TimeoutExpired):
        return [], None
    authenticated = set(registry.get('fixed_diagnostic_shell_authorized_targets') or [])
    import re
    scopes = []
    for claim in claims:
        if not isinstance(claim, dict):
            continue
        job, target, digest = (claim.get('job_id'), claim.get('target_id'),
                               claim.get('command_sha256'))
        if not (isinstance(job, str) and re.fullmatch(r'[a-f0-9]{32}', job)
                and isinstance(target, str) and target in authenticated
                and isinstance(digest, str) and re.fullmatch(r'[a-f0-9]{64}', digest)):
            continue
        verified = [sys.executable, str(BROKER/'vps-broker-exact-authority.py'),
                    'verify', '--job='+job, '--target='+target,
                    '--command-sha256='+digest]
        try:
            response = subprocess.run(verified, capture_output=True, text=True,
                                      check=False, timeout=45)
            if response.returncode or response.stderr.strip():
                continue
            proof = json.loads(response.stdout.strip().splitlines()[-1])
        except (OSError, ValueError, IndexError, subprocess.TimeoutExpired):
            continue
        if exact_scope_matches(claim, proof, authenticated):
            scopes.append({'job_id':job, 'target_id':target,
                           'command_sha256':digest,
                           'grant_id':proof.get('grant_id'),
                           'expires_epoch':proof['expires_epoch'],
                           'central_scope_only':True,
                           'shell_executed':False})
    return scopes, len(claims)


def bounded_diagnostic_unit_installed():
    """Verify the reviewed fixed diagnostic unit and source; not a grant."""
    from pathlib import Path
    runner=BROKER/'remote-exact-canary.py'
    unit=Path('/etc/systemd/system/mad4b-dispatch-v1.service.d/67-broker-exact-remote-canary.conf')
    acceptance=Path('/opt/staging/mad4b-broker-native-execution-closure-20261031/remote-exact-canary-acceptance-20261031.json')
    try:
        receipt=json.loads(acceptance.read_text())
        if receipt.get('contract')!='mad4b.remote-exact-canary-deployment-evidence.v1':
            return False
        expected=receipt.get('installed_sha256',{})
        for p in (runner,unit):
            if not p.is_file() or p.is_symlink():
                return False
            if hashlib.sha256(p.read_bytes()).hexdigest()!=expected.get(str(p)):
                return False
        return 'ExecStart=-/usr/bin/python3 /opt/mad4b-company-transport/broker-v1/remote-exact-canary.py run' in unit.read_text()
    except (OSError,ValueError,TypeError):
        return False


def claim_scope_blockers(scopes, claimed_count):
    # No job is an idle condition, not a failed target scope binding.
    # An unavailable 312 inventory is unknown, never silently treated as empty.
    if scopes:
        return []
    if claimed_count is None:
        return ['CENTRAL_CLAIM_INVENTORY_UNAVAILABLE']
    if claimed_count == 0:
        return ['NO_CURRENT_EXACT_ELIGIBLE_JOB']
    return ['CENTRAL_REMOTE_TARGET_SCOPE_BINDING_PENDING',
            'NO_CURRENT_EXACT_ELIGIBLE_JOB']

def measured():
    config=read_json(BROKER/'broker-config.json')
    registration=read_json(NATIVE/'registration.json')
    expected=registration.get('runtime_modules_sha256',{})
    current={}
    for file in expected:
        path=NATIVE/'runtime'/file
        if path.is_file() and not path.is_symlink():
            current[file]=hashlib.sha256(path.read_bytes()).hexdigest()
    signer_ok=False
    try:
        private=serialization.load_pem_private_key((BROKER/'host-ed25519-private.pem').read_bytes(),password=None)
        if isinstance(private,Ed25519PrivateKey):
            public=private.public_key()
            public_raw=public.public_bytes(serialization.Encoding.Raw,serialization.PublicFormat.Raw)
            import base64
            pinned=base64.b64decode((BROKER/'host-ed25519-public.base64').read_text().strip(),validate=True)
            challenge=os.urandom(32)
            public.verify(private.sign(challenge),challenge)
            signer_ok=public_raw==pinned
    except (ValueError,TypeError,OSError,InvalidSignature):
        signer_ok=False
    age=None
    try:
        for line in (BROKER/'broker-events.jsonl').read_text().splitlines()[-250:][::-1]:
            try: event=json.loads(line)
            except ValueError: continue
            if event.get('event')=='HEARTBEAT_VERIFIED' and event.get('host_id')==HOST:
                timestamp=dt.datetime.fromisoformat(event['at'])
                age=max(0,(dt.datetime.now(dt.timezone.utc)-timestamp).total_seconds())
                break
    except (OSError,ValueError,KeyError):
        age=None
    # An existing central grant/transport reference is NOT a host entitlement.
    # Only an independently provisioned host adapter and trusted grant verifier
    # can move these flags to true.
    # One Broker cycle owns both Registry transport observation and the
    # central authorizer preflight. A valid SSH session is not a central grant.
    registry=registry_ssh_observe(use_network=True)
    target_transport_ready=bool(registry['authenticated_targets'])
    central_grant_ready=registry['central_grant_verifier_bound'] is True
    result=evaluate(config,registration,current,age,signer_ok,
                    identity_enrolled=target_transport_ready,
                    grant_verifier_enrolled=central_grant_ready)
    result['registry_ssh']=registry
    result['registry_transport_authenticated']=target_transport_ready
    result['fixed_diagnostic_shell_authorized_targets']=registry['fixed_diagnostic_shell_authorized_targets']
    result['fixed_diagnostic_shell_authorized']=bool(registry['fixed_diagnostic_shell_authorized_targets'])
    # A registered 312 verifier is not a grant. Each eligible target must have
    # an existing claim whose exact grant, company, digest and target 312 confirms.
    scopes, claimed_count=current_signed_target_scopes(registry)
    result['claimed_jobs_evaluated']=claimed_count
    result['central_target_binding_verified']=bool(scopes)
    result['exact_312_verified_claims']=scopes
    # The installed native runtime currently advertises only status/selftest.
    # Do not mark a queue executable until a real registered execute interface,
    # host enrollment and a fresh exact job are all present.
    native_ready=(registration.get('registration_state')=='ENROLLED'
                  and registration.get('central_dispatcher_binding_verified') is True
                  and registration.get('ssh_credential_enrolled') is True
                  and registration.get('host_registry_enrolled') is True
                  and registration.get('trusted_grant_verifier_bound') is True
                  and 'execute' in registration.get('interfaces', []))
    result['native_executor_enrolled_for_target']=native_ready
    result['bounded_fixed_canary_unit_installed']=bounded_diagnostic_unit_installed()
    result['bounded_fixed_canary_currently_eligible']=bool(
        result['bounded_fixed_canary_unit_installed'] and scopes
        and central_grant_ready and target_transport_ready
        and result['broker_connected'])
    # Fixed diagnostic lane is separate from the general Native Executor.
    result['central_queue_execution_eligible']=bool(
        native_ready and scopes and target_transport_ready and central_grant_ready
        and result['broker_connected'] and result['native_files_integrity_verified'])
    result['host_ssh_execution_authorized']=result['central_queue_execution_eligible']
    if not scopes:
        result['blockers'].extend(claim_scope_blockers(scopes, claimed_count))
    if not native_ready:
        result['blockers'].append('REMOTE_EXECUTOR_NOT_ENROLLED_FOR_TARGET')
    if result['blockers']:
        result['state']='WAITING_FOR_VERIFIED_HOST_AUTHORITY'
    else:
        result['state']='READY_FOR_AUTHENTICATED_EXECUTION'
    result['observed_at']=dt.datetime.now(dt.timezone.utc).isoformat()
    result['heartbeat_age_seconds']=round(age,1) if age is not None else None
    result['native_registration_state']=registration.get('registration_state','UNKNOWN')
    result['configured_companies']=sorted(config.get('companies',{}).keys())
    return result

def write_receipt(record):
    fd,tmp=tempfile.mkstemp(dir=BROKER,prefix='.native-ssh-reconcile-',suffix='.json')
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(record,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,REPORT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    event={'contract':'mad4b.central-vps-broker.v1','at':record['observed_at'],
           'event':'NATIVE_SSH_AUTHORITY_PENDING' if not record['host_ssh_execution_authorized'] else 'NATIVE_SSH_AUTHORITY_READY',
           'job_id':'','company':''}
    with (BROKER/'broker-events.jsonl').open('a',encoding='utf-8') as f:
        f.write(json.dumps(event,sort_keys=True)+'\n');f.flush();os.fsync(f.fileno())

def selftest():
    reg={'contract':'mad4b.native-managed-ssh-installation.v1','runtime_modules_sha256':{'a':'1'*64}}
    cfg={'contract':'mad4b.vps-broker-poller.v1','host_id':HOST}
    a=evaluate(cfg,reg,{'a':'1'*64},1,True)
    assert a['state']=='WAITING_FOR_VERIFIED_HOST_AUTHORITY'
    assert a['broker_connected'] and not a['host_ssh_execution_authorized']
    b=evaluate(cfg,reg,{'a':'1'*64},1,True,True,True)
    assert b['host_ssh_execution_authorized'] and b['state']=='READY_FOR_AUTHENTICATED_EXECUTION'
    assert 'NATIVE_SOURCE_DIGEST_MISMATCH' in evaluate(cfg,reg,{'a':'2'*64},1,True)['blockers']
    assert 'BROKER_HOST_BINDING_MISMATCH' in evaluate({'contract':cfg['contract'],'host_id':'wrong'},reg,{'a':'1'*64},1,True)['blockers']
    assert 'NO_FRESH_SIGNED_BROKER_HEARTBEAT' in evaluate(cfg,reg,{'a':'1'*64},700,True)['blockers']
    assert not evaluate(cfg,reg,{'a':'1'*64},1,False)['broker_connected']
    example={'job_id':'a'*32,'target_id':'hosting-1','command_sha256':'b'*64}
    proof={'contract':'mad4b.broker-exact-ssh-authority.v1','verified':True,
           'eligible':True,'central_scope_only':True,'shell_executed':False,
           'job_id':example['job_id'],'target_id':example['target_id'],
           'command_sha256':example['command_sha256'],
           'expires_epoch':int(time.time())+100}
    assert exact_scope_matches(example,proof,{'hosting-1'})
    assert not exact_scope_matches(example,{**proof,'shell_executed':True},{'hosting-1'})
    assert not exact_scope_matches(example,{**proof,'target_id':'hosting-3'},{'hosting-1'})
    assert not exact_scope_matches(example,proof,{'hosting-3'})
    assert claim_scope_blockers([], 0)==['NO_CURRENT_EXACT_ELIGIBLE_JOB']
    assert claim_scope_blockers([], None)==['CENTRAL_CLAIM_INVENTORY_UNAVAILABLE']
    assert 'CENTRAL_REMOTE_TARGET_SCOPE_BINDING_PENDING' in claim_scope_blockers([], 1)
    assert claim_scope_blockers([proof], 1)==[]
    print(json.dumps({'selftest':'PASS','assertions':14,'any_shell_executed':False}))

if __name__=='__main__':
    if len(sys.argv)!=2 or sys.argv[1] not in ('reconcile','status','selftest'):
        raise SystemExit('usage: broker-native-ssh-reconcile.py reconcile|status|selftest')
    if sys.argv[1]=='selftest': selftest()
    elif sys.argv[1]=='status':print(json.dumps(read_json(REPORT) if REPORT.is_file() else {'state':'NOT_YET_RECONCILED'},sort_keys=True))
    else:
        if os.geteuid()!=0:raise SystemExit('ROOT_BROKER_REQUIRED')
        result=measured()
        write_receipt(result)
        print(json.dumps(result,sort_keys=True))

