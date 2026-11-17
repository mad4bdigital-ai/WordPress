#!/usr/bin/env python3
"""Bounded original Central WordPress read-only identity registration from SSH.

Fresh pinned remote readback is compared against first-party host append-only
receipts. No site password, OAuth, WP --user, signed313 or delegated grant.
"""
from pathlib import Path
import base64,datetime as dt,hashlib,json,os,re,runpy,subprocess,sys
SOURCE=Path('/opt/mad4b-company-provisioning/worktrees/central-native-ssh-enrollment-20261117/ops/central-native-site-profile-v1/host_enroll.py')
RECEIPT=Path('/opt/mad4b-company-provisioning/reports/central-ssh-site-profile-20261117/native-provisioning-receipts.json')
CENTRAL='php-wkgtkzta35wkkgouwt6ruljc'
MARK='MAD4B_CENTRAL_BINDING:'
def run_central(code,write=False):
    command=['/usr/bin/docker','exec','-u','33:33']
    if write: command+=['-e','MAD4B_CENTRAL_SSH_REGISTRATION=1']
    command += [CENTRAL,'/usr/local/bin/wp','eval',code,'--path=/var/www/html','--skip-themes']
    p=subprocess.run(command,capture_output=True,text=True,timeout=67)
    marked=[x.split(MARK,1)[1] for x in p.stdout.splitlines() if x.startswith(MARK)]
    if p.returncode or len(marked)!=1:
        raise RuntimeError('CENTRAL_NATIVE_WPCLI_REFUSED_OR_UNVERIFIED')
    data=json.loads(marked[0])
    if not isinstance(data,dict):raise RuntimeError('CENTRAL_NATIVE_WPCLI_JSON_INVALID')
    return data
def central_code(company,payload=None):
    if payload is None:
        return r'''
$a=get_option('mad4b_tourism_company_site_registry_v1',[]);
echo 'MAD4B_CENTRAL_BINDING:'.wp_json_encode(['contract'=>'mad4b.central.native-site-register-readback.v1',
'company'=>'''+json.dumps(company)+r''',
'registered'=>isset($a['''+json.dumps(company)+r''']),
'site_uuid'=>$a['''+json.dumps(company)+r''']['company_site_uuid']??null,
'profile_digest'=>$a['''+json.dumps(company)+r''']['native_profile_digest']??null,
'write_authorized'=>($a['''+json.dumps(company)+r''']['native_write_authorized']??false)===true,
'preferred_provisioner'=>$a['''+json.dumps(company)+r''']['preferred_identity_provisioner']??null],JSON_UNESCAPED_SLASHES);
'''
    encoded=base64.b64encode(json.dumps(payload,separators=(',',':'),sort_keys=True).encode()).decode()
    return '''
$p=json_decode(base64_decode("'''+encoded+'''"),true);
$r=MAD4B_Central_Native_Host_Identity_V1::register($p);
if(is_wp_error($r))WP_CLI::error('CENTRAL_NATIVE_READBACK_DENIED:'.$r->get_error_code());
echo 'MAD4B_CENTRAL_BINDING:'.wp_json_encode($r,JSON_UNESCAPED_SLASHES);
'''
def main():
    if os.geteuid()!=0:raise RuntimeError('ROOT_HOST_VERIFIED_READBACK_REQUIRED')
    company=sys.argv[1] if len(sys.argv)==2 else None
    if company not in ('egypt-tour-gates','tourism-almallahgroup'):raise RuntimeError('COMPANY_NOT_IN_PINNED_HOST_SCOPE')
    if RECEIPT.is_symlink() or RECEIPT.stat().st_uid!=0 or RECEIPT.stat().st_mode&0o077:raise RuntimeError('FIRST_PARTY_HOST_RECEIPT_NOT_TRUSTED')
    entries=json.loads(RECEIPT.read_text())
    if entries.get('contract')!='mad4b.central.native-site-profile.host-ssh-receipt.v1':raise RuntimeError('HOST_RECEIPT_CONTRACT_INVALID')
    matching=[v for v in entries['entries'] if v.get('company')==company]
    if len(matching)!=1:raise RuntimeError('EXACT_HOST_RECEIPT_REQUIRED')
    receipt=matching[0]
    if (receipt.get('authority')!='central_native_pinned_ssh_no_wp_user'
        or receipt.get('signed_original313') is not False
        or receipt.get('write_enabled') is not False
        or receipt.get('revision')!=1):raise RuntimeError('HOST_RECEIPT_AUTHORITY_INVALID')
    stamp=dt.datetime.fromisoformat(receipt['verified_at_utc'].replace('Z','+00:00'))
    if not 0<=(dt.datetime.now(dt.timezone.utc)-stamp).total_seconds()<=7200:raise RuntimeError('HOST_RECEIPT_TOO_OLD')
    m=runpy.run_path(str(SOURCE),run_name='_verified_central_host_enroller')
    first=runpy.run_path(str(m['FIRST_PARTY']),run_name='_root_first_party_policy')
    routes=[r for r in first['policy_routes']() if r['company_id']==company and r['environment']=='production']
    if len(routes)!=1 or routes[0]['origin']!=m['ALLOW'][company]:raise RuntimeError('HOST_ORIGIN_POLICY_CHANGED')
    row=routes[0];host=m['remote_args'](first,row)
    remote=m['ssh_eval'](host,row,m['site_probe']())
    if (remote.get('origin')!=receipt['origin'] or remote.get('siteurl')!=receipt['origin']
        or remote.get('environment')!='production' or remote.get('site_uuid')!=receipt['site_uuid']
        or remote.get('profile_digest')!=receipt['profile_digest']
        or remote.get('audit_ready') is not True or remote.get('configured') is not True
        or remote.get('write_enabled') is not False
        or (remote.get('audit_count') or 0)<receipt['after_audit_count']
        or remote.get('php_sha256')!=m['PHP_SHA']):
        raise RuntimeError('FRESH_PINNED_SSH_PROFILE_READBACK_DIFFERS_FROM_HOST_RECEIPT')
    original=run_central(central_code(company))
    if original.get('registered') is True:
        if (original.get('site_uuid')==receipt['site_uuid']
            and original.get('profile_digest')==receipt['profile_digest']
            and original.get('write_authorized') is False
            and original.get('preferred_provisioner')=='central_native_pinned_ssh'):
            print(json.dumps({'state':'ALREADY_REGISTERED_EXACT_IDENTITY_VERIFIED',
                'company':company,'site_uuid':receipt['site_uuid'],
                'preferred_provisioner':'central_native_pinned_ssh',
                'write_authorized':False},sort_keys=True))
            return
        raise RuntimeError('CENTRAL_COMPANY_EXISTING_BINDING_CONFLICT_REVIEW_REQUIRED')
    data={
      'company':company,'environment':'production','origin':receipt['origin'],
      'site_uuid':receipt['site_uuid'],'profile_digest':receipt['profile_digest'],
      'revision':1,'write_enabled':False,'profile_audit_verified':True,
      'host_transport':'pinned_hostinger_ssh_wpcli',
      'source_contract':'mad4b.central.native-site-profile.saved.v1'
    }
    result=run_central(central_code(company,data),write=True)
    if result.get('state')!='EXACT_READ_ONLY_HOST_IDENTITY_REGISTERED' or result.get('write_authorized') is not False:
        raise RuntimeError('CENTRAL_NATIVE_REGISTRATION_NOT_VERIFIED')
    post=run_central(central_code(company))
    if (post.get('registered') is not True or post.get('site_uuid')!=receipt['site_uuid']
        or post.get('profile_digest')!=receipt['profile_digest']
        or post.get('write_authorized') is not False
        or post.get('preferred_provisioner')!='central_native_pinned_ssh'):
        raise RuntimeError('CENTRAL_INDEPENDENT_BOUND_IDENTITY_READBACK_FAILED')
    print(json.dumps({'state':'CENTRAL_READ_ONLY_IDENTITY_REGISTERED_AND_VERIFIED',
      'company':company,'site_uuid':receipt['site_uuid'],
      'method_preferred':'central_native_pinned_ssh','site_write_authorized':False,
      'original313_signed':False},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (ValueError,KeyError,TypeError,RuntimeError,OSError,subprocess.TimeoutExpired) as error:
        print(json.dumps({'state':'HOLD','reason':str(error)[:150],
          'site_write_grants_created':False,'signed_original313':False}))
        raise SystemExit(2)
