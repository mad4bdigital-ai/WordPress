#!/usr/bin/env python3
"""MAD4B dynamic Company->Site->Environment->Host/Provider reconciliation.
Does not issue grants, mutate targets, or upgrade authority from host discovery.
"""
import argparse, datetime as dt, hashlib, importlib.util, json, os, re, stat, subprocess, tempfile
from pathlib import Path

BASE=Path('/opt/mad4b-company-transport/dynamic-site-binding-v1')
REGISTRY=BASE/'registry.json'
STATUS=BASE/'status.json'
CONTRACT='mad4b.dynamic-infrastructure-binding.v1'
OPS={'identity.read','wordpress.siteurl.read','wpcli.general','wordpress.content.write'}
LOCAL=['/usr/bin/docker','exec','-u','www-data','php-wkgtkzta35wkkgouwt6ruljc',
       '/usr/local/bin/wp','--path=/var/www/html','--skip-themes']
COMPANY=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=re.compile(r'^[a-z][a-z0-9_-]{1,39}$')

def safe_json(path):
    st=path.lstat()
    if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o022:
        raise ValueError('ROOT_OWNED_REGISTRY_REQUIRED')
    return json.loads(path.read_text())

def canonical(value):
    return json.dumps(value,sort_keys=True,separators=(',',':'),ensure_ascii=False)

def sha(value):
    return hashlib.sha256(canonical(value).encode()).hexdigest()

def registry():
    d=safe_json(REGISTRY)
    if d.get('contract')!='mad4b.dynamic-infrastructure-site-binding.registry.v1':
        raise ValueError('REGISTRY_CONTRACT_MISMATCH')
    hosts=d.get('host_registry')
    bindings=d.get('site_bindings')
    if not isinstance(hosts,dict) or not isinstance(bindings,list):
        raise ValueError('INVALID_REGISTRY')
    seen=set()
    for b in bindings:
        if not isinstance(b,dict) or not COMPANY.fullmatch(str(b.get('company_id',''))) or not ENV.fullmatch(str(b.get('environment',''))):
            raise ValueError('INVALID_COMPANY_ENVIRONMENT')
        key=b.get('binding_id')
        if not isinstance(key,str) or key in seen:raise ValueError('DUPLICATE_BINDING_ID')
        seen.add(key)
        if b.get('host_id') not in hosts:raise ValueError('UNREGISTERED_HOST_REFERENCE')
    return d

def central_sites():
    php='$a=MAD4B_Company_Environment_Registry_V2::discover();$o=[];foreach($a as $company=>$record){foreach($record["environments"] as $environment=>$x){$o[]=["company_id"=>$company,"site_id"=>"primary","environment"=>$environment,"canonical_origin"=>$x["canonical_origin"]??null,"site_uuid"=>$x["site_uuid"]??null,"identity_verified"=>$x["identity_evidence"]["signed_v4_centrally_verified"]??false,"write_authorized"=>$x["write_authorized"]??false];}}echo wp_json_encode($o,JSON_UNESCAPED_SLASHES);'
    p=subprocess.run(LOCAL+['eval',php],capture_output=True,text=True,timeout=38,check=False)
    if p.returncode:raise RuntimeError('CENTRAL_COMPANY_REGISTRY_UNAVAILABLE')
    rows=json.loads(p.stdout.strip().splitlines()[-1])
    if not isinstance(rows,list):raise RuntimeError('CENTRAL_COMPANY_REGISTRY_SHAPE_INVALID')
    result={}
    for row in rows:
        k=(row.get('company_id'),row.get('site_id','primary'),row.get('environment'))
        if k in result:raise RuntimeError('AMBIGUOUS_CENTRAL_COMPANY_ENVIRONMENT')
        result[k]=row
    return result

def remote_module(path):
    spec=importlib.util.spec_from_file_location('mad4b_dynamic_provider',path)
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module

def provider_read(b,hosts):
    kind=b['provider']
    if kind=='docker_wpcli':
        if b['host_id']!='srv2030908' or b['wp_root']!='/var/www/html':
            return 'UNTRUSTED_LOCAL_HOST',None
        name=b['provider_locator']
        central_container=(name=='php-wkgtkzta35wkkgouwt6ruljc'
                           and b['company_id']=='tours-central'
                           and b['environment']=='staging')
        if not central_container:
            # A registered Coolify service PHP container is a read route only:
            # Docker project and service labels must match its exact locator.
            # This never enrolls a site, creates an Original grant, or writes.
            m=re.fullmatch(r'php-([a-z0-9]{20,40})',str(name))
            if not m or b.get('registration_status')!='observed_not_enrolled':
                return 'UNENROLLED_LOCAL_CONTAINER',None
            labels=subprocess.run(['/usr/bin/docker','inspect','--format',
                  '{{index .Config.Labels "com.docker.compose.project"}} '
                  '{{index .Config.Labels "com.docker.compose.service"}} '
                  '{{.State.Status}}',name],
                  capture_output=True,text=True,timeout=10)
            if labels.returncode!=0 or labels.stdout.strip()!=m.group(1)+' php running':
                return 'UNTRUSTED_COOLIFY_CONTAINER_SCOPE',None
        args=['/usr/bin/docker','exec','-u','www-data',name,
              '/usr/local/bin/wp','--path=/var/www/html','--skip-plugins',
              '--skip-themes','option','get','siteurl']
        p=subprocess.run(args,capture_output=True,text=True,timeout=28)
        if p.returncode!=0:
            return 'PROVIDER_UNAVAILABLE',None
        origin=p.stdout.strip()
        if not central_container:
            profile=subprocess.run(args[:-3]+['option','get',
                  'mad4b_scp_site_profile_v2','--format=json'],
                  capture_output=True,text=True,timeout=28)
            if profile.returncode!=0:
                return 'SITE_PROFILE_UNAVAILABLE',None
            try:
                data=json.loads(profile.stdout)
            except (TypeError,ValueError):
                return 'SITE_PROFILE_MALFORMED',None
            if (not isinstance(data,dict) or
                data.get('site_uuid')!=b.get('site_uuid') or
                data.get('environment')!=b.get('environment') or
                data.get('canonical_origin')!=b.get('canonical_origin') or
                origin!=b.get('canonical_origin')):
                return 'SITE_PROFILE_SCOPE_MISMATCH',None
        return 'OBSERVED',origin
    if kind=='registry_ssh_wpcli':
        h=hosts[b['host_id']]
        mod=remote_module(h['registry_module'])
        route=b['provider_locator']
        item=mod.TARGETS.get(route)
        if not item or item.get('root')!=b['wp_root'] or item.get('site_uuid')!=b['site_uuid']:
            return 'SSH_HOST_OR_SITE_SCOPE_DRIFT',None
        mod.guard(route)
        origin=mod.remote(route,'/usr/local/bin/wp --path='+__import__('shlex').quote(b['wp_root'])+' --skip-plugins --skip-themes option get siteurl')
        return 'OBSERVED',origin.strip()
    # API, MCP and Remote Native are valid candidate types, but have no
    # executable route without an independently enrolled verified provider.
    if kind in ('rest_api','mcp','remote_native'):
        return 'PROVIDER_ENROLLMENT_REQUIRED',None
    return 'UNKNOWN_PROVIDER',None

def actual_host_anchor(b,hosts):
    # Cryptographic SSH pin is evidence of host connection, not site authorization.
    anchor={'host_id':b['host_id'],'provider':b['provider']}
    if b['provider']=='registry_ssh_wpcli':
        mod=remote_module(hosts[b['host_id']]['registry_module'])
        target=mod.TARGETS.get(b['provider_locator'])
        if not target:raise ValueError('REMOTE_HOST_NOT_REGISTERED')
        _,_,_,known_hosts=mod.ssh_base(b['provider_locator'])
        anchor.update({'endpoint':target.get('host'),'port':target.get('port'),
            'principal':target.get('user'),'known_hosts_sha256':hashlib.sha256(known_hosts.read_bytes()).hexdigest()})
    elif b['provider']=='docker_wpcli':
        anchor['host_machine']='srv2030908'
        anchor['container']=b['provider_locator']
    else:
        anchor['host_identity_state']='PROVIDER_ATTESTATION_NOT_REGISTERED'
    return anchor

def identity_key(b):return (b['company_id'],b['site_id'],b['environment'])

def resolve(b,hosts,discovery,prior=None,probe=provider_read):
    row={'binding_id':b['binding_id'],'company_id':b['company_id'],
      'site_id':b['site_id'],'environment':b['environment'],
      'site_uuid':b.get('site_uuid'),'canonical_origin':b['canonical_origin'],
      'host_id':b['host_id'],'provider':b['provider'],
      'provider_locator':b['provider_locator'],'execution_authorized':False,
      'write_authorized':False,'fallback_scope': 'SAME_SITE_UUID_AND_ENVIRONMENT_PRE_DISPATCH_ONLY'}
    anchor={k:b.get(k) for k in ('company_id','site_id','environment','site_uuid',
      'canonical_origin','host_id','provider','provider_locator','wp_root')}
    try:
        anchor['actual_host']=actual_host_anchor(b,hosts)
    except (OSError,ValueError,RuntimeError,KeyError):
        row['state']='HOST_IDENTITY_ATTESTATION_MISSING'
        return row
    digest=sha(anchor);row['binding_fingerprint']=digest
    if prior and prior.get('binding_fingerprint')!=digest:
        # One-time schema upgrade: legacy digest omitted the physical host pin.
        legacy=sha({k:b.get(k) for k in ('company_id','site_id','environment','site_uuid',
            'canonical_origin','host_id','provider','provider_locator','wp_root')})
        legacy_schema=(prior.get('binding_fingerprint')==legacy and
            prior.get('state') in ('IDENTITY_AND_HOST_READBACK_VERIFIED','BINDING_NOT_ENROLLED','HOST_OBSERVED_IDENTITY_AND_AUTHORITY_PENDING'))
        if not legacy_schema:
            row['state']='REBIND_REATTESTATION_REQUIRED'
            return row
        row['host_fingerprint_upgrade_requires_fresh_readback']=True
    if b.get('registration_status')!='enrolled':
        row['state']='BINDING_NOT_ENROLLED'
        # A read-only probe can confirm a candidate's site address, not a Grant.
        if b.get('registration_status')=='observed_not_enrolled' and b['provider'] in ('docker_wpcli','registry_ssh_wpcli'):
            try:
                observed,origin=probe(b,hosts)
                row['provider_probe']=observed
                if observed=='OBSERVED' and origin==b['canonical_origin']:
                    row['state']='HOST_OBSERVED_IDENTITY_AND_AUTHORITY_PENDING'
                    row['readback_verified']=True
            except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired):
                pass
        return row
    if b['company_id']=='tours-central' and b['site_id']=='control-plane':
        source={'site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
          'canonical_origin':'https://tours.dev.mad4b.com',
          'identity_verified':True,'environment':'staging'}
    else:
        source=discovery.get((b['company_id'],b['site_id'],b['environment']))
    if not source:
        row['state']='SITE_NOT_IN_CANONICAL_REGISTRY';return row
    if (source.get('site_uuid')!=b['site_uuid'] or
        source.get('canonical_origin')!=b['canonical_origin']):
        row['state']='CANONICAL_SITE_IDENTITY_CHANGED';return row
    if not source.get('identity_verified'):
        # Signed proof OR explicitly pinned central self site; never an inferred host.
        row['state']='SIGNED_SITE_PROOF_REQUIRED';return row
    try:state,origin=probe(b,hosts)
    except (OSError,ValueError,RuntimeError,subprocess.TimeoutExpired):
        state,origin='PROVIDER_UNAVAILABLE',None
    row['provider_probe']=state
    if state!='OBSERVED':
        row['state']=state;return row
    if origin!=b['canonical_origin']:
        row['state']='SITE_ORIGIN_READBACK_MISMATCH';return row
    row['state']='IDENTITY_AND_HOST_READBACK_VERIFIED'
    row['readback_verified']=True
    row['execution_authorized']=False # separate original grant admission
    row['next_action']='EXACT_OPERATION_GRANT_REQUIRED'
    return row

def reconcile(source=None,reg=None,prior=None,probe=provider_read):
    conf=reg or registry()
    source=central_sites() if source is None else source
    source=dict(source)
    # Additional company sites remain separate identities, never inherited
    # from a company's primary WordPress site or the host connection.
    for candidate in conf.get('additional_sites',[]):
        if not isinstance(candidate,dict):raise ValueError('ADDITIONAL_SITE_SHAPE_INVALID')
        key=(candidate.get('company_id'),candidate.get('site_id'),candidate.get('environment'))
        if key in source:raise ValueError('DUPLICATE_CANONICAL_SITE_IDENTITY')
        if not COMPANY.fullmatch(str(key[0])) or not ENV.fullmatch(str(key[2])) or not key[1]:
            raise ValueError('ADDITIONAL_SITE_IDENTITY_INVALID')
        source[key]={'company_id':key[0],'site_id':key[1],
            'environment':key[2],'site_uuid':candidate.get('site_uuid'),
            'canonical_origin':candidate.get('canonical_origin'),
            'identity_verified':False,'write_authorized':False,
            'next_action':'INDEPENDENT_SIGNED_SITE_PROOF_REQUIRED'}
    old=prior
    if old is None:
        try:old=safe_json(STATUS)['bindings']
        except (OSError,KeyError,ValueError,TypeError):old={}
    if not isinstance(old,dict):old={}
    rows={};seen={}
    for b in conf['site_bindings']:
        key=identity_key(b)
        # Multiple providers can serve one site; conflicting origin/UUID cannot.
        scope=(b.get('site_uuid'),b.get('canonical_origin'))
        if key in seen and seen[key]!=scope:
            rows[b['binding_id']]={'state':'SITE_BINDING_CONFLICT','execution_authorized':False}
            continue
        seen[key]=scope
        rows[b['binding_id']]=resolve(b,conf['host_registry'],source,old.get(b['binding_id']),probe)
    targets=[]
    for (company,site_id,environment),s in sorted(source.items()):
        related=[x for x in rows.values() if x.get('company_id')==company and x.get('site_id')==site_id and x.get('environment')==environment]
        targets.append({'company_id':company,'site_id':site_id,'environment':environment,
          'canonical_origin':s.get('canonical_origin'),'site_uuid':s.get('site_uuid'),
          'site_registry_identity_verified':bool(s.get('identity_verified')),
          'binding_ids':[x.get('binding_id') for x in related],
          'state':'BINDING_REGISTERED' if related else 'HOST_OR_PROVIDER_ENROLLMENT_REQUIRED'})
    return {'contract':CONTRACT,'generated_at':dt.datetime.now(dt.timezone.utc).isoformat(),
      'host_count':len(conf['host_registry']),'company_target_count':len(source),
      'binding_count':len(rows),'targets':targets,'bindings':rows,
      'grants_issued':0,'remote_operations_executed':0,
      'production_mutation':False,'host_moves_auto_authorized':False}

def write_status(data):
    fd,tmp=tempfile.mkstemp(prefix='.dynamic-',dir=BASE)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(data,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,STATUS)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def plan(target,operation,site_id=''):
    d=reconcile()
    if operation not in OPS:return {'state':'OPERATION_NOT_REGISTERED','execution_authorized':False}
    rows=[x for x in d['bindings'].values() if
          (x.get('company_id')+':'+x.get('environment') if x.get('company_id') and x.get('environment') else None)==target]
    if not site_id and len(set(x.get('site_id') for x in rows))>1:
        return {'state':'SITE_ID_REQUIRED_AMBIGUOUS_COMPANY_ENVIRONMENT','execution_authorized':False}
    if site_id:rows=[x for x in rows if x.get('site_id')==site_id]
    eligible=[x for x in rows if x.get('state')=='IDENTITY_AND_HOST_READBACK_VERIFIED']
    state='CENTRAL_GRANT_REQUIRED' if eligible else 'NO_ENROLLED_VERIFIED_BINDING'
    return {'contract':CONTRACT,'target':target,'site_id':site_id or (rows[0]['site_id'] if rows else None),'operation':operation,'state':state,
      'eligible_readback_verified_providers':[x['provider'] for x in eligible],
      'execution_authorized':False,'production_mutation':False,
      'fallback_requires_same_exact_site_scope':True}

def cli():
    ap=argparse.ArgumentParser()
    ap.add_argument('action',choices=['status','reconcile','plan'])
    ap.add_argument('--target',default='')
    ap.add_argument('--site-id',default='')
    ap.add_argument('--operation',default='wordpress.siteurl.read')
    a=ap.parse_args()
    if os.geteuid()!=0:raise SystemExit('ROOT_ENROLLED_HOST_REQUIRED')
    if a.action=='plan':out=plan(a.target,a.operation,a.site_id)
    elif a.action=='reconcile':
        out=reconcile();write_status(out)
    else:out=safe_json(STATUS) if STATUS.exists() else reconcile()
    print(json.dumps(out,sort_keys=True))
if __name__=='__main__':cli()
