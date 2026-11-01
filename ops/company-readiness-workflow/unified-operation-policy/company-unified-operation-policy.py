#!/usr/bin/env python3
"""Source-separated MAD4B operation policy projection for all registered tenants.

Original Core WP resolver is the operation decision authority. Universal
Resolver's operations are legacy observer/SSH transport capabilities ONLY.
Original 305 is a separate native transport/grant signal. No writes or grants.
"""
import argparse,datetime,hashlib,json,os,pathlib,re,subprocess,sys,tempfile,fcntl
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
LEGACY=pathlib.Path('/opt/mad4b-company-transport/universal-runtime-v1/policy.json')
REPORT=ROOT/'reports/company-unified-operation-policy-current.json'
CMD_CORE=ROOT/'bin/company-original-core-channel-readback.py'
CODE=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
UUID=re.compile(r'^[0-9a-f]{8}-(?:[0-9a-f]{4}-){3}[0-9a-f]{12}$',re.I)
OPERATIONS=('plugin.install','content.write')
CAPABILITY_CATALOG=pathlib.Path(__file__).with_name('full-host-capability-catalog.json')
WP=r'''
if(DB_NAME!=='etg_commerce_staging'||wp_get_environment_type()!=='staging'
 ||untrailingslashit(home_url('/'))!=='https://tours.dev.mad4b.com')
 WP_CLI::error('EXACT_ORIGINAL_CENTRAL_RUNTIME_REQUIRED');
if(!class_exists('MAD4B_Company_Environment_Registry_V2',false)
 ||!class_exists('MAD4B_Company_Execution_Resolver_V1',false))
 WP_CLI::error('ORIGINAL_OPERATION_RESOLVER_NOT_LOADED');
$rows=MAD4B_Company_Environment_Registry_V2::discover();
$out=[];
foreach($rows as $company=>$entry){
 if(!is_string($company)||!preg_match('/^[a-z][a-z0-9-]{2,47}$/D',$company))
   WP_CLI::error('ORIGINAL_COMPANY_ID_INVALID');
 foreach((array)($entry['environments']??[]) as $env=>$site) {
  if(!in_array($env,['staging','production'],true)||!is_array($site))continue;
  $operations=[];
  foreach(['plugin.install','content.write'] as $op) {
   $p=MAD4B_Company_Execution_Resolver_V1::plan($company,$env,$op);
   if(!is_array($p))WP_CLI::error('ORIGINAL_RESOLVER_PLAN_NOT_OBJECT');
   $operations[$op]=[
    'state'=>$p['state']??null,'selected_channel'=>$p['selected_channel']??null,
    'execution_allowed'=>($p['execution_allowed']??false)===true,
    'remote_write_permitted'=>($p['remote_write_permitted']??false)===true,
    'readback_required'=>($p['readback_required']??false)===true,
    'site_uuid'=>$p['site_uuid']??null,
    'canonical_origin'=>$p['canonical_origin']??null,
    'blockers'=>$p['blockers']??[]];
  }
  $out[]=['company'=>$company,'environment'=>$env,
    'site_uuid'=>$site['site_uuid']??null,
    'canonical_origin'=>$site['canonical_origin']??null,
    'original_operations'=>$operations];
 }
}
echo wp_json_encode(['contract'=>'mad4b.original-operation-admission-projection.v1',
 'central_origin'=>untrailingslashit(home_url('/')),
 'target_count'=>count($out),'targets'=>$out,
 'grant_issued'=>false,'operation_executed'=>false,'production_mutated'=>false],
 JSON_UNESCAPED_SLASHES);
'''
def load_json(path):
    if not path.is_file() or path.is_symlink():raise RuntimeError('SOURCE_NOT_REGULAR_FILE')
    return json.loads(path.read_text())
def original_rows():
    p=subprocess.run(['docker','exec','-u','33:33','etg-commerce-staging-php',
      'php','-d','memory_limit=512M','/usr/local/bin/wp','eval',WP,
      '--path=/var/www/html','--skip-themes'],capture_output=True,text=True,timeout=115)
    if p.returncode:raise RuntimeError('ORIGINAL_OPERATION_PLAN_READ_FAILED:'+p.stderr[-180:])
    rows=[x for x in p.stdout.splitlines() if x.startswith('{')]
    if len(rows)!=1:raise RuntimeError('ORIGINAL_OPERATION_PLAN_JSON_NOT_EXACT')
    obj=json.loads(rows[0])
    if (obj.get('contract')!='mad4b.original-operation-admission-projection.v1'
        or obj.get('central_origin')!='https://tours.dev.mad4b.com'
        or obj.get('target_count')!=len(obj.get('targets',[]))
        or obj.get('grant_issued') is not False
        or obj.get('operation_executed') is not False
        or obj.get('production_mutated') is not False):
        raise RuntimeError('ORIGINAL_OPERATION_PLAN_CONTRACT_DRIFT')
    return obj
def original_305():
    p=subprocess.run([sys.executable,str(CMD_CORE)],capture_output=True,text=True,timeout=145)
    if p.returncode:raise RuntimeError('ORIGINAL_305_READ_FAILED:'+p.stderr[-150:])
    d=json.loads(p.stdout)
    if (d.get('contract')!='mad4b.original-core-channel-aggregate.v1'
        or d.get('target_count')!=len(d.get('targets',[]))
        or d.get('production_mutated') is not False
        or d.get('transport_or_grant_created') is not False):
        raise RuntimeError('ORIGINAL_305_CONTRACT_INVALID')
    return d
def classify_legacy(v):
    if v is None:return {'scope':'NOT_IN_LEGACY_TRANSPORT_REGISTRY',
       'transport_capabilities':[],'native_execution_granted_by_legacy':False}
    if not isinstance(v,dict) or not isinstance(v.get('operations'),list):
        raise RuntimeError('LEGACY_TRANSPORT_SCOPE_INVALID')
    ops=v['operations']
    if any(not isinstance(x,str) or not re.fullmatch('[a-z_]+:[a-z0-9_]+',x) for x in ops):
        raise RuntimeError('LEGACY_TRANSPORT_CAPABILITY_FORMAT_INVALID')
    if len(set(ops))!=len(ops):raise RuntimeError('LEGACY_DUPLICATE_TRANSPORT_CAPABILITY')
    if v.get('release_observer') and ops:
        raise RuntimeError('READ_ONLY_OBSERVER_CLAIMS_MUTATING_CAPABILITIES')
    kind=('READ_ONLY_RELEASE_OBSERVER' if v.get('release_observer') else
          'EXACT_LEGACY_TRANSPORT_CAPABILITIES' if ops else 'LEGACY_SCOPE_EMPTY')
    return {'scope':kind,'transport_capabilities':sorted(ops),
      'native_execution_granted_by_legacy':False}
def operation_decision(op,site,plan):
    if op not in OPERATIONS:raise RuntimeError('UNKNOWN_OPERATION')
    if not isinstance(plan,dict):raise RuntimeError('ORIGINAL_OPERATION_PLAN_INVALID')
    origin=site.get('canonical_origin');uuid=site.get('site_uuid')
    exact=(isinstance(uuid,str) and UUID.fullmatch(uuid) is not None
        and isinstance(origin,str) and origin.startswith('https://')
        and '/' not in origin[8:])
    if not exact:return {'state':'SITE_IDENTITY_PENDING',
       'authority_source':'ORIGINAL_COMPANY_CORE_OPERATION_RESOLVER',
       'eligible_for_native_wordpress_operation':False,
       'blockers':['site_identity_unverified']}
    if plan.get('execution_allowed') is True or plan.get('remote_write_permitted') is True:
        if plan.get('site_uuid')!=uuid or plan.get('canonical_origin')!=origin:
            raise RuntimeError('AUTHORIZING_ORIGINAL_OPERATION_SITE_SCOPE_MISMATCH')
    core_ready=(plan.get('state')=='READY_FOR_EXACT_NATIVE_WRITE'
       and plan.get('selected_channel')=='native_company_wordpress_write'
       and plan.get('execution_allowed') is True
       and plan.get('remote_write_permitted') is True
       and plan.get('readback_required') is True
       and plan.get('site_uuid')==uuid and plan.get('canonical_origin')==origin)
    return {'state':'ORIGINAL_OPERATION_PLAN_ELIGIBLE_FURTHER_WORDPRESS_ADMISSION' if core_ready
        else 'ORIGINAL_OPERATION_AUTHORITY_PENDING',
      'original_plan_state':plan.get('state'),
      'authority_source':'ORIGINAL_COMPANY_CORE_OPERATION_RESOLVER',
      'eligible_for_native_wordpress_operation':core_ready,
      'independent_operation_apply_verified':False,
      'blockers':plan.get('blockers',[]) if isinstance(plan.get('blockers'),list) else ['original_plan_blockers_invalid']}
def full_host_catalog():
    """Read the default capability *menu*, never treat it as an issued grant."""
    catalog=load_json(CAPABILITY_CATALOG)
    if (catalog.get('contract')!='mad4b.company.full-host-capability-template.v1'
        or catalog.get('status')!='NON_AUTHORIZING_PROVISIONING_CATALOG'
        or catalog.get('applies_to')!=['staging','production']
        or catalog.get('capability_grants_created_by_catalog') is not False
        or catalog.get('execution_enabled_by_catalog') is not False
        or catalog.get('issuance_authority')!='original_mad4b_core_registry_and_native_dispatcher'):
        raise RuntimeError('FULL_HOST_CAPABILITY_CATALOG_AUTHORITY_DRIFT')
    required={'company','environment','site_uuid','canonical_origin','host_id',
              'agent_public_id','server_id','provider','ability_name','command_sha256'}
    if set(catalog.get('binding_required',[]))!=required:
        raise RuntimeError('FULL_HOST_CAPABILITY_BINDING_INCOMPLETE')
    entries=catalog.get('capabilities',[])
    required_families={'host','wordpress','database','native_executor','transport'}
    if (not isinstance(entries,list) or len(entries)!=13
        or {e.get('family') for e in entries if isinstance(e,dict)}!=required_families
        or any(not isinstance(e,dict) or e.get('default_state')!='DISCOVERABLE_NOT_GRANTED'
               or not isinstance(e.get('id'),str)
               or not re.fullmatch(r'[a-z]+(?:[._][a-z]+)+',e['id']) for e in entries)
        or len({e['id'] for e in entries})!=len(entries)):
        raise RuntimeError('FULL_HOST_CAPABILITY_SET_INVALID')
    if catalog.get('supported_channels')!=['oauth_mcp','vps_ssh_wpcli','signed_broker','ssh_fallback']:
        raise RuntimeError('FULL_HOST_TRANSPORT_SET_INVALID')
    return catalog

def full_host_provisioning_preview(site,original_305,catalog):
    """Company-wide default applies to every exact target, without issuing grants."""
    company,env=site['company'],site['environment']
    uuid,origin=site.get('site_uuid'),site.get('canonical_origin')
    enrolled=original_305.get('site_identity_registered') is True
    counts=('original_persistent_full_wpcli_grants',
            'original_matched_active_transport_grant_pairs',
            'original_matched_pending_transport_grant_pairs')
    if any(not isinstance(original_305.get(k,0),int)
           or isinstance(original_305.get(k,0),bool)
           or original_305.get(k,0)<0 for k in counts):
        raise RuntimeError('ORIGINAL_305_GRANT_COUNTER_INVALID')
    permanent=original_305.get('original_persistent_full_wpcli_grants',0)
    active=original_305.get('original_matched_active_transport_grant_pairs',0)
    pending=original_305.get('original_matched_pending_transport_grant_pairs',0)
    identity_ok=(isinstance(company,str) and CODE.fullmatch(company) is not None
        and env in ('staging','production')
        and isinstance(uuid,str) and UUID.fullmatch(uuid) is not None
        and isinstance(origin,str) and origin.startswith('https://')
        and '/' not in origin[8:])
    if not identity_ok or not enrolled:
        state='PENDING_EXACT_SITE_ENROLLMENT'
        transition='RECONCILE_ORIGINAL_COMPANY_SITE_IDENTITY'
    elif permanent<1:
        state='PENDING_ORIGINAL_305_OWNER_VERIFIED_STANDING_GRANT'
        transition='ENROLL_OWNER_VERIFIED_ORIGINAL_305_PERSISTENT_WPCLI_FULL'
    elif active<1:
        state=('PENDING_EXACT_HOST_PROOF_AND_NATIVE_TRANSPORT' if pending
               else 'PENDING_ORIGINAL_305_TRANSPORT_GRANT_BINDING')
        transition=('VERIFY_ORIGINAL_HOST_PROOF_AND_ACTIVATE_ENROLLED_NATIVE_TRANSPORT'
                    if pending else 'REPAIR_ORIGINAL_305_TRANSPORT_GRANT_PAIR_BINDING')
    else:
        state='PENDING_INDEPENDENT_NATIVE_EXECUTOR_AND_313_READBACK'
        transition='VERIFY_ORIGINAL_NATIVE_OPERATION_312_313'
    return {
      'contract':'mad4b.company.full-host-provisioning-preview.v1',
      'company':company,'environment':env,'site_uuid':uuid,'canonical_origin':origin,
      'capability_policy':'DEFAULT_DISCOVERABLE_ON_ENROLLED_SITE',
      'capabilities':sorted(e['id'] for e in catalog['capabilities']),
      'capability_count':len(catalog['capabilities']),
      'provisioning_trigger':catalog['provisioning_trigger'],
      'evidence_source':'ORIGINAL_CORE_SITE_REGISTRY_AND_305',
      'site_identity_registered_by_original_core':enrolled,
      'scope_state':state,
      'next_original_typed_transition':transition,
      'original_305_persistent_wpcli_grant_count':permanent,
      'original_305_active_host_grant_pair_count':active,
      'original_305_pending_host_grant_pair_count':pending,
      'host_execution_proof_verified_by_preview':False,
      'exact_company_site_host_binding_required':True,
      'cross_company_wildcard_allowed':False,
      'grant_reconciliation_requires_original_core':True,
      'grant_issued_by_preview':False,'execution_authorized_by_preview':False,
      'production_mutated':False,
    }
def project(plans,grants,legacy):
    catalog=full_host_catalog()
    if plans.get('target_count')!=len(plans.get('targets',[])) or grants.get('target_count')!=len(grants.get('targets',[])):
        raise RuntimeError('TARGET_COUNT_INVALID')
    if legacy.get('contract')!='mad4b.universal-runtime-reconciler.v1':
        raise RuntimeError('LEGACY_TRANSPORT_POLICY_CONTRACT_INVALID')
    if legacy.get('authority_model')!='identity_proof_plus_capability_specific_provider_authority':
        raise RuntimeError('LEGACY_TRANSPORT_AUTHORITY_MODEL_INVALID')
    grantindex={}
    for item in grants['targets']:
        key=item['company']+':'+item['environment']
        if key in grantindex:raise RuntimeError('ORIGINAL_305_DUPLICATE_SITE')
        grantindex[key]=item
    seen=set();out=[]
    for site in plans['targets']:
        company,env=site.get('company'),site.get('environment')
        if not isinstance(company,str) or not CODE.fullmatch(company) or env not in ('staging','production'):
            raise RuntimeError('ORIGINAL_OPERATION_SITE_KEY_INVALID')
        key=company+':'+env
        if key in seen:raise RuntimeError('ORIGINAL_OPERATION_DUPLICATE_SITE')
        seen.add(key)
        if key not in grantindex:raise RuntimeError('ORIGINAL_305_AND_RESOLVER_TARGET_MISMATCH')
        old=legacy.get('scopes',{}).get(key)
        if old is not None and (old.get('site_uuid')!=site.get('site_uuid')
          or old.get('canonical_origin')!=site.get('canonical_origin') or old.get('environment')!=env):
            raise RuntimeError('LEGACY_OBSERVER_AND_ORIGINAL_CORE_SITE_MISMATCH')
        g=grantindex[key]
        pair_count=g.get('original_matched_active_transport_grant_pairs',0)
        if not isinstance(pair_count,int) or isinstance(pair_count,bool) or pair_count<0:
            raise RuntimeError('ORIGINAL_ACTIVE_PAIR_COUNT_INVALID')
        ops=site.get('original_operations')
        if not isinstance(ops,dict) or set(ops)!=set(OPERATIONS):
            raise RuntimeError('ORIGINAL_OPERATION_SET_INVALID')
        out.append({'target':key,'site_uuid':site.get('site_uuid'),
          'canonical_origin':site.get('canonical_origin'),
          'identity_registered_by_original_305':g.get('site_identity_registered') is True,
          'legacy_transport':classify_legacy(old),
          'original_305_native_ssh_active_pair_present':pair_count>0,
          'original_305_native_ssh_pending_pair_count':g.get('original_matched_pending_transport_grant_pairs',0),
          'operations':{op:operation_decision(op,site,ops[op]) for op in OPERATIONS},
          'full_host_authority':full_host_provisioning_preview(site,g,catalog),
          'legacy_operations_are_not_native_write_grants':True,
          'grant_or_job_issued_by_projection':False,
          'original_312_job_verified_by_projection':False,
          'original_313_receipt_verified_by_projection':False,
          'production_mutated':False})
    if seen!=set(grantindex):raise RuntimeError('ORIGINAL_305_AND_RESOLVER_CATALOG_DIFFER')
    return {'contract':'mad4b.unified-operation-policy-projection.v1',
      'status':'ORIGINAL_CORE_AND_LEGACY_TRANSPORT_SEMANTICS_SEPARATED',
      'original_authority':'live_mad4b_company_execution_resolver',
      'native_ssh_grant_authority':'original_core_305_transport_grant',
      'legacy_transport_authority':'transport_capabilities_only',
      'full_host_capability_catalog_contract':catalog['contract'],
      'full_host_template_is_not_grant':True,
      'company_count':len({x['target'].split(':')[0] for x in out}),
      'target_count':len(out),'targets':sorted(out,key=lambda x:x['target']),
      'grants_created':False,'operations_executed':False,'production_mutated':False,
      'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat()}
def project_enrollment_event(plans,grants,legacy,event):
    """Re-evaluate a claimed enrollment against *fresh* Original Core inventory.
    It cannot authorize any mutation, grant, host transport or operation.
    """
    if not isinstance(event,dict) or set(event)!={'company','environment','site_uuid','canonical_origin'}:
        raise RuntimeError('COMPANY_ENROLLMENT_EVENT_INVALID')
    company,env=event['company'],event['environment']
    if (not isinstance(company,str) or not CODE.fullmatch(company)
        or env not in ('staging','production')
        or not isinstance(event['site_uuid'],str) or not UUID.fullmatch(event['site_uuid'])
        or not isinstance(event['canonical_origin'],str)):
        raise RuntimeError('COMPANY_ENROLLMENT_EVENT_IDENTITY_INVALID')
    report=project(plans,grants,legacy)
    matches=[site for site in report['targets'] if site['target']==company+':'+env]
    if len(matches)!=1:raise RuntimeError('COMPANY_ENROLLMENT_NOT_IN_ORIGINAL_REGISTRY')
    current=matches[0]
    if (event['site_uuid']!=current['site_uuid']
        or event['canonical_origin']!=current['canonical_origin']):
        raise RuntimeError('COMPANY_ENROLLMENT_SCOPE_CHANGED_OR_UNVERIFIED')
    return {
        'contract':'mad4b.company.full-host-enrollment-event-projection.v1',
        'target':current['target'],
        'full_host_authority':current['full_host_authority'],
        'original_core_registration_rechecked':True,
        'grant_issued':False,'host_credential_created':False,
        'native_operation_executed':False,'production_mutated':False,
    }

def selftest():
    site={'site_uuid':'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa',
      'canonical_origin':'https://staging.example.org'}
    plans={'target_count':1,'targets':[{'company':'sample','environment':'staging',
      **site,'original_operations':{op:{'state':'BLOCKED_MISSING_AUTHORITY',
      'execution_allowed':False,'remote_write_permitted':False,'blockers':['missing_owner']}
      for op in OPERATIONS}}]}
    grants={'target_count':1,'targets':[{'company':'sample','environment':'staging',
      'site_identity_registered':True,'original_matched_active_transport_grant_pairs':0}]}
    legacy={'contract':'mad4b.universal-runtime-reconciler.v1',
      'authority_model':'identity_proof_plus_capability_specific_provider_authority',
      'scopes':{'sample:staging':{**site,'environment':'staging','operations':[],
       'release_observer':'test_observer'}}}
    x=project(plans,grants,legacy)['targets'][0]
    assert x['legacy_transport']['scope']=='READ_ONLY_RELEASE_OBSERVER'
    assert x['operations']['plugin.install']['eligible_for_native_wordpress_operation'] is False
    n=2
    legacy['scopes']['sample:staging'].update({'operations':['remote_exec:site_root'],'release_observer':None})
    x=project(plans,grants,legacy)['targets'][0]
    assert x['legacy_transport']['scope']=='EXACT_LEGACY_TRANSPORT_CAPABILITIES'
    assert not x['operations']['plugin.install']['eligible_for_native_wordpress_operation']
    n+=2
    grants['targets'][0]['original_matched_active_transport_grant_pairs']=1
    x=project(plans,grants,legacy)['targets'][0]
    assert x['original_305_native_ssh_active_pair_present'] is True
    assert not x['operations']['content.write']['eligible_for_native_wordpress_operation']
    n+=2
    op=plans['targets'][0]['original_operations']['plugin.install']
    op.update({'state':'READY_FOR_EXACT_NATIVE_WRITE',
      'selected_channel':'native_company_wordpress_write','execution_allowed':True,
      'remote_write_permitted':True,'readback_required':True,**site})
    x=project(plans,grants,legacy)['targets'][0]
    assert x['operations']['plugin.install']['eligible_for_native_wordpress_operation'] is True
    assert x['operations']['plugin.install']['independent_operation_apply_verified'] is False
    n+=2
    op['site_uuid']='bbbbbbbb-bbbb-bbbb-bbbb-bbbbbbbbbbbb'
    try:project(plans,grants,legacy)
    except RuntimeError as e:assert 'SCOPE_MISMATCH' in str(e)
    else:raise AssertionError('WRONG_SITE_WRITE_AUTHORITY_ACCEPTED')
    n+=1
    op['site_uuid']=site['site_uuid']
    legacy['scopes']['sample:staging']['release_observer']='test_observer'
    try:project(plans,grants,legacy)
    except RuntimeError as e:assert 'OBSERVER_CLAIMS' in str(e)
    else:raise AssertionError('READONLY_OBSERVER_WRITE_ACCEPTED')
    n+=1
    return {'state':'SELFTEST_PASS','tests':n,'original_grants_created':False}
def main():
    ap=argparse.ArgumentParser();ap.add_argument('action',choices=['selftest','once','event'])
    ap.add_argument('--company');ap.add_argument('--environment')
    ap.add_argument('--site-uuid');ap.add_argument('--canonical-origin')
    args=ap.parse_args()
    if args.action=='selftest':print(json.dumps(selftest()));return
    if args.action=='event':
        expected={'company':args.company,'environment':args.environment,
                  'site_uuid':args.site_uuid,'canonical_origin':args.canonical_origin}
        result=project_enrollment_event(original_rows(),original_305(),load_json(LEGACY),expected)
        print(json.dumps(result,sort_keys=True))
        return
    with open('/run/lock/mad4b-unified-operation-policy.lock','a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        obj=project(original_rows(),original_305(),load_json(LEGACY))
        REPORT.parent.mkdir(exist_ok=True,parents=True)
        fd,tmp=tempfile.mkstemp(prefix='.unified-operation-',dir=REPORT.parent)
        try:
            with os.fdopen(fd,'w') as f:
                json.dump(obj,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
            os.chmod(tmp,0o600);os.replace(tmp,REPORT)
        finally:
            if os.path.exists(tmp):os.unlink(tmp)
        print(json.dumps({'state':obj['status'],'company_count':obj['company_count'],
          'target_count':obj['target_count'],'source':'original_core_live',
          'legacy_operations_granted_native_write':False,
          'grants_created':False,'production_mutated':False,'report':str(REPORT)}))
if __name__=='__main__':
    try:main()
    except (ValueError,RuntimeError,KeyError,OSError,subprocess.SubprocessError,AssertionError) as e:
        print('UNIFIED_OPERATION_POLICY_FAIL_CLOSED:'+str(e)[:220],file=sys.stderr)
        sys.exit(2)
