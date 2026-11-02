#!/usr/bin/env python3
"""Company-wide automatic native contract reconciliation; no grants, live code writes or tenant mutation.

- Revalidates original central WordPress contracts from the live Docker container.
- Auto-repairs missing *source candidate* files from pinned, audited originals only.
- Tests candidate PHP contracts inside the container, without loading WordPress.
- Reconciles all enrolled company/environment plans, detects drift and records independent readback.
- Never retries native jobs or impersonates an owner. Existing Core alone may admit/execute.
"""
import argparse,datetime,fcntl,hashlib,json,os,pathlib,subprocess,sys,tempfile
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
LIVE=pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins')
CAND=ROOT/'source-candidates/company-native-operation-contract-v1'
REPORT=ROOT/'reports/company-native-contract-reconciliation-current.json'
HISTORY=ROOT/'reports/company-native-contract-reconciliation-changes.jsonl'
NAMES=('300-mad4b-manager-auto-write.php','301-mad4b-company-operation-admission.php')
CONTRACT='mad4b.company-native-contract-auto-reconcile.v1'
def digest(data):return hashlib.sha256(data).hexdigest()
def checked_command(args,**kw):
    p=subprocess.run(args,capture_output=True,text=True,timeout=185,**kw)
    if p.returncode:raise RuntimeError('COMMAND_FAILED:'+str(args[0])+':'+(p.stderr or '')[-250:])
    return p.stdout
def expected_bytes(name,raw):
    text=raw.decode('utf-8')
    if name.startswith('300'):
        a="'operations.write'=>'operations_manage','commerce.write'=>'commerce_manage'];"
        b="'operations.write'=>'operations_manage','commerce.write'=>'commerce_manage',\n  'plugin.install'=>'operations_manage'];"
        if text.count(a)!=1:raise ValueError('MANAGER_SOURCE_TRANSFORM_INVALID')
        return text.replace(a,b,1).encode()
    a="const WRITE=['content.write','strategy.write','operations.write','commerce.write'];"
    b="const WRITE=['content.write','strategy.write','operations.write','commerce.write','plugin.install'];"
    if text.count(a)!=1:raise ValueError('ADMISSION_SOURCE_TRANSFORM_INVALID')
    text=text.replace(a,b,1)
    a="  $binding=MAD4B_Company_Portfolio_Manager_V1::company_binding($company);"
    replacement=("  // Plugin installation is a privileged company operation; delegated\n"
      "  // operations_manage alone never replaces WordPress install_plugins.\n"
      "  if($operation==='plugin.install'&&!current_user_can('install_plugins'))\n"
      "   return self::deny('plugin_install_wordpress_capability_required');\n")
    if text.count(a)!=1:raise ValueError('ADMISSION_CAPABILITY_ANCHOR_INVALID')
    text=text.replace(a,replacement+a,1)
    return text.encode()
def store(path,payload,mode=0o600):
    path.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='.reconcile-')
    try:
        with os.fdopen(fd,'wb') as h:h.write(payload);h.flush();os.fsync(h.fileno())
        os.chmod(tmp,mode);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
def php_lint(content):
    p=subprocess.run(['docker','exec','-i','-u','33:33','etg-commerce-staging-php',
                      'php','-l','/dev/stdin'],input=content,capture_output=True,timeout=30)
    if p.returncode:raise RuntimeError('ISOLATED_PHP_SYNTAX_DENIED')
def candidate_reconcile():
    m=json.loads((CAND/'manifest.json').read_text())
    if m.get('contract')!='mad4b.company-native-operation-source-candidate.v1' or set(m.get('files',{}))!=set(NAMES):
        raise RuntimeError('PINNED_CANDIDATE_MANIFEST_MISMATCH')
    statuses={};repairs=[]
    for name in NAMES:
        live=LIVE/name
        if not live.is_file() or live.is_symlink():raise RuntimeError('ORIGINAL_SITE_SOURCE_ABSENT')
        raw=live.read_bytes()
        row=m['files'][name];original=digest(raw)
        if original!=row['baseline_sha256']:
            statuses[name]='ORIGINAL_SOURCE_DRIFT_REVIEW_REQUIRED'
            continue
        prepared=expected_bytes(name,raw)
        if digest(prepared)!=row['candidate_sha256']:
            raise RuntimeError('PINNED_CANDIDATE_DIGEST_MISMATCH')
        target=CAND/name
        if target.exists() and (target.is_symlink() or not target.is_file()):
            raise RuntimeError('CANDIDATE_FILE_NOT_REGULAR')
        if not target.exists() or target.read_bytes()!=prepared:
            # Repair candidate-only artifacts. Never copy into WordPress MU/plugin paths.
            store(target,prepared,0o600)
            repairs.append(name)
        php_lint(target.read_bytes())
        statuses[name]='PREPARED_SYNTAX_VERIFIED_NOT_DEPLOYED'
    return statuses,repairs
def live_plans():
    output=checked_command([sys.executable,
        str(ROOT/'bin/company-native-publication-plan.py'),'--all-enrolled'])
    data=json.loads(output)
    if (data.get('contract')!='mad4b.company-native-publication-all-enrolled.v1'
        or data.get('production_mutated') is not False):
        raise RuntimeError('DYNAMIC_SITE_REGISTRY_READBACK_UNVERIFIED')
    if data.get('target_count')!=len(data.get('targets',[])):
        raise RuntimeError('DYNAMIC_TARGET_COUNT_INVALID')
    for x in data['targets']:
        if not x.get('company_code') or x.get('native_apply_executed') is not False:
            raise RuntimeError('COMPANY_SCOPE_OR_MUTATION_MISMATCH')
    return data
def original_core_channels():
    raw=checked_command([sys.executable,
      str(ROOT/'bin/company-original-core-channel-readback.py')])
    data=json.loads(raw)
    if (data.get('contract')!='mad4b.original-core-channel-aggregate.v1'
       or data.get('transport_or_grant_created') is not False
       or data.get('production_mutated') is not False
       or data.get('target_count')!=len(data.get('targets',[]))):
        raise RuntimeError('ORIGINAL_CHANNEL_AGGREGATE_INVALID')
    return data

def standing_default_actions(sites,channels):
    entries={}
    for item in channels['targets']:
        key=item['company']+':'+item['environment']
        if key in entries:raise RuntimeError('DUPLICATE_CHANNEL_TARGET')
        entries[key]=item
    results=[]
    for site in sites['targets']:
        key=site['company_code']+':'+site['environment']
        current=entries.get(key)
        if current is None:raise RuntimeError('CHANNEL_COMPANY_ENV_MISMATCH')
        if not current['site_identity_registered']:
            action='RECONCILE_ORIGINAL_COMPANY_SITE_IDENTITY'
        elif current['original_persistent_full_wpcli_grants']<1:
            # Requested by default; the original 305 owner-authenticated
            # enrollment is the only grant issuer. This monitor NEVER calls it.
            action='ENROLL_OWNER_VERIFIED_ORIGINAL_305_PERSISTENT_WPCLI_FULL'
        elif current['original_matched_active_transport_grant_pairs']<1:
            action=('VERIFY_ORIGINAL_HOST_PROOF_AND_ACTIVATE_ENROLLED_NATIVE_TRANSPORT'
                    if current['original_matched_pending_transport_grant_pairs']>0 else
                    'REPAIR_ORIGINAL_305_TRANSPORT_GRANT_PAIR_BINDING')
        elif site['state']=='BLOCKED_SOURCE_RUNTIME_INTEGRATION_GAPS':
            action='APPLY_TESTED_SOURCE_CONTRACTS_VIA_ORIGINAL_UPDATE_PATH'
        elif site['state']=='NATIVE_ELIGIBLE_REQUIRES_JOB_AND_ROLLBACK_RECEIPTS':
            action='RUN_ORIGINAL_312_NATIVE_READ_CANARY_313_AND_ROLLBACK_VERIFICATION'
        else:action='RECONCILE_ORIGINAL_NATIVE_EXECUTOR_AND_SITE_READBACK'
        results.append({
            'company_code':site['company_code'],'environment':site['environment'],
            'site_identity_registered':current['site_identity_registered'],
            'default_grant_mode':'persistent',
            'default_capabilities':['wpcli.full'],
            'original_persistent_grant_present':current['original_persistent_full_wpcli_grants']>0,
            'original_active_transport_present':current['original_matched_active_transport_grant_pairs']>0,
            'original_verified_transport_grant_pairs':current['original_matched_active_transport_grant_pairs'],
            'original_pending_transport_count':current['original_pending_full_wpcli_transports'],
            'expired_one_time_grants_ignored':current['original_expired_one_time_grants'],
            'standing_execution_eligible':current['original_matched_active_transport_grant_pairs']>0
               and site['state']=='NATIVE_ELIGIBLE_REQUIRES_JOB_AND_ROLLBACK_RECEIPTS',
            'next_original_typed_transition':action,
            'automatic_reconciliation_enabled':True,
            'enrollment_executed_by_reconciler':False,
            'grant_created_by_reconciler':False
        })
    if len(results)!=len(entries):raise RuntimeError('COMPANY_CHANNEL_SET_DIFFERS_FROM_REGISTRY')
    return results

def channel_runtime_preflight():
    script=ROOT/'bin/mad4b-execution-channel-preflight.py'
    result={}
    for name in ('central_identity',):
        raw=checked_command([sys.executable,str(script),name])
        data=json.loads(raw)
        required='CENTRAL_WPCLI_READ_CONFIRMED'
        if (data.get('contract')!='mad4b.execution-channel-preflight.v1'
            or data.get('state')!=required
            or data.get('required_key_name')!='shell_access'
            or data.get('tenant_production_mutated') is not False
            or data.get('original_native_execution_authorized') is not False):
            raise RuntimeError('CHANNEL_PREFLIGHT_UNEXPECTED_RESULT')
        # Do not embed observation times in the stable report fingerprint.
        result[name]=data['state']
    return result

def reconcile():
    policy=json.loads((ROOT/'policies/company-native-publication-lifecycle-v1.json').read_text())
    if policy.get('contract')!='mad4b.company-native-publication-lifecycle.v1':
        raise RuntimeError('DYNAMIC_NATIVE_LIFECYCLE_POLICY_INVALID')
    if policy['native_executor']['registration']!='original_core_only':
        raise RuntimeError('ORIGINAL_NATIVE_AUTHORITY_REQUIRED')
    channel=json.loads((ROOT/'policies/mad4b-execution-command-contract.v1.json').read_text())
    if (channel.get('contract')!='mad4b.execution-channel-command.v1'
        or channel.get('shell_connector')!='mcp__registry_infra_mad4b__exec_root_shell'
        or channel.get('shell_key_name')!='shell_access'
        or channel.get('native_execution',{}).get('authority')!='original_mad4b_core_305_306_312_313'
        or channel.get('native_execution',{}).get('default_grant_mode')!='persistent'
        or channel.get('native_execution',{}).get('default_capability')!='wpcli.full'
        or channel.get('live_execution_authorized_by_policy') is not False):
        raise RuntimeError('EXECUTION_CHANNEL_CONTRACT_DRIFT')
    statuses,repairs=candidate_reconcile()
    sites=live_plans()
    channels=original_core_channels()
    standing=standing_default_actions(sites,channels)
    channel_runtime=channel_runtime_preflight()
    # Universal original-evidence reconciliation: proof is NOT the 305 transport
    # and must never silently activate an executor. Read-only for all companies,
    # including Production. No site-specific tenant names or assumed owner.
    for row in standing:
        row['host_proof_independently_verified']=False
        row['original_transport_activation_performed']=False
        row['production_mutated_by_reconciler']=False
        if (not row['site_identity_registered']
            or not row['original_persistent_grant_present']
            or row['original_pending_transport_count']<1):
            row['host_proof_receipt_state']='NOT_ELIGIBLE_FOR_ORIGINAL_PROOF_READBACK'
            continue
        proof_cmd=['docker','exec','-u','33:33','etg-commerce-staging-php',
          '/usr/local/bin/wp','mad4b','company-native-host-proof','status',
          '--company='+row['company_code'],'--environment='+row['environment'],
          '--path=/var/www/html','--skip-themes']
        try:
            proof=json.loads(checked_command(proof_cmd).strip())
        except (RuntimeError,ValueError) as e:
            row['host_proof_receipt_state']='ORIGINAL_HOST_PROOF_READBACK_UNAVAILABLE'
            row['next_original_typed_transition']='RECONCILE_ORIGINAL_PROOF_READBACK'
            continue
        now=int(datetime.datetime.now(datetime.timezone.utc).timestamp())
        verified=(proof.get('contract')=='mad4b.company-native-host-proof.v1'
          and proof.get('company')==row['company_code']
          and proof.get('environment')==row['environment']
          and proof.get('state')=='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'
          and proof.get('verified') is True
          and proof.get('native_executor_certified') is False
          and proof.get('production_mutation') is False
          and proof.get('native_job_executed') is False
          and type(proof.get('proof_epoch')) is int
          and 0<=now-proof['proof_epoch']<900)
        row['host_proof_independently_verified']=bool(verified)
        row['host_proof_receipt_state']=('ORIGINAL_SIGNED_HOST_PROOF_FRESH'
          if verified else 'ORIGINAL_HOST_PROOF_MISSING_STALE_OR_MISMATCHED')
        row['next_original_typed_transition']=(
          'ACTIVATE_ORIGINAL_ENROLLED_305_TRANSPORT_VIA_AUTHENTICATED_ISSUER'
          if verified else 'REISSUE_ORIGINAL_EXACT_SIGNED_HOST_PROOF')
        # 305 grants, host proofs and standing transport all remain separate.
        # No admin user override, implicit entitlement or operation dispatched.
    handler_cert=json.loads(checked_command([
        sys.executable,str(ROOT/'bin/company-native-handler-source-cert.py')]))
    if (handler_cert.get('state')!='STAGING_SOURCE_CANDIDATE_EXACT_TESTS_VERIFIED'
        or handler_cert.get('tests')!=21
        or handler_cert.get('313_verified') is not False
        or handler_cert.get('native_handler_service_enabled') is not False
        or handler_cert.get('production_mutated') is not False):
        raise RuntimeError('NATIVE_HANDLER_STAGING_SOURCE_CERT_NOT_VERIFIED')
    staging_handler=json.loads(checked_command([
        'docker','exec','-u','33:33','etg-commerce-staging-php',
        '/usr/local/bin/wp','mad4b','company-native-handler','status',
        '--path=/var/www/html','--skip-themes']))
    if (staging_handler.get('contract')!='mad4b.company-native-handler-staging-runtime.v1'
        or staging_handler.get('state')!='STAGING_SUPERVISOR_ACTIVE_READ_ONLY'
        or staging_handler.get('staging_supervisor_current') is not True
        or staging_handler.get('source_tests_passed')!=21
        or staging_handler.get('execution_authorized') is not False
        or staging_handler.get('original_313_independent_readback_verified') is not False
        or staging_handler.get('production_mutated') is not False):
        raise RuntimeError('LIVE_CENTRAL_STAGING_HANDLER_SUPERVISOR_NOT_VERIFIED')
    central_evidence=json.loads(checked_command([
        sys.executable,str(ROOT/'bin/central-native-dispatch-evidence.py'),'check']))
    if (central_evidence.get('contract')!='mad4b.central-original-native-dispatch-evidence.v1'
        or central_evidence.get('state')!='CENTRAL_ORIGINAL_306_312_314_READBACK_VERIFIED'
        or not isinstance(central_evidence.get('verified_completed_canaries'),int)
        or central_evidence.get('tenant_wpcli_313_certified') is not False
        or central_evidence.get('tenant_production_mutated') is not False
        or central_evidence.get('grants_issued') is not False):
        raise RuntimeError('CENTRAL_NATIVE_DISPATCH_READBACK_UNVERIFIED')
    universal=json.loads(checked_command([
        sys.executable,str(ROOT/'bin/company-native-universal-readiness.py'),'all-enrolled']))
    if (universal.get('state')!='ALL_ENROLLED_NATIVE_READINESS_OBSERVED'
        or universal.get('companies')!=sites['company_count']
        or universal.get('targets')!=sites['target_count']
        or not isinstance(universal.get('actions'),dict)
        or len(universal['actions'])!=sites['target_count']
        or not isinstance(universal.get('active_executors'),int)
        or universal.get('production_mutated') is not False):
        raise RuntimeError('UNIVERSAL_ORIGINAL_NATIVE_READINESS_MISMATCH')
    state={
        'contract':CONTRACT,
        'company_count':sites['company_count'],'target_count':sites['target_count'],
        'targets':sites['targets'],
        'standing_execution_default':'original_305_persistent_wpcli.full',
        'execution_command_contract':channel['contract'],
        'shell_transport':channel['shell_connector'],
        'container_wordpress_runtime':channel['wordpress_runtime']['container'],
        'channel_runtime_preflight':channel_runtime,
        'native_handler_staging_runtime':{
          'state':staging_handler['state'],
          'status_current':True,
          'source_tests_passed':staging_handler['source_tests_passed'],
          'registered_staging_targets':staging_handler['registered_staging_targets'],
          'signed_job_producer_certified':False,
          'original_313_verified':False,
          'execution_authorized':False,
          'production_mutated':False
        },
        'native_handler_source_certification':{
          'state':handler_cert['state'],
          'tests_passed':handler_cert['tests'],
          'original_313_verified':False,
          'native_handler_service_enabled':False,
          'production_mutated':False
        },
        'central_native_dispatch_evidence':{
          'state':central_evidence['state'],
          'verified_completed_canaries':central_evidence['verified_completed_canaries'],
          'tenant_wpcli_313_certified':False,
          'tenant_production_mutated':False
        },
        'universal_native_readiness':{
          'companies':universal['companies'],'targets':universal['targets'],
          'actions':universal['actions'],
          'active_executors':universal['active_executors'],
          'production_mutated':False
        },
        'standing_execution_enrollment':standing,
        'source_candidates':statuses,
        'source_candidate_repairs':repairs,
        'deployed_code_mutated':False,'site_or_company_mutated':False,
        'grants_or_transports_created':False,
        'native_jobs_dispatched':False,
        'automatic_source_candidate_repair_enabled':True,
        'original_core_authorization_required':True
    }
    stable={k:v for k,v in state.items() if k!='source_candidate_repairs'}
    fingerprint=digest(json.dumps(stable,sort_keys=True,ensure_ascii=False,separators=(',',':')).encode())
    prior={}
    if REPORT.is_file():
        try:prior=json.loads(REPORT.read_text())
        except (ValueError,OSError):pass
    state['fingerprint_sha256']=fingerprint
    state['generated_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    state['changed_since_prior']=(prior.get('fingerprint_sha256')!=fingerprint)
    state['status']='CANDIDATE_RECONCILED_ORIGINAL_APPLY_PENDING' if all(
        x=='PREPARED_SYNTAX_VERIFIED_NOT_DEPLOYED' for x in statuses.values()) else 'SOURCE_DRIFT_REVIEW_REQUIRED'
    store(REPORT,(json.dumps(state,ensure_ascii=False,indent=2)+'\n').encode())
    if state['changed_since_prior']:
        with HISTORY.open('a',encoding='utf8') as f:
            f.write(json.dumps({'at':state['generated_utc'],'fingerprint':fingerprint,
                'status':state['status'],'company_count':state['company_count'],
                'target_count':state['target_count']},ensure_ascii=False)+'\n')
    return state
def selftest():
    raw=b"<?php\nfinal class X { const RIGHTS=['operations.write'=>'operations_manage','commerce.write'=>'commerce_manage']; }"
    assert b"'plugin.install'=>'operations_manage'" in expected_bytes(NAMES[0],raw)
    assert len(NAMES)==2
    cc=json.loads((ROOT/'policies/mad4b-execution-command-contract.v1.json').read_text())
    assert cc['shell_key_name']=='shell_access'
    assert cc['native_execution']['default_grant_mode']=='persistent'
    assert (ROOT/'bin/mad4b-execution-channel-preflight.py').is_file()
    assert (ROOT/'bin/company-native-universal-readiness.py').is_file()
    assert (ROOT/'bin/central-native-dispatch-evidence.py').is_file()
    assert (ROOT/'bin/company-native-handler-source-cert.py').is_file()
    assert standing_default_actions(
      {'targets':[{'company_code':'sample','environment':'staging','state':'BLOCKED'}]},
      {'targets':[{'company':'sample','environment':'staging','site_identity_registered':True,
        'original_persistent_full_wpcli_grants':0,'original_enrolled_full_wpcli_transports':0,
        'original_pending_full_wpcli_transports':0,'original_expired_one_time_grants':4,
        'original_matched_active_transport_grant_pairs':0,
        'original_matched_pending_transport_grant_pairs':0}]
      })[0]['next_original_typed_transition']=='ENROLL_OWNER_VERIFIED_ORIGINAL_305_PERSISTENT_WPCLI_FULL'
    assert not standing_default_actions(
      {'targets':[{'company_code':'sample','environment':'staging','state':'BLOCKED'}]},
      {'targets':[{'company':'sample','environment':'staging','site_identity_registered':True,
        'original_persistent_full_wpcli_grants':0,'original_enrolled_full_wpcli_transports':0,
        'original_pending_full_wpcli_transports':0,'original_expired_one_time_grants':4,
        'original_matched_active_transport_grant_pairs':0,
        'original_matched_pending_transport_grant_pairs':0}]
      })[0]['standing_execution_eligible']
    return {'state':'SELFTEST_PASS','tests':4,'live_deployment':False}
def transient_busy_status(error):
    # WordPress CLI concurrency is not a failed site registration or grant.
    # Keep the last complete report unchanged and retry at the existing timer.
    if 'STAGING_WPCLI_BUSY' not in str(error):
        return None
    return {
      'state':'DEFERRED_STAGING_WPCLI_BUSY',
      'retry':'NEXT_REGISTERED_SYSTEMD_TIMER',
      'last_complete_report_preserved':True,
      'current_reconcile_completed':False,
      'grants_issued':False,
      'native_jobs_dispatched':False,
      'production_mutated':False,
      'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()
    }

def main():
    args=argparse.ArgumentParser()
    args.add_argument('--once',action='store_true')
    args.add_argument('--selftest',action='store_true')
    opt=args.parse_args()
    if opt.selftest:print(json.dumps(selftest()));return
    if not opt.once:raise SystemExit('USE --once')
    # One plan reconcile per invocation. No iterative retry of an ambiguous job.
    row=reconcile()
    print(json.dumps({'state':row['status'],'companies':row['company_count'],
      'environments':row['target_count'],'candidate_repairs':row['source_candidate_repairs'],
      'state_changed':row['changed_since_prior'],'original_code_mutated':False,
      'production_mutated':False},ensure_ascii=False))
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,RuntimeError,subprocess.SubprocessError) as e:
        deferred=transient_busy_status(e)
        if deferred is not None:
            # An observation, not an execution claim or success receipt.
            store(ROOT/'reports/company-native-reconcile-last-attempt.json',
                  (json.dumps(deferred,indent=2)+'\n').encode())
            print(json.dumps(deferred))
            sys.exit(0)
        print('COMPANY_NATIVE_RECONCILIATION_BLOCKED:'+str(e)[:240],file=sys.stderr)
        sys.exit(2)
