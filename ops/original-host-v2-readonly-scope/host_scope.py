#!/usr/bin/env python3
"""Exact Production Hostinger-v2 *read-only binding*; signed original Host rotation.

Enrolling a Hostinger remote target into first-party read routing does not
issue an Original305/312/313 grant. All generic operator/mutation flags MUST
remain false. The independent signed per-operation executor is never bypassed.
"""
from __future__ import annotations
import argparse,datetime as dt,fcntl,hashlib,json,os,pathlib,runpy,shutil,stat,subprocess,tempfile
ROOT=pathlib.Path('/opt/mad4b-company-transport')
HOST=ROOT/'native-operator-v1/host-authority.json'
ROUTER=ROOT/'unified-execution-router-v1/policy.json'
SIGNER=ROOT/'broker-v1/native-vps-policy-rotate.py'
SSH_SOURCE=pathlib.Path('/opt/mad4b-company-provisioning/bin/company-native-external-enrollment-readback.py')
REPORT=pathlib.Path('/opt/mad4b-company-provisioning/reports/original-host-v2-readonly-scope-20261118')
LOCK=pathlib.Path('/run/lock/mad4b-original-host-v2-readonly-scope.lock')
COMPANIES=('egypt-tour-gates','tourism-almallahgroup')
MUTATION_KEYS=('allow_operator','allow_mutation','allow_production_mutation','allow_sql','allow_remote_shell')

def rootbytes(path,limit=100_000,secret=False):
    if not path.is_file() or path.is_symlink():raise RuntimeError('ROOT_PIN_FILE_MISSING_OR_SYMLINK')
    st=path.stat()
    if st.st_uid!=0 or not stat.S_ISREG(st.st_mode) or st.st_mode&0o022 or st.st_size>limit:
        raise RuntimeError('ROOT_FILE_PERMISSIONS_OR_SIZE_INVALID')
    if secret and st.st_mode&0o077:raise RuntimeError('ROOT_SECRET_PERMISSIONS_INVALID')
    return path.read_bytes()

def original_sign(action):
    res=subprocess.run(['/usr/bin/python3',str(SIGNER),action],capture_output=True,text=True,timeout=120)
    if res.returncode!=0:raise RuntimeError('ORIGINAL_HOST_ED25519_SIGNING_OR_LEDGER_REFUSED_'+action)
    entries=[s for s in res.stdout.splitlines() if s.startswith('{')]
    if len(entries)!=1:raise RuntimeError('ORIGINAL_SIGNER_RECEIPT_MALFORMED')
    return json.loads(entries[0])

def central_owners():
    php=r'''
$manager=MAD4B_Company_Operation_Admission_V1::manager_id();
$co=['egypt-tour-gates','tourism-almallahgroup'];$out=[];
foreach($co as $c) {
 $row=MAD4B_Company_Portfolio_Manager_V1::production_assignment_status($manager,$c);
 $site=MAD4B_Company_Environment_Registry_V2::target($c,'production');
 $out[$c]=[
 'state'=>$row['state']??null,
 'revision'=>$row['revision']??null,
 'uuid'=>$row['company_site_uuid']??null,
 'binding_current'=>$row['binding_current']??false,
 'operations_manage'=>in_array('operations_manage',(array)($row['rights']??[]),true),
 'approval_matches'=>isset($row['proposal_sha256'],$row['approval_sha256'])
   &&hash_equals((string)$row['proposal_sha256'],(string)$row['approval_sha256']),
 'site_uuid'=>$site['site_uuid']??null,
 'origin'=>$site['canonical_origin']??null
 ];
}
echo 'HOST_V2_OWNER:'.wp_json_encode([
'contract'=>'mad4b.host-v2.owner-production-readonly.v1',
'central'=>DB_NAME==='etg_commerce_staging'&&wp_get_environment_type()==='staging'
&&untrailingslashit(home_url('/'))==='https://tours.dev.mad4b.com',
'owners'=>$out],JSON_UNESCAPED_SLASHES);
'''
    proc=subprocess.run(['docker','exec','-u','33:33','php-wkgtkzta35wkkgouwt6ruljc',
        '/usr/local/bin/wp','eval',php,'--path=/var/www/html','--skip-themes'],capture_output=True,text=True,timeout=95)
    found=[line.split('HOST_V2_OWNER:',1)[1] for line in proc.stdout.splitlines() if line.startswith('HOST_V2_OWNER:')]
    if proc.returncode or len(found)!=1:raise RuntimeError('CENTRAL_OWNER_READBACK_FAILED')
    obj=json.loads(found[0])
    if obj.get('contract')!='mad4b.host-v2.owner-production-readonly.v1' or obj.get('central') is not True:
        raise RuntimeError('CENTRAL_ORIGIN_NOT_VERIFIED')
    if set(obj['owners'])!=set(COMPANIES):raise RuntimeError('CENTRAL_COMPANY_SET_CHANGED')
    for c,x in obj['owners'].items():
        if x.get('state')!='active' or x.get('binding_current') is not True or x.get('operations_manage') is not True or x.get('approval_matches') is not True or x.get('site_uuid')!=x.get('uuid') or not isinstance(x.get('revision'),int) or x['revision']<1:
            raise RuntimeError('CENTRAL_PRODUCTION_OWNER_OR_SITE_BINDING_NOT_APPROVED_'+c)
    return obj['owners']

def remote_routes(owners):
    if SSH_SOURCE.is_symlink() or SSH_SOURCE.stat().st_uid!=0 or SSH_SOURCE.stat().st_mode&0o022:
        raise RuntimeError('PINNED_SSH_FIRST_PARTY_SOURCE_NOT_TRUSTED')
    impl=runpy.run_path(str(SSH_SOURCE),run_name='_readonly_host_v2_policy')
    rows=[x for x in impl['policy_routes']() if x['company_id'] in COMPANIES and x['environment']=='production']
    if len(rows)!=len(COMPANIES):raise RuntimeError('PINNED_REMOTE_ROUTES_MISSING')
    routes={}
    for row in rows:
        c=row['company_id']
        if c in routes or row['origin']!=owners[c]['origin']:raise RuntimeError('ROUTE_COMPANY_ORIGIN_MISMATCH')
        remote=impl['remote_site'](row,None)
        if not (remote.get('site_profile_uuid')==owners[c]['uuid']
           and remote.get('site_profile_origin')==row['origin']
           and remote.get('site_profile_environment')=='production'
           and remote.get('wordpress_environment')=='production'
           and remote.get('home')==row['origin'] and remote.get('siteurl')==row['origin']
           and remote.get('site_profile_plugin_active') is True
           and remote.get('site_profile_audit_ready') is True
           and remote.get('site_profile_governed_write') is False):
            raise RuntimeError('REMOTE_PRODUCTION_PROFILE_PINNED_SSH_READBACK_MISMATCH_'+c)
        routes[c]=row
    return routes

def desired(host,router,owners,routes):
    if(host.get('contract')!='mad4b.native-vps-operator.v1'
       or host.get('host_id')!='srv2030908' or host.get('revoked') is not False
       or router.get('contract')!='mad4b.unified-execution-router.policy.v1'
       or router.get('auth_model')!='source-verified-host-operator-read-only'):
        raise RuntimeError('ORIGINAL_HOST_OR_ROUTER_CONTRACT_CHANGED')
    h=json.loads(json.dumps(host));r=json.loads(json.dumps(router));changed=[]
    for c in COMPANIES:
        row=routes[c];scope=c+':production';now=h['scopes'].get(scope)
        if now is not None:
            if not isinstance(now,dict) or any(now.get(k) is not False for k in MUTATION_KEYS):
                raise RuntimeError('EXISTING_HOST_SCOPE_WRITER_PRESENT_REFUSE_'+c)
            if now.get('site_uuid')!=owners[c]['uuid'] or now.get('canonical_origin')!=row['origin']:
                raise RuntimeError('EXISTING_HOST_SCOPE_DIFFERENT_UUID_OR_ORIGIN_'+c)
        target=r['targets'].get(scope)
        if target is not None:
            if (not isinstance(target,dict) or target.get('site_uuid')!=owners[c]['uuid']
                or target.get('canonical_origin')!=row['origin']
                or target.get('read_only_hostinger_binding') is not True):
                raise RuntimeError('EXISTING_ROUTER_TARGET_DIFFERS_'+c)
        hostscope={
           'allow_operator':False,'allow_mutation':False,
           'allow_production_mutation':False,'allow_sql':False,
           'allow_remote_shell':False,'allow_pinned_wpcli_read':True,
           'canonical_origin':row['origin'],'site_uuid':owners[c]['uuid'],
           'site_environment':'production','expected_root':row['ssh_root'],
           'registry_binding':row['ssh_target']+':production'
        }
        targetscope={
           'company_id':c,'environment':'production',
           'site_uuid':owners[c]['uuid'],'canonical_origin':row['origin'],
           'preferences':['hostinger_pinned_read'],
           'providers':{'hostinger_pinned_read':{'ssh_target':row['ssh_target'],
                  'expected_root':row['ssh_root']}},
           'read_only_hostinger_binding':True
        }
        if now is None:
            h['scopes'][scope]=hostscope;changed.append(scope)
        elif any(now.get(k)!=v for k,v in hostscope.items()):
            # No implicit overwrite of previously enrolled first-party scope.
            raise RuntimeError('EXISTING_HOST_SCOPE_SCHEMA_CONFLICT_'+c)
        if target is None:
            r['targets'][scope]=targetscope
        elif target!=targetscope:
            raise RuntimeError('EXISTING_ROUTER_SCHEMA_CONFLICT_'+c)
    return h,r,changed

def canonical(obj):return (json.dumps(obj,indent=2,sort_keys=True,ensure_ascii=False)+'\n').encode()
def atomic(path,data,mode):
    fd,tmp=tempfile.mkstemp(prefix='.signed-host-v2-',dir=path.parent)
    try:
        with os.fdopen(fd,'wb') as out:
            os.fchmod(out.fileno(),mode);out.write(data);out.flush();os.fsync(out.fileno())
        os.chown(tmp,0,0);os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    args=argparse.ArgumentParser();args.add_argument('action',choices=('plan','apply','verify'));mode=args.parse_args().action
    if os.geteuid()!=0:raise RuntimeError('ROOT_HOST_ORIGINAL_ADMISSION_REQUIRED')
    rootbytes(SIGNER,65536)
    REPORT.mkdir(exist_ok=True,mode=0o700)
    if REPORT.is_symlink() or REPORT.stat().st_uid!=0 or REPORT.stat().st_mode&0o077:raise RuntimeError('REPORT_DIR_NOT_ROOT_ONLY')
    with LOCK.open('a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX)
        raw_h=rootbytes(HOST,65536,secret=True)
        raw_r=rootbytes(ROUTER,65536)
        h=json.loads(raw_h);r=json.loads(raw_r)
        owners=central_owners();routes=remote_routes(owners)
        new_h,new_r,changed=desired(h,r,owners,routes)
        old_sha=hashlib.sha256(raw_h).hexdigest()
        new_sha=hashlib.sha256(canonical(new_h)).hexdigest()
        check=original_sign('plan')
        if check.get('state')!='ORIGINAL_POLICY_ROTATION_NOT_REQUIRED' or check.get('original_pinned_signer_verified') is not True or check.get('original_principal_ledger_verified') is not True:
            raise RuntimeError('EXISTING_PINNED_ORIGINAL_POLICY_NOT_CURRENT')
        if mode=='plan':
            print(json.dumps({'state':'EXACT_READONLY_REMOTE_HOST_SCOPE_READY' if changed else 'EXACT_READONLY_REMOTE_HOST_SCOPE_EXISTS',
             'scopes':list(COMPANIES),'new_scopes':changed,'original_signer_verified':True,
             'read_only':True,'writes_enabled':False,'new_policy_sha256':new_sha,
             'grants_issued':0,'content_mutated':False},sort_keys=True));return
        if mode=='verify':
            if changed or r!=new_r or h!=new_h:raise RuntimeError('READ_ONLY_SCOPE_MISMATCH')
            print(json.dumps({'state':'SIGNED_HOST_POLICY_AND_ROUTER_EXACT_READONLY_READY','scopes':list(COMPANIES),
             'original_signer_current':True,'grants_issued':0,'content_mutated':False},sort_keys=True));return
        if not changed:raise RuntimeError('ALREADY_ENROLLED_USE_VERIFY')
        backup_h=REPORT/('host-before-'+old_sha[:16]+'.json')
        backup_r=REPORT/('router-before-'+hashlib.sha256(raw_r).hexdigest()[:16]+'.json')
        if backup_h.exists() or backup_r.exists():raise RuntimeError('IMMUTABLE_ORIGINAL_PREIMAGE_ALREADY_STORED')
        atomic(backup_h,raw_h,0o600);atomic(backup_r,raw_r,0o600)
        if rootbytes(HOST,65536,secret=True)!=raw_h or rootbytes(ROUTER,65536)!=raw_r:
            raise RuntimeError('POLICY_CHANGED_DURING_PRECHECK')
        # Both target files only add exact read-only scope; all mutation booleans false.
        atomic(ROUTER,canonical(new_r),0o600)
        atomic(HOST,canonical(new_h),0o600)
        if hashlib.sha256(rootbytes(HOST,65536,secret=True)).hexdigest()!=new_sha:
            raise RuntimeError('HOST_POLICY_POSTWRITE_SHA_MISMATCH')
        signed=original_sign('rotate')
        if signed.get('state') not in ('ORIGINAL_PRINCIPAL_POLICY_ROTATED','ORIGINAL_HOST_POLICY_CURRENT'):
            raise RuntimeError('SIGNED_ORIGINAL_HOST_ROTATION_NOT_VERIFIED')
        after=original_sign('plan')
        if after.get('state')!='ORIGINAL_POLICY_ROTATION_NOT_REQUIRED':
            raise RuntimeError('ORIGINAL_HOST_SIGNED_LEDGER_POSTREADBACK_MISMATCH')
        print(json.dumps({'state':'SIGNED_ORIGINAL_READONLY_HOST_SCOPE_ENROLLED',
          'scopes':changed,'original_signer_current':True,'original_rotation':signed['state'],
          'new_policy_sha256':new_sha,'original_grant_issued':False,
          'original312_313_certified':False,'content_mutated':False},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (OSError,KeyError,TypeError,ValueError,RuntimeError,subprocess.TimeoutExpired) as exc:
        print(json.dumps({'state':'HOLD','reason':str(exc)[:155],
          'new_grants_issued':False,'production_content_mutated':False}))
        raise SystemExit(2)
