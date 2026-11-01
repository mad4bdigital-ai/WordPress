#!/usr/bin/env python3
"""Read-only original WordPress Core transport/evidence status.

Runs FIXED original WP-CLI reads using the existing dynamic enrolled actor.
A signed host-observation flag verifies the existing WordPress registry sync,
not a per-job grant, executable Native provider, or an Original 312 claim.
Never signs payloads, issues grants, selects --user, invokes shell commands,
retries uncertain work or produces a synthetic job/candidate admission.
"""
import json
import pathlib
import subprocess
import sys
import time

ROOT=pathlib.Path('/opt/mad4b-company-transport')
sys.path.insert(0,str(ROOT))
from wpcli_dynamic_actor import MARKER,with_actor

BASE=['/usr/bin/docker','exec','-u','www-data','etg-commerce-staging-php',
      '/usr/local/bin/wp','--path=/var/www/html','--skip-themes',MARKER]
CONTRACT='mad4b.original-core-channel-evidence-readback.v1'
SCOPE_SCRIPT=r"""
$s=MAD4B_Native_VPS_Principal_V1::scope();
$p=MAD4B_Native_VPS_Principal_V1::existing();
echo wp_json_encode(['contract'=>'mad4b.original-host-ledger-scope.v1',
 'scope'=>$s,'ledger_verified'=>is_array($p)&&MAD4B_Native_VPS_Principal_V1::ledger($p,$s),
 'site_origin'=>untrailingslashit(home_url('/')),
 'wordpress_environment'=>wp_get_environment_type()]);
"""

def read_fixed(args):
    cmd=with_actor(BASE+args)
    p=subprocess.run(cmd,capture_output=True,text=True,check=False,timeout=38)
    if p.returncode!=0 or p.stderr.strip() or len(p.stdout)>40000:
        raise RuntimeError('ORIGINAL_WORDPRESS_READBACK_UNAVAILABLE')
    s=p.stdout.strip()
    if not s.startswith('{'):
        raise RuntimeError('ORIGINAL_WORDPRESS_JSON_MISSING')
    data=json.loads(s)
    if not isinstance(data,dict):
        raise RuntimeError('ORIGINAL_WORDPRESS_JSON_INVALID')
    return data

def classify(scope,registry,verifier,claims,receipt,registration,now):
    blocked=[]
    src=scope.get('scope') if isinstance(scope,dict) else None
    if (not isinstance(src,dict) or
        scope.get('contract')!='mad4b.original-host-ledger-scope.v1' or
        scope.get('ledger_verified') is not True or
        src.get('contract')!='mad4b.central-host-scope.v1' or
        src.get('scope_kind')!='central_native_host' or
        src.get('environment')!='staging' or
        src.get('state')!='CENTRAL_HOST_IDENTITY_VERIFIED' or
        src.get('owner_enrolled') is not True or
        src.get('company_target') is not False or
        src.get('canonical_origin')!=scope.get('site_origin') or
        scope.get('wordpress_environment')!='staging'):
        blocked.append('ORIGINAL_NATIVE_HOST_LEDGER_UNVERIFIED')
    valid_registry=(isinstance(registry,dict) and
        registry.get('contract')=='mad4b.broker-registry-ssh-status.v1' and
        registry.get('host_id')==src.get('host_id') if isinstance(src,dict) else False)
    registry_time=registry.get('received_epoch') if isinstance(registry,dict) else None
    fresh=(type(registry_time) is int and now-600 < registry_time <= now+10)
    signed=(valid_registry and fresh and
            registry.get('signed_host_observation_verified') is True and
            registry.get('production_mutation') is False)
    if not signed:
        blocked.append('SIGNED_ORIGINAL_REGISTRY_STATUS_MISSING_OR_STALE')
    rows=registry.get('targets') if signed else []
    if not isinstance(rows,list) or len(rows)>25:
        rows=[]
        blocked.append('REGISTERED_TARGET_INVENTORY_INVALID')
    observed=[]
    for row in rows:
        if not isinstance(row,dict) or not isinstance(row.get('target_id'),str):
            blocked.append('REGISTERED_TARGET_ENTRY_INVALID');continue
        if len(row['target_id'])>128:
            blocked.append('REGISTERED_TARGET_ENTRY_INVALID');continue
        observed.append({
         'target_id':row['target_id'],
         'ssh_authenticated':row.get('ssh_authenticated') is True,
         'permission_scope':row.get('permission_scope') if
              row.get('permission_scope') in ('none','fixed_nonmutating_diagnostic') else 'none',
         'independent_core_execution_grant_verified':False,
         'eligible_for_alternative_dispatch':False,
        })
    valid_verifier=(isinstance(verifier,dict) and
        verifier.get('contract')=='mad4b.broker-exact-ssh-authority.v1' and
        verifier.get('verifier_registered') is True and
        verifier.get('central_scope_source')==
         'original_wordpress_grants_claims_and_delegations' and
        verifier.get('shell_executed') is False and
        verifier.get('remote_execution_authority_automatically_granted') is False)
    if not valid_verifier:blocked.append('ORIGINAL_312_VERIFIER_UNAVAILABLE')
    valid_claims=isinstance(claims,dict) and (
        claims.get('contract')=='mad4b.broker-exact-ssh-authority.v1') and (
        isinstance(claims.get('claimed'),list)) and len(claims['claimed'])<=10
    if not valid_claims:blocked.append('ORIGINAL_312_CLAIM_INVENTORY_UNAVAILABLE')
    claim_count=len(claims['claimed']) if valid_claims else None
    if claim_count==0:blocked.append('NO_CURRENT_EXACT_ELIGIBLE_JOB')
    if claim_count and claim_count>0:
        blocked.append('PER_JOB_312_SIGNATURE_AND_GRANT_READBACK_REQUIRED')
    if not (isinstance(receipt,dict) and
        receipt.get('contract')=='mad4b.broker-remote-ssh-diagnostic.v1' and
        receipt.get('registered') is True and
        receipt.get('only_fixed_nonmutating_diagnostic') is True and
        receipt.get('production_mutation') is False):
        blocked.append('ORIGINAL_DIAGNOSTIC_PROVIDER_UNVERIFIED')
    state=registration.get('registration_state') if isinstance(registration,dict) else None
    interfaces=registration.get('interfaces') if isinstance(registration,dict) else []
    if state!='ENROLLED' or not isinstance(interfaces,list) or 'execute' not in interfaces:
        blocked.append('NATIVE_EXECUTOR_NOT_ENROLLED')
    if registry.get('central_grant_verifier_bound') is not True:
        blocked.append('CENTRAL_GRANT_VERIFIER_NOT_BOUND')
    # A registered SSH host and a *signed transport observation* never imply
    # an enrolled command provider or a signed original per-job journal.
    return {'contract':CONTRACT,
       'state':'READ_ONLY_ORIGINAL_CORE_EVIDENCE_OBSERVED' if not blocked else
               'READ_ONLY_ORIGINAL_CORE_AUTHORITY_PENDING',
       'central_host_ledger_verified':'ORIGINAL_NATIVE_HOST_LEDGER_UNVERIFIED' not in blocked,
       'signed_transport_observation_verified':bool(signed),
       'central_verifier_registered':bool(valid_verifier),
       'original_claim_count':claim_count,
       'native_registration_state':state,
       'registered_diagnostic_targets':observed,
       'registered_alternative_execution_candidates':[],
       'signed_per_job_journal_verified':False,
       'independent_provider_admission_verified':False,
       'native_execute_authorized':False,
       'provider_recovery_authorized':False,
       'new_job_created':False,
       'operation_executed':False,
       'production_mutation':False,
       'blockers':sorted(set(blocked))}

def status():
    if sys.platform!='linux':
        raise RuntimeError('STAGING_LINUX_ONLY')
    regfile=pathlib.Path('/opt/mad4b-company-provisioning/governed-execution/native-ssh-v1/registration.json')
    if regfile.is_symlink() or not regfile.is_file():
        raise RuntimeError('NATIVE_REGISTRATION_MISSING')
    registration=json.loads(regfile.read_text())
    try:
        payload={
         'scope':read_fixed(['eval',SCOPE_SCRIPT]),
         'registry':read_fixed(['mad4b','broker-registry-ssh','status']),
         'verifier':read_fixed(['mad4b','broker-authority','status']),
         'claims':read_fixed(['mad4b','broker-authority','claimed','--limit=2']),
         'receipt':read_fixed(['mad4b','broker-remote-ssh','status']),
        }
        return classify(payload['scope'],payload['registry'],payload['verifier'],
                        payload['claims'],payload['receipt'],registration,int(time.time()))
    except (RuntimeError,ValueError,KeyError,subprocess.TimeoutExpired,OSError):
        return {'contract':CONTRACT,'state':'READBACK_UNAVAILABLE_FAIL_CLOSED',
                'blockers':['ORIGINAL_CORE_READBACK_UNAVAILABLE'],
                'provider_recovery_authorized':False,'native_execute_authorized':False,
                'new_job_created':False,'operation_executed':False,'production_mutation':False}

def selftest():
    now=2000000000
    scope={'contract':'mad4b.original-host-ledger-scope.v1',
      'scope':{'contract':'mad4b.central-host-scope.v1','scope_kind':'central_native_host',
       'environment':'staging','state':'CENTRAL_HOST_IDENTITY_VERIFIED',
       'owner_enrolled':True,'company_target':False,'canonical_origin':'https://central.example',
       'host_id':'original-host'},
      'ledger_verified':True,'site_origin':'https://central.example',
      'wordpress_environment':'staging'}
    registry={'contract':'mad4b.broker-registry-ssh-status.v1',
      'host_id':'original-host','received_epoch':now-10,
      'signed_host_observation_verified':True,'production_mutation':False,
      'targets':[{'target_id':'other-host','ssh_authenticated':True,
        'permission_scope':'fixed_nonmutating_diagnostic'}],
      'central_grant_verifier_bound':False}
    verifier={'contract':'mad4b.broker-exact-ssh-authority.v1',
      'verifier_registered':True,'central_scope_source':'original_wordpress_grants_claims_and_delegations',
      'shell_executed':False,'remote_execution_authority_automatically_granted':False}
    claims={'contract':'mad4b.broker-exact-ssh-authority.v1','claimed':[]}
    receipt={'contract':'mad4b.broker-remote-ssh-diagnostic.v1',
      'registered':True,'only_fixed_nonmutating_diagnostic':True,'production_mutation':False}
    reg={'registration_state':'INSTALLED_UNENROLLED','interfaces':['status','selftest']}
    def check(sc=scope,rg=registry,vr=verifier,cl=claims,rc=receipt,rt=reg):
        return classify(sc,rg,vr,cl,rc,rt,now)
    r=check()
    assert r['signed_transport_observation_verified'] is True
    assert r['central_host_ledger_verified'] is True
    assert r['original_claim_count']==0
    assert r['provider_recovery_authorized'] is False
    assert r['registered_alternative_execution_candidates']==[]
    assert r['registered_diagnostic_targets'][0]['eligible_for_alternative_dispatch'] is False
    assert 'NO_CURRENT_EXACT_ELIGIBLE_JOB' in r['blockers']
    assert 'NATIVE_EXECUTOR_NOT_ENROLLED' in r['blockers']
    assert not check(rg={**registry,'received_epoch':now-700})['signed_transport_observation_verified']
    assert not check(rg={**registry,'signed_host_observation_verified':False})['signed_transport_observation_verified']
    assert not check(sc={**scope,'ledger_verified':False})['central_host_ledger_verified']
    assert 'PER_JOB_312_SIGNATURE_AND_GRANT_READBACK_REQUIRED' in check(cl={
       **claims,'claimed':[{'job_id':'a'*32}]})['blockers']
    assert 'NATIVE_EXECUTOR_NOT_ENROLLED' not in check(rt={
       'registration_state':'ENROLLED','interfaces':['status','execute']})['blockers']
    assert not check(rt={'registration_state':'ENROLLED','interfaces':['status','execute']})['provider_recovery_authorized']
    print(json.dumps({'contract':CONTRACT,'selftest':'PASS','assertions':14,
                      'grants_created':0,'execution_performed':False}))

if __name__=='__main__':
    if len(sys.argv)!=2 or sys.argv[1] not in ('status','selftest'):
        raise SystemExit('usage: original-core-channel-evidence.py status|selftest')
    if sys.argv[1]=='selftest':selftest()
    else:print(json.dumps(status(),sort_keys=True,separators=(',',':')))
