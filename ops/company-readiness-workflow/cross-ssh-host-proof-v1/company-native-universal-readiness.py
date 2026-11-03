#!/usr/bin/env python3
"""Original MAD4B native site readiness for every registered company/environment.

Observes existing 305 channel, company registry, fresh host proof, core
source/admission gates, independent 312/313 native evidence. Fail-closed.
No owner impersonation, no grant minting, no native jobs, no tenant mutation.
"""
import argparse,datetime,hashlib,json,os,pathlib,re,subprocess,sys,tempfile
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
REPORT=ROOT/'reports/company-native-universal-readiness-current.json'
CODE=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=('staging','production')
CONTRACT='mad4b.company-native-universal-readiness.v1'
WP=['docker','exec','-u','33:33','etg-commerce-staging-php',
    '/usr/local/bin/wp']
BASE=['--path=/var/www/html','--skip-themes']

def stop(error):
    raise RuntimeError(error)
def run(args, timeout=90):
    r=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    if r.returncode:stop('READ_ONLY_COMMAND_DENIED_'+str(args[0])+':'+r.stderr[-130:])
    try:return json.loads(r.stdout.strip().splitlines()[-1])
    except (ValueError,IndexError):raise RuntimeError('STRICT_JSON_READBACK_FAILED') from None
def combine(sites,channels,proofs,certifications=None):
    if (sites.get('contract')!='mad4b.company-native-publication-all-enrolled.v1'
     or channels.get('contract')!='mad4b.original-core-channel-aggregate.v1'
     or sites.get('production_mutated') is not False
     or channels.get('production_mutated') is not False
     or channels.get('transport_or_grant_created') is not False):
        stop('ORIGINAL_REGISTRY_CONTRACT_INVALID')
    site_rows=sites.get('targets',[])
    ch_rows=channels.get('targets',[])
    if not isinstance(site_rows,list) or not isinstance(ch_rows,list) or sites.get('target_count')!=len(site_rows) or channels.get('target_count')!=len(ch_rows):
        stop('REGISTRY_TARGET_COUNT_MISMATCH')
    def keyed(rows,company_key):
        found={}
        for r in rows:
            c=r.get(company_key); e=r.get('environment')
            if not isinstance(c,str) or not CODE.fullmatch(c) or e not in ENV:
                stop('COMPANY_ENV_SCOPE_INVALID')
            key=c+':'+e
            if key in found:stop('DUPLICATE_COMPANY_ENVIRONMENT')
            found[key]=r
        return found
    by_site=keyed(site_rows,'company_code')
    by_ch=keyed(ch_rows,'company')
    if set(by_site)!=set(by_ch):stop('ORIGINAL_CHANNEL_REGISTRY_SET_DRIFT')
    if not isinstance(proofs,dict) or set(proofs)-set(by_site):
        stop('HOST_PROOF_OUTSIDE_ORIGINAL_REGISTRY')
    certs=certifications or {}
    if set(certs)-set(by_site):stop('CERTIFICATE_OUTSIDE_ORIGINAL_REGISTRY')
    result=[]
    for key in sorted(by_site):
        site=by_site[key]; ch=by_ch[key]
        if any(type(ch.get(i)) is not int or ch[i]<0 for i in (
          'original_persistent_full_wpcli_grants',
          'original_matched_pending_transport_grant_pairs',
          'original_matched_active_transport_grant_pairs')):
            stop('ORIGINAL_PAIR_COUNT_INVALID')
        proof=proofs.get(key,{})
        if not isinstance(proof,dict):stop('HOST_PROOF_SCHEMA_INVALID')
        cert=certs.get(key,{})
        if not isinstance(cert,dict):stop('CERTIFICATE_SCHEMA_INVALID')
        enrolled=bool(ch.get('site_identity_registered'))
        grant=ch['original_persistent_full_wpcli_grants']>0
        pending=ch['original_matched_pending_transport_grant_pairs']>0
        active=ch['original_matched_active_transport_grant_pairs']>0
        proof_ready=proof.get('verified') is True and proof.get('state')=='HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'
        certified=cert.get('native_executor_certified') is True and cert.get('native_312_readback_verified') is True and cert.get('native_313_readback_verified') is True
        # Transport status ALONE never authorizes a job or production write.
        admissible=enrolled and grant and active and proof_ready and certified
        source_ready=(site.get('original_core_native_write_eligible') is True
           and not site.get('runtime_integration_gaps'))
        if not enrolled:next_step='RECONCILE_ORIGINAL_COMPANY_SITE_IDENTITY'
        elif not grant:next_step='ENROLL_EXACT_OWNER_ORIGINAL_305_PERSISTENT_WPCLI_FULL'
        elif not (pending or active):next_step='REPAIR_ORIGINAL_305_GRANT_TRANSPORT_PAIR'
        elif not proof_ready:next_step='VERIFY_EXACT_SITE_SIGNED_NATIVE_HOST_PROOF'
        elif not active or not certified:next_step='CERTIFY_ORIGINAL_306_312_313_NATIVE_EXECUTOR_AND_PAIR'
        elif not source_ready:next_step='CERTIFY_AND_PUBLISH_ORIGINAL_WRITE_PROVIDER_AND_ADMISSION'
        else:next_step='VERIFY_OPERATION_SPECIFIC_PLAN_BACKUP_TEST_AND_READBACK'
        result.append({
          'company_code':site['company_code'],'environment':site['environment'],
          'identity_registered':enrolled,'persistent_grant_present':grant,
          'matched_pending_pair':pending,'matched_active_pair':active,
          'signed_host_proof_verified':proof_ready,
          'native_312_313_certified':certified,
          'source_runtime_eligible':source_ready,
          'native_command_eligible':admissible,
          'content_write_eligible':admissible and source_ready,
          'production_apply_eligible':False,
          'next_original_transition':next_step,
          'automated_observation':True,
          'original_native_job_executed_by_monitor':False,
          'owner_grant_issued_by_monitor':False,
          'tenant_mutation':False
        })
    return {'contract':CONTRACT,'company_count':len({r['company_code'] for r in result}),
      'target_count':len(result),'targets':result,'production_mutated':False,
      'grants_issued':False,'native_jobs_dispatched':False}

def from_live():
    sites=run([sys.executable,str(ROOT/'bin/company-native-publication-plan.py'),'--all-enrolled'],155)
    channels=run([sys.executable,str(ROOT/'bin/company-original-core-channel-readback.py')],85)
    known={r['company']+':'+r['environment'] for r in channels.get('targets',[])}
    proofs={}
    for ch in channels['targets']:
        key=ch['company']+':'+ch['environment']
        if (ch.get('original_persistent_full_wpcli_grants',0)>0
            and ch.get('original_matched_pending_transport_grant_pairs',0)+ch.get('original_matched_active_transport_grant_pairs',0)>0):
            # Read-only signed receipt: no retries, attestation or privilege escalation.
            status=run(WP+['mad4b','company-native-host-proof','status',
                '--company='+ch['company'],'--environment='+ch['environment'],*BASE],45)
            # A missing signed host proof is a valid fail-closed state. Original
            # Core DENIED responses intentionally omit company/environment;
            # never confuse that with a forged verified proof or abort every
            # other company's read-only readiness observation.
            if (status.get('contract')=='mad4b.company-native-host-proof.v1'
                and status.get('state')=='DENIED'
                and status.get('verified') is False
                and status.get('reason') in ('HOST_PROOF_NOT_FOUND',
                    'HOST_PROOF_ORIGINAL_PAIR_NO_LONGER_ELIGIBLE')):
                proofs[key]={'state':'DENIED','verified':False,
                    'company':ch['company'],'environment':ch['environment']}
            elif (status.get('company')==ch['company']
                  and status.get('environment')==ch['environment']
                  and status.get('contract')=='mad4b.company-native-host-proof.v1'):
                proofs[key]=status
            else:
                stop('HOST_PROOF_READBACK_SCOPE_MISMATCH')
    if not set(proofs)<=known:stop('EXTRANEOUS_HOST_PROOF')
    state=combine(sites,channels,proofs)
    state['observed_utc']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    REPORT.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(dir=REPORT.parent,prefix='.universal-readiness-')
    with os.fdopen(fd,'w') as f:json.dump(state,f,indent=2);f.write('\n')
    os.chmod(tmp,0o640);os.replace(tmp,REPORT)
    return state

def selftest():
    def ch(c,e,identity=True,grants=0,pending=0,active=0):
        return {'company':c,'environment':e,'site_identity_registered':identity,
          'original_persistent_full_wpcli_grants':grants,
          'original_matched_pending_transport_grant_pairs':pending,
          'original_matched_active_transport_grant_pairs':active}
    def site(c,e,eligible=False):
        return {'company_code':c,'environment':e,'original_core_native_write_eligible':eligible,
          'runtime_integration_gaps':[] if eligible else ['SOURCE_RUNTIME_NOT_READY']}
    site_data={'contract':'mad4b.company-native-publication-all-enrolled.v1',
      'targets':[site('a-company','production'),site('b-company','staging'),site('c-company','production'),
       site('d-company','staging'),site('e-company','production',True)],'target_count':5,'production_mutated':False}
    ch_data={'contract':'mad4b.original-core-channel-aggregate.v1','targets':[
      ch('a-company','production',False),ch('b-company','staging'),
      ch('c-company','production',True,1,1),ch('d-company','staging',True,1,0,1),
      ch('e-company','production',True,1,0,1)],'target_count':5,
      'production_mutated':False,'transport_or_grant_created':False}
    proofs={'c-company:production':{'verified':True,'state':'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'},
      'd-company:staging':{'verified':True,'state':'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'},
      'e-company:production':{'verified':True,'state':'HOST_PROOF_VERIFIED_NATIVE_EXECUTOR_PENDING'}}
    tests=0
    def ok(cond):
        nonlocal tests
        assert cond;tests+=1
    x=combine(site_data,ch_data,proofs)
    z={t['company_code']:t for t in x['targets']}
    ok(x['target_count']==5 and x['company_count']==5)
    ok(z['a-company']['next_original_transition']=='RECONCILE_ORIGINAL_COMPANY_SITE_IDENTITY')
    ok(z['b-company']['next_original_transition']=='ENROLL_EXACT_OWNER_ORIGINAL_305_PERSISTENT_WPCLI_FULL')
    ok(z['c-company']['matched_pending_pair'] and not z['c-company']['native_command_eligible'])
    ok(not z['d-company']['native_command_eligible']) # active transport with no 312/313 receipt
    ok(z['e-company']['source_runtime_eligible'] and not z['e-company']['native_command_eligible'])
    ok(all(not r['production_apply_eligible'] for r in x['targets']))
    ok(all(not r['tenant_mutation'] for r in x['targets']))
    try:combine(site_data,{**ch_data,'targets':ch_data['targets'][:-1],'target_count':4},proofs)
    except RuntimeError as e:ok('SET_DRIFT' in str(e))
    else:raise AssertionError('MISSING_COMPANY_NOT_REJECTED')
    try:combine(site_data,ch_data,{'outside-company:production':{'verified':True}},None)
    except RuntimeError:ok(True)
    else:raise AssertionError('FOREIGN_PROOF_ACCEPTED')
    try:combine(site_data,ch_data,proofs,{'e-company:production':{
     'native_executor_certified':True,'native_312_readback_verified':True,'native_313_readback_verified':True}})
    except RuntimeError:raise
    else:ok(True)
    ok(not z['c-company']['content_write_eligible'])
    return {'state':'SELFTEST_PASS','tests':tests,'target_fixtures':5,
      'production_mutated':False,'grants_created':False,'jobs_dispatched':False}

def main():
    p=argparse.ArgumentParser();p.add_argument('action',choices=['selftest','all-enrolled'])
    a=p.parse_args()
    state=selftest() if a.action=='selftest' else from_live()
    print(json.dumps(state if a.action=='selftest' else {
      'state':'ALL_ENROLLED_NATIVE_READINESS_OBSERVED',
      'companies':state['company_count'],'targets':state['target_count'],
      'actions':{r['company_code']+':'+r['environment']:r['next_original_transition']
         for r in state['targets']},
      'active_executors':sum(r['native_command_eligible'] for r in state['targets']),
      'production_mutated':False,
      'report':str(REPORT)},ensure_ascii=False))
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,RuntimeError,AssertionError,subprocess.SubprocessError) as e:
        print('UNIVERSAL_NATIVE_READINESS_FAIL_CLOSED:'+str(e)[:270],file=sys.stderr)
        sys.exit(2)
