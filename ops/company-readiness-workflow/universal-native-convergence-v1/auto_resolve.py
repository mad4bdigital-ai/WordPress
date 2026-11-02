#!/usr/bin/env python3
"""Auto-converge missing Cross-SSH *binding catalog* entries from original authority.

Site identity, SSH pin, host scope and central signed trust must ALREADY exist.
Never mints grants, fabricates site UUIDs, changes WordPress site state or replays
jobs. Dispatches only idempotent read-only siteurl canaries as final evidence.
"""
import hashlib,json,os,pathlib,stat,tempfile,datetime
import router
from pathlib import Path

ROOT=Path('/opt/mad4b-company-transport')
HERE=ROOT/'unified-execution-router-v1'
REGISTRY=ROOT/'dynamic-site-binding-v1/registry.json'
SOURCE=ROOT/'dynamic-site-binding-v1/resolver.py'
CONTRACT='mad4b.auto-resolve-crossssh-binding.v1'

def save(path,data):
    content=json.dumps(data,sort_keys=True,indent=2)+'\n'
    fd,temp=tempfile.mkstemp(prefix='.auto-resolve-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600)
            f.write(content);f.flush();os.fsync(f.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def reconcile(allow_write=True):
    if os.geteuid()!=0:raise RuntimeError('ROOT_REQUIRED')
    import dynamic_binding_guard as guard
    resolver=guard.RESOLVER
    policy=router.load()
    current=resolver.registry()
    sites=resolver.central_sites()
    operator=router.secure_json(router.OPERATOR)
    native=router.registry_module()
    pending=[]
    registered=[]
    declined=[]
    for target,scope in sorted(policy['targets'].items()):
        if not isinstance(scope.get('providers',{}).get('cross_ssh_wpcli'),dict):
            continue
        company,env=target.rsplit(':',1)
        orig=operator['scopes'].get(target)
        provider=scope['providers']['cross_ssh_wpcli']
        binding=provider['registered_target']
        record=native.TARGETS.get(binding)
        site=sites.get((company,'primary',env))
        existing=[x for x in current['site_bindings']
                  if (x['company_id'],x['site_id'],x['environment'])==(company,'primary',env)
                  and x['provider']=='registry_ssh_wpcli']
        if existing:
            if len(existing)!=1 or any(x[k]!=v for x in existing for k,v in
                   [('site_uuid',scope['site_uuid']),('canonical_origin',scope['canonical_origin']),
                    ('provider_locator',binding),('wp_root',provider['expected_root'])]):
                declined.append({'target':target,'reason':'CONFLICTING_EXISTING_BINDING'})
            continue
        if (not isinstance(orig,dict) or orig.get('allow_remote_shell') is not True
            or orig.get('allow_operator') is not True
            or not site or site.get('identity_verified') is not True
            or site.get('site_uuid')!=scope['site_uuid']
            or site.get('canonical_origin')!=scope['canonical_origin']
            or not isinstance(record,dict) or record.get('root')!=provider['expected_root']
            or record.get('site_uuid')!=scope['site_uuid']):
            declined.append({'target':target,'reason':'ORIGINAL_SITE_OR_HOST_AUTHORITY_MISSING'})
            continue
        if router.cross_preflight(target,scope,provider)!='READY':
            declined.append({'target':target,'reason':'LIVE_CROSSSSH_EXACT_SITE_IDENTITY_NOT_VERIFIED'})
            continue
        host=binding.split(':')[0]
        prior=current['host_registry'].get(host)
        module=str(ROOT/'registry-remote-v1/registry_remote.py')
        if prior and prior.get('registry_module') not in (None,module):
            declined.append({'target':target,'reason':'HOST_MODULE_CONFLICT'})
            continue
        if prior is None:
            current['host_registry'][host]={'kind':'ssh_host','host_status':'enrolled',
              'identity_source':'pinned_registered_crossssh','registry_module':module,
              'description':'Original SSH host scope readback, not site grant'}
        elif 'registry_module' not in prior:
            prior['registry_module']=module
        tag=hashlib.sha256(target.encode()).hexdigest()[:14]
        new={'binding_id':'auto-'+tag,'company_id':company,'site_id':'primary',
          'environment':env,'site_uuid':scope['site_uuid'],
          'canonical_origin':scope['canonical_origin'],'host_id':host,
          'provider':'registry_ssh_wpcli','provider_locator':binding,
          'wp_root':provider['expected_root'],'registration_status':'enrolled',
          'scope_evidence':'original_signed_v4_plus_host_scope_readback',
          'grant_authority':'original_operation_grant'}
        current['site_bindings'].append(new)
        registered.append({'target':target,'binding_id':new['binding_id']})
    canaries=[]
    if registered and allow_write:
        prior=router.secure_json(REGISTRY)
        try:
            save(REGISTRY,current)
            new_status=resolver.reconcile()
            resolver.write_status(new_status)
            for item in registered:
                rec=new_status['bindings'].get(item['binding_id'],{})
                if rec.get('state')!='IDENTITY_AND_HOST_READBACK_VERIFIED' or rec.get('readback_verified') is not True:
                    raise RuntimeError('INDEPENDENT_RECONCILIATION_FAILED:'+item['target'])
        except Exception:
            save(REGISTRY,prior)
            resolver.write_status(resolver.reconcile())
            raise
        # Auto-dispatch ONLY an original-host-scoped read-only canary.
        # The deterministic operation ID and journaling prevent replay.
        # Write jobs remain owned by the separate original 305/306/312 queue.
        for item in registered:
            opid='auto-binding-'+item['binding_id'][5:]
            receipt=HERE/'journal'/(opid+'.json')
            if receipt.exists():
                canaries.append({'target':item['target'],
                                 'state':'EXISTING_RECEIPT_NO_REPLAY'})
                continue
            try:
                response=router.run(item['target'],opid,operation='wordpress.siteurl.read')
                canaries.append({'target':item['target'],'state':response.get('state'),
                     'readback_verified':response.get('readback_verified') is True})
            except (OSError,RuntimeError,ValueError,KeyError) as exc:
                canaries.append({'target':item['target'],
                     'state':'RECONCILIATION_REQUIRED_NO_REPLAY',
                     'reason':type(exc).__name__})
    report={'contract':CONTRACT,'observed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
      'registered':registered if allow_write else [],
      'candidates':registered if not allow_write else [],
      'declined':declined,'read_canary_dispatches':canaries,
      'grant_issued':False,'production_site_mutated':False,
      'write_jobs_submitted_by_resolver':False}
    return report

def main():
    import argparse
    a=argparse.ArgumentParser()
    a.add_argument('action',choices=('plan','apply'))
    opt=a.parse_args()
    result=reconcile(allow_write=opt.action=='apply')
    if opt.action=='apply':
        save(HERE/'auto-resolve-status.json',result)
    print(json.dumps(result,sort_keys=True))
if __name__=='__main__':main()
