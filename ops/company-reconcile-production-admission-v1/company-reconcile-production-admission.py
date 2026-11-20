#!/usr/bin/env python3
"""MAD4B cross-host company-reconcile admission observation, NEVER a writer.

Reads prior original evidence and exact installed provider manifests. Prevents
stale reports/unsigned Host readbacks from becoming Original305/312/313 claims.
"""
from __future__ import annotations
import argparse,datetime as dt,hashlib,importlib.util,json,os,pathlib,re,stat,tempfile
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
EXTERNAL=ROOT/'reports/company-native-external-enrollment-diagnostics-current.json'
INVENTORY=ROOT/'reports/company-reconcile-host-assets-current.json'
NATIVE=ROOT/'reports/company-native-universal-readiness-current.json'
REGISTER=pathlib.Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
REPORT=ROOT/'reports/company-reconcile-production-admission-current.json'
CONTRACT='mad4b.company-reconcile.production-admission.v1'
HEX=re.compile(r'^[a-f0-9]{64}$')
CODE=re.compile(r'^[a-z][a-z0-9-]{2,47}$')
ENV=('production','staging')
MAX_AGE_EXTERNAL=3600*2
MAX_AGE_HOST=3600

def root_doc(path:pathlib.Path,expected:str,limit:int=200_000)->dict:
    if not path.is_absolute() or path.is_symlink():raise RuntimeError('INPUT_NOT_ABSOLUTE_OR_SYMLINK')
    st=path.stat()
    if (not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode & 0o022
        or st.st_size<10 or st.st_size>limit):raise RuntimeError('ROOT_OWNED_READBACK_REQUIRED')
    obj=json.loads(path.read_text())
    if not isinstance(obj,dict) or obj.get('contract')!=expected:raise RuntimeError('INPUT_CONTRACT_INVALID')
    return obj

def parse_timestamp(text:str,now:dt.datetime,max_age:int)->bool:
    if not isinstance(text,str):return False
    try:stamp=dt.datetime.fromisoformat(text.replace('Z','+00:00'))
    except ValueError:return False
    if stamp.tzinfo is None:return False
    delta=(now-stamp).total_seconds()
    return -60<=delta<=max_age

def enrolled_ops(index:dict,read_manifest)->set[str]:
    if index.get('contract')!='mad4b.dynamic-operation-index.v1' or index.get('installed_by_first_party') is not True:
        raise RuntimeError('ORIGINAL_REGISTRY_NOT_FIRST_PARTY')
    items=index.get('providers')
    if not isinstance(items,list) or len(items)>100:raise RuntimeError('ORIGINAL_PROVIDER_INDEX_INVALID')
    result=set()
    for row in items:
        if not isinstance(row,dict) or not HEX.fullmatch(str(row.get('sha256',''))):raise RuntimeError('REGISTRY_DIGEST_MISSING')
        rel=row.get('manifest')
        if not isinstance(rel,str) or not rel.startswith('dynamic-operation-registry-v1/providers/') or '/..' in rel:
            raise RuntimeError('REGISTERED_PROVIDER_MANIFEST_PATH_INVALID')
        obj,digest=read_manifest(rel)
        if digest!=row['sha256'] or obj.get('contract')!='mad4b.dynamic-operation-provider.v1':
            raise RuntimeError('REGISTERED_PROVIDER_CONTENT_CHANGED')
        op=obj.get('operation_id')
        if not isinstance(op,str) or op in result:raise RuntimeError('REGISTERED_PROVIDER_OP_INVALID')
        result.add(op)
    return result

def project(external:dict,host:dict,native:dict,providers:set[str],now:dt.datetime)->dict:
    if (external.get('contract')!='mad4b.company-native-external-enrollment-doctor.v1'
        or external.get('read_only') is not True or external.get('tenant_write_executed') is not False
        or not isinstance(external.get('remote_sites'),list)
        or host.get('contract')!='mad4b.company-reconcile.host-asset-observation.v1'
        or host.get('full_wpcli_inventory_certified') is not False
        or host.get('read_only') is not True
        or native.get('contract') not in ('mad4b.company-native-universal-readiness.v1',
                                         'mad4b.company-native-universal-readiness.v2')):
        raise RuntimeError('READBACK_AUTHORITY_FLAGS_INVALID')
    remote_fresh=parse_timestamp(external.get('observed_utc'),now,MAX_AGE_EXTERNAL)
    host_fresh=parse_timestamp(host.get('observed_utc'),now,MAX_AGE_HOST)
    rows=[];seen=set()
    for item in external['remote_sites']:
        if not isinstance(item,dict):raise RuntimeError('REMOTE_ENTRY_INVALID')
        company=item.get('company_code');env=item.get('environment');origin=item.get('canonical_origin')
        if not isinstance(company,str) or not CODE.fullmatch(company) or env not in ENV or not isinstance(origin,str) or not origin.startswith('https://'):
            raise RuntimeError('REMOTE_SCOPE_MALFORMED')
        key=company+':'+env
        if key in seen:raise RuntimeError('DUPLICATE_ENROLLMENT_SCOPE')
        seen.add(key)
        owner_path=origin+'/wp-admin/admin.php?page=mad4b-control-plane-site-profile'
        exact_ssh_site=(item.get('authenticated_pinned_ssh_read') is True
                 and item.get('wordpress_origin_environment_verified') is True
                 and isinstance(item.get('central_site_uuid'),str)
                 and item.get('central_site_uuid')==item.get('remote_site_uuid'))
        native_readonly=(env=='production' and exact_ssh_site
                 and item.get('state')=='CENTRAL_SSH_NATIVE_IDENTITY_AND_OWNER_VERIFIED_WRITE_GRANT_MISSING'
                 and item.get('remote_company_site_uuid') is None
                 and item.get('site_profile_plugin_active') is True
                 and item.get('site_profile_audit_ready') is True
                 and item.get('original_grant_present') is False
                 and item.get('production_environment_assignment_state')=='active'
                 and item.get('production_environment_assignment_uuid')==item.get('central_site_uuid')
                 and type(item.get('production_environment_assignment_revision')) is int
                 and item['production_environment_assignment_revision']>=1
                 and item.get('production_environment_owner_operations_authorized') is True)
        original_identity=(exact_ssh_site
                 and item.get('remote_company_site_uuid')==item.get('central_site_uuid'))
        matched=original_identity or native_readonly
        # Production has an environment-specific approved assignment. A
        # separate company-wide assignment may still refer to Staging. Don't
        # treat that old assignment as a new Production consent requirement.
        owner_production=(env=='production'
                 and item.get('production_environment_assignment_state')=='active'
                 and item.get('production_environment_assignment_uuid')==item.get('central_site_uuid')
                 and type(item.get('production_environment_assignment_revision')) is int
                 and item['production_environment_assignment_revision']>=1
                 and item.get('production_environment_owner_operations_authorized') is True)
        legacy_owner=(item.get('central_owner_delegation_active') is True
                 and item.get('central_assignment_matches_target_site_uuid') is True
                 and item.get('central_admin_has_exact_tenant_identity') is True
                 and item.get('production_environment_owner_operations_authorized') is True)
        if not remote_fresh:state='REOBSERVE_REMOTE_ORIGINAL_IDENTITY'
        elif item.get('authenticated_pinned_ssh_read') is not True:state='REMOTE_SSH_IDENTITY_NOT_VERIFIED'
        elif not item.get('remote_site_uuid'):
            state='OWNER_SITE_PROFILE_SAVE_REQUIRED'
        elif not matched:state='SIGNED_EXACT_IDENTITY_ENROLLMENT_REQUIRED'
        elif env=='production' and not (owner_production or legacy_owner):
            state='ORIGINAL_OWNER_DELEGATION_NOT_VERIFIED'
        elif item.get('original_grant_present') is not True:
            state='ORIGINAL_REMOTE_305_GRANT_NOT_ISSUED'
        elif item.get('original_transport_state')!='REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED':
            state='ORIGINAL_TRANSPORT_UNCERTIFIED'
        else:state='BOUND_REMOTE_ORIGINAL_305_STILL_REQUIRES_JOB_SCOPED_312_313'
        rows.append({'scope':key,'origin':origin,'state':state,'owner_site_profile_url':owner_path,
            'site_profile_owner_action_required':state=='OWNER_SITE_PROFILE_SAVE_REQUIRED',
            'exact_site_identity_verified':matched,
            'native_readonly_identity_verified':native_readonly,
            'production_owner_scope_verified':owner_production,
            'signed_312_job_ready':False,
            'signed_313_execution_receipt':False,'native_writes_authorized':False,
            'remote_readback_fresh':remote_fresh})
    # A fresh host-only checksum is not a signed 313 or Central WordPress scan.
    if (host.get('company_code'),host.get('environment'))!=('wayfatours','production'):
        raise RuntimeError('HOST_ASSET_SCOPE_CHANGED')
    count=host.get('catalog_files');changed=host.get('different_files')
    if not isinstance(count,int) or not isinstance(changed,int) or not 0<=changed<=count<=40:
        raise RuntimeError('HOST_ASSET_COUNT_INVALID')
    if not host_fresh:outcome='HOST_ASSET_OBSERVATION_STALE'
    elif changed==0:outcome='HOST_ASSET_SHA_PARITY_OBSERVED_NO_NEW_JOB_REQUIRED'
    elif 'company.appearance.reconcile' not in providers:outcome='EXACT_ORIGINAL_NATIVE_APPEARANCE_HANDLER_UNREGISTERED'
    else:outcome='EXACT_SIGNED_JOB_305_312_313_STILL_REQUIRED'
    return {'contract':CONTRACT,'observed_utc':now.isoformat(),
        'remote_identity_readback_fresh':remote_fresh,'host_asset_readback_fresh':host_fresh,
        'scopes':rows,'wayfatours_appearance':{'files':count,'changed_files':changed,
        'state':outcome,'native_provider_present':'company.appearance.reconcile' in providers,
        'code_release_provider_present':'company.code.release' in providers,
        'original_305_312_313_ready':False,'signed_native_job_created':False,
        'original_313_receipt_issued':False,'source_host_observation_only':True},
        'original_registered_provider_count':len(providers),
        'read_only':True,'enrollments_created':0,'transports_enabled':0,
        'grants_created':0,'target_writes':0,'certificates_issued':0}

def publish(out:dict,path:pathlib.Path=REPORT):
    if path.is_symlink() or path.parent.is_symlink() or not path.parent.is_dir() or path.parent.stat().st_uid!=0:
        raise RuntimeError('REPORT_DIRECTORY_UNTRUSTED')
    fd,tmp=tempfile.mkstemp(dir=path.parent,prefix='.production-admission-')
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600);json.dump(out,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    p=argparse.ArgumentParser();p.add_argument('--report-only',action='store_true');a=p.parse_args()
    if os.geteuid()!=0:raise RuntimeError('HOST_ROOT_READBACK_REQUIRED')
    external=root_doc(EXTERNAL,'mad4b.company-native-external-enrollment-doctor.v1')
    host=root_doc(INVENTORY,'mad4b.company-reconcile.host-asset-observation.v1')
    # Original installed registry validates *all* handlers, verifier programs,
    # first-party dependencies and evidence schemas, not just manifest hashes.
    # It only reads protected root-owned files and issues no original 305/313.
    native=root_doc(NATIVE,'mad4b.company-native-universal-readiness.v1')
    registry_path=REGISTER/'registry.py'
    if (registry_path.is_symlink() or not registry_path.is_file()
        or registry_path.stat().st_uid!=0 or registry_path.stat().st_mode&0o022):
        raise RuntimeError('ORIGINAL_REGISTRY_VALIDATOR_NOT_PINNED')
    spec=importlib.util.spec_from_file_location('_mad4b_original_registry_readonly',str(registry_path))
    if spec is None or spec.loader is None:raise RuntimeError('ORIGINAL_REGISTRY_VALIDATOR_UNAVAILABLE')
    module=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    original_registry=module.Registry(REGISTER)
    def read_man(relative):
        f=REGISTER/relative
        m=root_doc(f,'mad4b.dynamic-operation-provider.v1')
        return m,hashlib.sha256(f.read_bytes()).hexdigest()
    ops=enrolled_ops(root_doc(REGISTER/'index.json','mad4b.dynamic-operation-index.v1'),read_man)
    if ops!=set(original_registry.manifests):
        raise RuntimeError('ORIGINAL_PROVIDER_REGISTRY_VALIDATION_MISMATCH')
    out=project(external,host,native,ops,dt.datetime.now(dt.timezone.utc))
    publish(out)
    print(json.dumps({'contract':CONTRACT,'scopes':{v['scope']:v['state'] for v in out['scopes']},
       'appearance':out['wayfatours_appearance']['state'],
       'jobs_issued':0,'grants_issued':0,'sites_written':0},sort_keys=True))
if __name__=='__main__':
 try:main()
 except (ValueError,RuntimeError,KeyError,TypeError,OSError,json.JSONDecodeError) as exc:
  print(json.dumps({'state':'HOLD','reason':str(exc)[:150],
    'native_job_created':False,'grants_created':False,'production_written':False}));raise SystemExit(2)
