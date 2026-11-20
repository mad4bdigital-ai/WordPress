#!/usr/bin/env python3
"""Every enrolled company: source-first pricing preview policy reconciliation PLAN.

Read-only observer. No implicit grants, profile writes, Commerce activation,
Woo transactions, tenant Settings writes or production deployment.
"""
from __future__ import annotations
import datetime as dt,json,pathlib,subprocess,sys,tempfile,os
BASE=pathlib.Path('/opt/mad4b-company-provisioning')
SRC='php-wkgtkzta35wkkgouwt6ruljc'
OP=BASE/'bin/company-mode-price-policy-reconcile.py'
CFG=BASE/'policies/company-fx-distribution-v1.json'
BINDINGS=BASE/'policies/company-fx-site-bindings.d'
OUT=BASE/'reports/company-price-preview-reconcile-current.json'
REGISTRY=r'''$rows=MAD4B_Company_Environment_Registry_V2::discover();$out=[];
foreach($rows as $company=>$item)foreach((array)($item['environments']??[]) as $env=>$site)
 if(in_array($env,['staging','production'],true))$out[]=['company'=>$company,'environment'=>$env,
 'site_uuid'=>$site['site_uuid']??null,'origin'=>$site['canonical_origin']??null];
echo wp_json_encode($out);'''

def checked(args,timeout=90):
    p=subprocess.run(args,capture_output=True,text=True,timeout=timeout)
    if p.returncode:raise RuntimeError('RECONCILE_PROBE_FAILED:'+p.stderr[-180:])
    return p.stdout

def registered():
    data=json.loads(checked(['docker','exec','-u','33:33',SRC,'/usr/local/bin/wp',
                            '--path=/var/www/html','--skip-themes','eval',REGISTRY],timeout=75))
    if not isinstance(data,list) or len(data)>500:raise RuntimeError('ORIGINAL_COMPANY_REGISTRY_INVALID')
    ids=[(x['company'],x['environment']) for x in data]
    if len(ids)!=len(set(ids)):raise RuntimeError('DUPLICATE_ORIGINAL_COMPANY_RECORDS')
    return data

def bound():
    doc=json.loads(CFG.read_text())
    if doc.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('BROKER_SITE_POLICY_INVALID')
    rows=list(doc['targets'])
    if BINDINGS.is_dir():
        for f in sorted(BINDINGS.glob('*.json')):
            s=f.lstat()
            if f.is_symlink() or s.st_uid!=0 or s.st_mode & 0o022:raise RuntimeError('UNTRUSTED_BROKER_BINDING')
            x=json.loads(f.read_text())
            if x.get('contract')!='mad4b.company-fx-broker-site-binding.v1':raise RuntimeError('BROKER_MANIFEST_CONTRACT_INVALID')
            rows.append(x)
    out={}
    for x in rows:
        k=(x['company_code'],x['environment'])
        if k in out:raise RuntimeError('DUPLICATE_REGISTERED_SITE_BINDING')
        out[k]={'uuid':x['site_uuid'],'origin':x['origin']}
    return out

def plan(company,env):
    p=subprocess.run([sys.executable,str(OP),'--company',company,
       '--environment',env,'--mode','plan'],capture_output=True,text=True,timeout=160)
    lines=[x for x in p.stdout.splitlines() if x.startswith('{')]
    if p.returncode or len(lines)!=1:raise RuntimeError((p.stderr or p.stdout)[-220:])
    obj=json.loads(lines[0])
    if obj.get('contract')!='mad4b.company-mode-price-policy-reconcile.v1' or obj.get('target_mutated') is not False:
        raise RuntimeError('PRICE_POLICY_OPERATION_CONTRACT_FAILED')
    return obj

def read_price_tag_semantic_evidence():
    """Verified read-only price-tag child; never upgrades policy PLAN authority."""
    f=BASE/'reports/company-live-semantic-reconciliation-current.json'
    if not f.is_file() or f.is_symlink():return None
    try:
        report=json.loads(f.read_text())
        mark=dt.datetime.fromisoformat(report['observed_utc'].replace('Z','+00:00'))
        age=(dt.datetime.now(dt.timezone.utc)-mark).total_seconds()
        price=report.get('comparison',{}).get('price_tag_contract',{})
        if not (0<=age<=1200
                and report.get('contract')=='mad4b.company-live-semantic-reconciliation.v1'
                and report.get('company')=='wayfatours'
                and report.get('environment')=='production'
                and report.get('source_origin')=='https://tours.dev.mad4b.com'
                and report.get('target_origin')=='https://wayfatours.com'
                and report.get('evidence_mode')=='LIVE_RUNTIME'
                and report.get('read_only') is True
                and report.get('writes_to_tenant') is False
                and price.get('contract')=='mad4b.company-elementor-price-tag-runtime-readback.v1'
                and price.get('read_only') is True
                and price.get('can_auto_apply') is False):
            return None
        return {'state':price.get('state','NOT_OBSERVED'),
                'site_uuid':price.get('target',{}).get('site_uuid'),
                'source_module_matches_target':price.get('module_source_target_sha_match') is True,
                'languages_checked':[x.get('language') for x in price.get('frontend',[]) if x.get('all_priced')],
                'price_tag_proof_timestamp':report['observed_utc']}
    except (ValueError,TypeError,KeyError,OSError):
        return None

def read_country_scope_semantic_evidence():
    """Keep country scope distinct from the JetEngine Destination catalog."""
    path=BASE/'reports/company-live-semantic-reconciliation-current.json'
    if not path.is_file() or path.is_symlink():return None
    try:
        report=json.loads(path.read_text())
        age=(dt.datetime.now(dt.timezone.utc)-dt.datetime.fromisoformat(
            report['observed_utc'].replace('Z','+00:00'))).total_seconds()
        value=report.get('comparison',{}).get('country_scope_contract',{})
        if not (0<=age<=1200 and report.get('evidence_mode')=='LIVE_RUNTIME'
           and report.get('contract')=='mad4b.company-live-semantic-reconciliation.v1'
           and report.get('source_origin')=='https://tours.dev.mad4b.com'
           and report.get('target_origin')=='https://wayfatours.com'
           and report.get('company')=='wayfatours' and report.get('environment')=='production'
           and report.get('read_only') is True and report.get('writes_to_tenant') is False
           and value.get('contract')=='mad4b.company-geographic-operating-scope-runtime-readback.v1'
           and value.get('read_only') is True and value.get('can_auto_publish') is False):return None
        return {'state':value.get('state','NOT_OBSERVED'),
                'site_uuid':value.get('target',{}).get('site_uuid'),
                'module_source_target_sha_match':value.get('module_source_target_sha_match') is True,
                'operating_country_ids':value.get('target',{}).get('allowed_country_ids',[]),
                'scope_held_parent_draft_rate_count':value.get('scope_held_published_rate_draft_parent_count',0),
                'scope_held_tour_ids':value.get('source_published_target_draft_scope_held_ids',[]),
                'can_auto_publish':False}
    except (OSError,ValueError,TypeError,KeyError):return None

def emit():
    records=registered();bindings=bound();results=[]
    price_tag=read_price_tag_semantic_evidence()
    country_scope=read_country_scope_semantic_evidence()
    config=json.loads(CFG.read_text())
    source=config['source']
    for r in records:
        company,env=r['company'],r['environment'];key=(company,env)
        row={'company':company,'environment':env,'site_uuid':r.get('site_uuid'),
          'state':'RECONCILE_PROVIDER_BINDING_PENDING','writes_performed':False}
        if (company,env)==('wayfatours','production'):
            match_country=(country_scope is not None and country_scope.get('site_uuid')==r.get('site_uuid'))
            row['company_country_scope_runtime_state']=country_scope['state'] if match_country else 'TARGET_COMPANY_GEO_SCOPE_READBACK_PENDING'
            row['company_country_scope_verified']=bool(match_country and country_scope['state']=='PASS_VERIFIED'
                and country_scope['module_source_target_sha_match'])
            row['operating_country_ids']=country_scope['operating_country_ids'] if match_country else []
            row['scope_held_published_rate_count']=country_scope['scope_held_parent_draft_rate_count'] if match_country else None
            row['scope_held_tour_ids']=country_scope['scope_held_tour_ids'] if match_country else []
            matched=(price_tag is not None and price_tag.get('site_uuid')==r.get('site_uuid'))
            row['price_tag_runtime_state']=price_tag['state'] if matched else 'TARGET_PRICE_TAG_LIVE_READBACK_PENDING'
            row['price_tag_verified']=bool(matched and price_tag['state']=='PASS_VERIFIED'
                and price_tag['source_module_matches_target']
                and set(price_tag['languages_checked'])=={'en','es'})
            row['price_tag_languages_checked']=price_tag['languages_checked'] if matched else []
        else:
            row['price_tag_runtime_state']='TARGET_PRICE_TAG_NATIVE_READBACK_PENDING'
            row['price_tag_verified']=False
            row['company_country_scope_runtime_state']='TARGET_COMPANY_GEO_SCOPE_READBACK_PENDING'
            row['company_country_scope_verified']=False
        if r.get('origin')==source['origin'] and r.get('site_uuid')==source['site_uuid']:
            row['state']='CENTRAL_SOURCE_REFERENCE_NOT_TENANT'
            results.append(row)
            continue
        if key in bindings:
            b=bindings[key]
            if b['uuid']!=r.get('site_uuid') or b['origin']!=r.get('origin'):
                row['state']='COMPANY_BROKER_IDENTITY_MISMATCH'
            else:
                try:
                    p=plan(company,env)
                    row.update({'state':p['state'],'source_template_sha256':p['source_template_sha256'],
                       'mode_rows_on_source':p['source_mode_rows_count'],
                       'target_policy_state':p['target_policy_state'],
                       'required_repair_actions':p['required_repair_actions'],
                       'preview_runtime_available':p['target_frontend_dependencies_present'],
                       'can_write_by_original_core':p['native_execution_authorized'],
                       'ready_for_public_preview':p['ready_for_public_preview']})
                except (ValueError,RuntimeError,subprocess.SubprocessError) as e:
                    row.update({'state':'HOLD_RECONCILE_PLAN_FAILED','reason':str(e)[:200]})
        results.append(row)
    now=dt.datetime.now(dt.timezone.utc).isoformat()
    result={'contract':'mad4b.company-pricing-preview-reconcile-observer.v1',
       'observed_utc':now,'source':'live:tours.dev.mad4b.com',
       'central_registered_count':len(records),'broker_enrolled_count':len(bindings),
       'priced_source_template_scoped':True,
       'targets':results,'mode':'READ_ONLY_PLAN','grants_created':False,
       'production_changed':False,'prices_or_orders_changed':False}
    OUT.parent.mkdir(parents=True,exist_ok=True)
    fd,tmp=tempfile.mkstemp(dir=OUT.parent,prefix='.price-preview-')
    try:
        with os.fdopen(fd,'w') as fp:
            json.dump(result,fp,ensure_ascii=False,indent=2);fp.write('\n');fp.flush();os.fsync(fp.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,OUT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
    print(json.dumps({'contract':result['contract'],'registered_count':len(records),
       'enrolled_count':len(bindings),'states':{x['company']+':'+x['environment']:x['state'] for x in results},
       'production_changed':False},ensure_ascii=False))
if __name__=='__main__':
    try:emit()
    except Exception as e:
        print(json.dumps({'state':'HOLD','reason':str(e)[:190],'production_changed':False}));sys.exit(3)
