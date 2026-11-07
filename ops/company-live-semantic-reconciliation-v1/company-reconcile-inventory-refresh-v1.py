#!/usr/bin/env python3
"""Refresh passive Company Reconcile file inventories from enrolled bindings.
Does not request execution, create Jobs/Grants, or change tenant files.
"""
import argparse,datetime,importlib.util,json,os,pathlib,subprocess,sys
BASE=pathlib.Path('/opt/mad4b-company-provisioning')
BINDING_POLICY=BASE/'policies/company-fx-distribution-v1.json'
REGISTRY_ADAPTER=BASE/'bin/company-mode-price-policy-reconcile.py'
PUBLISHER=BASE/'bin/company-reconcile-publish-inventory-v1.py'
REPORT=BASE/'reports/company-reconcile-inventory-refresh-current.json'
TARGET_READBACK=BASE/'bin/company-plugin-target-readback-publish.py'
PACKAGE_PREFLIGHT=BASE/'bin/company-plugin-package-preflight.py'
PACKAGE_BUILDER=BASE/'bin/company-experience-portable-source-bundle.py'
LIVE_SEMANTIC=BASE/'bin/company-live-semantic-discovery-v1.py'

def enrolled_targets():
    p=json.loads(BINDING_POLICY.read_text())
    if p.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('TRUSTED_SITE_BINDING_CONTRACT_MISMATCH')
    spec=importlib.util.spec_from_file_location('mad4b_enrolled_binding_reader',REGISTRY_ADAPTER)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    targets=module.gather_bindings(p)
    if len(targets)>40:raise RuntimeError('UNEXPECTED_ENROLLED_TARGET_COUNT')
    return sorted({(x['company_code'],x['environment']) for x in targets})

def main():
    a=argparse.ArgumentParser()
    a.add_argument('--list',action='store_true')
    a.add_argument('--company',default='')
    a.add_argument('--environment',default='')
    args=a.parse_args()
    pairs=enrolled_targets()
    if args.company or args.environment:
        if (args.company,args.environment) not in pairs:raise RuntimeError('TARGET_NOT_ENROLLED')
        pairs=[(args.company,args.environment)]
    if args.list:
        print(json.dumps({'state':'ENROLLED_SOURCE_INVENTORY_TARGETS','targets':[{'company':c,'environment':e} for c,e in pairs],'source_only':True}))
        return
    results=[]
    for company,env in pairs:
        result=subprocess.run([sys.executable,str(PUBLISHER),'--company',company,'--environment',env],text=True,capture_output=True,timeout=115,check=False)
        if result.returncode==0:
            try:
                value=json.loads(result.stdout.strip().splitlines()[-1]);state=value['snapshot']['state']
                count=value['snapshot']['different']
            except (ValueError,KeyError,IndexError,TypeError):state='INVALID_PUBLISHER_REPLY';count=None
        else:
            state=('DEFERRED_STAGING_WPCLI_BUSY'
                if 'STAGING_WPCLI_BUSY' in (result.stdout+result.stderr)
                else 'READONLY_INVENTORY_REFRESH_FAILED')
            count=None
        results.append({'company':company,'environment':env,'state':state,'different_files':count,
                        'publisher_exit':result.returncode})
    # Exact target adapters are observations, not native execution providers.
    # Reuse the existing reconcile timer, avoiding a second host scheduling loop.
    # This adapter currently covers Wayfatours Production only.
    plugin_results=[]
    if ('wayfatours','production') in pairs and TARGET_READBACK.is_file():
        try:
            observed=subprocess.run([sys.executable,str(TARGET_READBACK)],
                capture_output=True,text=True,timeout=85,check=False)
            data=json.loads(observed.stdout.strip().splitlines()[-1]) if observed.returncode==0 else {}
            correct=(data.get('state')=='SCOPED_TARGET_READBACK_PUBLISHED'
                and data.get('tenant_mutated') is False
                and data.get('original_313_certified') is False)
            plugin_results.append({
                'company':'wayfatours','environment':'production',
                'state':'LIVE_WPCLI_INVENTORY_PUBLISHED' if correct else 'READBACK_DEFERRED',
                'install_missing_count':data.get('missing') if correct else None,
                'activation_needed_count':data.get('inactive') if correct else None,
                'plan_hash':data.get('plan_hash') if correct else None,
                'signed_313_produced':False,'target_mutated':False})
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError):
            plugin_results.append({'company':'wayfatours','environment':'production',
                'state':'READBACK_DEFERRED','signed_313_produced':False,
                'target_mutated':False})
    package_results=[]
    if ('wayfatours','production') in pairs and PACKAGE_PREFLIGHT.is_file():
        try:
            if not PACKAGE_BUILDER.is_file():
                raise RuntimeError('SOURCE_PACKAGE_BUILDER_NOT_FOUND')
            built=subprocess.run([sys.executable,str(PACKAGE_BUILDER)],
                text=True,capture_output=True,timeout=85,check=False)
            if built.returncode!=0:
                raise RuntimeError('SOURCE_PACKAGE_BUILD_DEFERRED')
            package=subprocess.run([sys.executable,str(PACKAGE_PREFLIGHT)],
                text=True,capture_output=True,timeout=85,check=False)
            value=json.loads(package.stdout.strip().splitlines()[-1])
            ok=(package.returncode==0
                and value.get('state')=='SOURCE_ARCHIVE_AND_LIVE_TREE_VERIFIED'
                and value.get('target_install_authorized') is False
                and value.get('original_313_issued') is False
                and value.get('production_mutated') is False)
            package_results.append({'company':'wayfatours','environment':'production',
                'state':'SOURCE_ARCHIVE_VERIFIED' if ok else 'PACKAGE_PREFLIGHT_HOLD',
                'plan_hash':value.get('plan_hash') if ok else None,
                'archive_sha256':value.get('archive_sha256') if ok else None,
                'verified_files':value.get('archive_files') if ok else None,
                'native_worker_alignment':value.get('native_worker_alignment_state') if ok else None,
                'tenant_modified':False,'native_job_issued':False,'signed_313_issued':False})
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError,RuntimeError):
            package_results.append({'company':'wayfatours','environment':'production',
                'state':'PACKAGE_PREFLIGHT_DEFERRED','tenant_modified':False,
                'native_job_issued':False,'signed_313_issued':False})
    # Observations run inside the already scheduled read-only Company Reconcile
    # service. This is not a second timer and never triggers target apply.
    semantic={'state':'NOT_ENROLLED_OR_NOT_CONFIGURED','read_only':True,
              'writes_to_tenant':False,'ready_for_auto_apply':False}
    if ('wayfatours','production') in pairs and LIVE_SEMANTIC.is_file():
        try:
            result=subprocess.run([sys.executable,str(LIVE_SEMANTIC)],
                                  text=True,capture_output=True,timeout=90,check=False)
            data=json.loads(result.stdout.strip().splitlines()[-1]) if result.returncode==0 else {}
            if (result.returncode==0 and
                data.get('state')=='LIVE_SEMANTIC_DISCOVERY_PUBLISHED' and
                data.get('writes_to_tenant') is False):
                semantic={**data,'read_only':True,
                          'ready_for_auto_apply':False}
            else:
                semantic={'state':'LIVE_SEMANTIC_DISCOVERY_DEFERRED',
                          'reason':'WPCLI_OR_READBACK_NOT_READY',
                          'read_only':True,'writes_to_tenant':False,
                          'ready_for_auto_apply':False}
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError):
            semantic={'state':'LIVE_SEMANTIC_DISCOVERY_DEFERRED',
                      'reason':'WPCLI_BUSY_OR_INVALID_JSON','read_only':True,
                      'writes_to_tenant':False,'ready_for_auto_apply':False}
    report={'contract':'mad4b.company-reconcile-inventory-refresh.v1',
       'refreshed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
       'source':'company-fx-distribution enrolled bindings','results':results,
       'plugin_target_readbacks':plugin_results,
       'plugin_package_preflight':package_results,
       'live_semantic_discovery':semantic,
       'writes_to_tenant':False,'creates_jobs':False,'grants_permissions':False}
    tmp=REPORT.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n');tmp.chmod(0o600);os.replace(tmp,REPORT)
    print(json.dumps(report,ensure_ascii=False))
    if any(row['state'] not in ('ADVISORY_INVENTORY_PUBLISHED','DEFERRED_STAGING_WPCLI_BUSY')
           for row in results):sys.exit(2)
    # The report preserves explicit DEFERRED states; service success is NOT a
    # signed receipt or evidence that the entire inventory has converged.

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print(json.dumps({'state':'READONLY_REFRESH_FAILED','reason':str(exc)[:200]}),file=sys.stderr)
        sys.exit(3)
