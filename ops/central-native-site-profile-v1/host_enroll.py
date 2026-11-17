#!/usr/bin/env python3
"""MAD4B Central-first read-only Site Profile enrollment using pinned host SSH.

Not WordPress --user, not a forged admin session, not Original313 or a
company write grant. WordPress Site Profile itself performs audit and CAS.
"""
from __future__ import annotations
import argparse,datetime as dt,hashlib,json,os,pathlib,re,runpy,shlex,subprocess,tempfile,stat
ROOT=pathlib.Path('/opt/mad4b-company-provisioning')
FIRST_PARTY=ROOT/'bin/company-native-external-enrollment-readback.py'
REPORT=ROOT/'reports/central-ssh-site-profile-20261117/native-provisioning-receipts.json'
ALLOW={
 'egypt-tour-gates':'https://egypttourgates.com',
 'tourism-almallahgroup':'https://tourism.almallahgroup-mg.com',
}
PHP_SHA='d2e334993ecbb5afb2c35bcfd319f441754cf38afc96d1be5f27fc4bc223a88a'
MARK='MAD4B_CENTRAL_HOST_PROFILE:'

def remote_args(first_party,row):
    identity=first_party['IDENTITIES']/row['ssh_target']
    first_party['verified_pin'](identity,row['host'],row['port'])
    return ['/usr/bin/ssh','-F','/dev/null','-T','-p',str(row['port']),
      '-o','BatchMode=yes','-o','IdentitiesOnly=yes',
      '-o','PreferredAuthentications=publickey','-o','PasswordAuthentication=no',
      '-o','KbdInteractiveAuthentication=no','-o','StrictHostKeyChecking=yes',
      '-o','UpdateHostKeys=no','-o','HostKeyAlgorithms=ssh-ed25519',
      '-o','GlobalKnownHostsFile=/dev/null','-o','UserKnownHostsFile='+str(identity/'known_hosts_verified'),
      '-o','ConnectTimeout=8','-o','ConnectionAttempts=1','-i',str(identity/'id_ed25519'),
      row['ssh_username']+'@'+row['host']]

def ssh_eval(args,row,php,authorize=False):
    prefix=(
        'MAD4B_CENTRAL_SSH_ENROLLMENT=1 '
        + 'MAD4B_CENTRAL_SSH_COMPANY='+shlex.quote(row['company_id'])+' '
        + 'MAD4B_CENTRAL_SSH_ORIGIN='+shlex.quote(row['origin'])+' '
    ) if authorize else ''
    cmd=prefix+'wp --path='+shlex.quote(row['ssh_root'])+' --skip-themes eval '+shlex.quote(php)
    p=subprocess.run(args+[cmd],capture_output=True,text=True,timeout=84)
    lines=[x.split(MARK,1)[1] for x in p.stdout.splitlines() if x.startswith(MARK)]
    if p.returncode or len(lines)!=1:
        # Never emit stdout/credentials/stack traces from a production host.
        raise RuntimeError('PINNED_HOST_WPCLI_REFUSED:'+row['company_id']+':'+str(p.returncode))
    data=json.loads(lines[0])
    if not isinstance(data,dict):raise RuntimeError('REMOTE_PROFILE_READBACK_INVALID')
    return data

def site_probe():
    return r'''
$cls='MAD4B_SCP_Site_Profile';
$cache_repaired=false;
$p=get_option($cls::OPTION,null);
// Persistent object cache may retain a stale notoptions tombstone even when
// the canonical DB option was committed and append-only audited. In the
// already pinned SSH WP-CLI lane only, reconcile that *cache* key after
// validating the exact existing Production Site Profile from the DB.
// Never create/replace a profile or clear unrelated runtime caches.
if(!is_array($p) && defined('WP_CLI') && WP_CLI && get_current_user_id()===0) {
    global $wpdb;
    $db=$wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name=%s",
        $cls::OPTION));
    $stored=is_string($db)?maybe_unserialize($db):false;
    if(is_array($stored)
       && $cls::validate_record_for_test(
            $stored,wp_get_environment_type(),untrailingslashit(home_url('/')))
       && !empty($stored['site_uuid'])
       && empty($stored['features']['write'])) {
        $not=wp_cache_get('notoptions','options');
        if(is_array($not) && array_key_exists($cls::OPTION,$not)) {
            unset($not[$cls::OPTION]);
            wp_cache_set('notoptions',$not,'options');
        }
        wp_cache_delete($cls::OPTION,'options');
        wp_cache_delete('alloptions','options');
        $cls::reset_cache();
        $p=get_option($cls::OPTION,null);
        $cache_repaired=is_array($p)
            && ($p['site_uuid']??null)===($stored['site_uuid']??null);
    }
}
$s=$cls::status();
$a=MAD4B_SCP_Audit::storage_status();
$f=WP_PLUGIN_DIR.'/mad4b-site-control-plane/includes/class-mad4b-scp-site-profile.php';
echo 'MAD4B_CENTRAL_HOST_PROFILE:'.wp_json_encode([
'contract'=>'mad4b.central.native-site-profile.probe.v1',
'origin'=>untrailingslashit(home_url('/')),
'siteurl'=>untrailingslashit(site_url('/')),
'environment'=>wp_get_environment_type(),
'wp_cli'=>defined('WP_CLI')&&WP_CLI,
'user_id'=>(int)get_current_user_id(),
'profile_exists'=>null!==$p&&false!==$p,
'configured'=>!empty($s['configured']),
'site_uuid'=>$s['site_uuid']??null,
'profile_digest'=>$cls::profile_digest(),
'audit_ready'=>!empty($a['ready']),
'audit_count'=>(int)($a['event_count']??0),
'write_enabled'=>$cls::write_enabled(),
'php_sha256'=>is_file($f)?hash_file('sha256',$f):null,
'native_method_exists'=>method_exists($cls,'provision_from_central_host'),
'option_cache_repaired'=>$cache_repaired
],JSON_UNESCAPED_SLASHES);
'''

def apply_code(company):
    return r'''
$c='''+json.dumps(company)+r''';
$r=MAD4B_SCP_Site_Profile::provision_from_central_host([
    'company_code'=>$c,
    'canonical_origin'=>untrailingslashit(home_url('/'))
]);
if(is_wp_error($r)){WP_CLI::error('NATIVE_SCOPE_OR_AUDIT_DENIED:'.$r->get_error_code());}
$s=MAD4B_SCP_Site_Profile::status();
$p=MAD4B_SCP_Site_Profile::profile();
$a=MAD4B_SCP_Audit::storage_status();
echo 'MAD4B_CENTRAL_HOST_PROFILE:'.wp_json_encode([
'contract'=>'mad4b.central.native-site-profile.saved.v1',
'origin'=>untrailingslashit(home_url('/')),
'environment'=>$s['environment']??null,
'site_uuid'=>$s['site_uuid']??null,
'revision'=>$s['revision']??null,
'configured'=>$s['configured']??false,
'origin_match'=>$s['origin_match']??false,
'environment_match'=>$s['environment_match']??false,
'profile_digest'=>MAD4B_SCP_Site_Profile::profile_digest(),
'features'=>$p['features']??[],
'oauth_user_count'=>count($p['oauth_user_ids']??[]),
'audit_ready'=>$a['ready']??false,
'audit_count'=>(int)($a['event_count']??0),
'wp_user'=>(int)get_current_user_id(),
'write_enabled'=>MAD4B_SCP_Site_Profile::write_enabled()
],JSON_UNESCAPED_SLASHES);
'''

def check_scope(row,read,expected):
    if (read.get('contract')!='mad4b.central.native-site-profile.probe.v1'
        or read.get('origin')!=expected or read.get('siteurl')!=expected
        or read.get('environment')!='production' or read.get('wp_cli') is not True
        or read.get('user_id')!=0 or read.get('php_sha256')!=PHP_SHA
        or read.get('native_method_exists') is not True or read.get('audit_ready') is not True):
        raise RuntimeError('EXACT_PRODUCTION_PINNED_SSH_PROFILE_PREFLIGHT_FAILED:'+row['company_id'])
    if read.get('profile_exists') or read.get('configured'):
        raise RuntimeError('SITE_ALREADY_PROVISIONED_USE_READBACK_NOT_OVERWRITE:'+row['company_id'])

def ensure_after(read,expected):
    if (read.get('contract')!='mad4b.central.native-site-profile.saved.v1'
        or read.get('origin')!=expected or read.get('environment')!='production'
        or read.get('configured') is not True
        or read.get('origin_match') is not True or read.get('environment_match') is not True
        or not isinstance(read.get('site_uuid'),str)
        or not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}',read['site_uuid'])
        or read.get('revision')!=1 or read.get('write_enabled') is not False
        or read.get('oauth_user_count')!=0 or read.get('wp_user')!=0
        or read.get('audit_ready') is not True
        or not isinstance(read.get('profile_digest'),str)
        or not re.fullmatch(r'[a-f0-9]{64}',read['profile_digest'])
        or not isinstance(read.get('features'),dict)
        or any(read['features'].get(k) for k in ('write','oauth','skills','managed_runtime','acceptance'))):
        raise RuntimeError('CENTRAL_HOST_COMMIT_PERSISTED_PROFILE_RECHECK_FAILED')

def append_receipt(row,data,before_count,after_count):
    if after_count<=before_count:raise RuntimeError('APPEND_ONLY_AUDIT_EVENT_NOT_OBSERVED')
    if REPORT.is_symlink() or REPORT.parent.is_symlink() or REPORT.parent.stat().st_uid!=0:
        raise RuntimeError('REPORT_PATH_NOT_ROOT_OWNED')
    if REPORT.exists():
        cur=json.loads(REPORT.read_text())
        if cur.get('contract')!='mad4b.central.native-site-profile.host-ssh-receipt.v1':
            raise RuntimeError('EXISTING_RECEIPT_CONTRACT_INVALID')
    else:cur={'contract':'mad4b.central.native-site-profile.host-ssh-receipt.v1','entries':[]}
    if any(a['company']==row['company_id'] for a in cur['entries']):raise RuntimeError('DUPLICATE_ENROLLMENT_RECEIPT')
    cur['entries'].append({
       'company':row['company_id'],'environment':'production',
       'origin':data['origin'],'site_uuid':data['site_uuid'],'profile_digest':data['profile_digest'],
       'revision':data['revision'],'before_audit_count':before_count,'after_audit_count':after_count,
       'authority':'central_native_pinned_ssh_no_wp_user',
       'signed_original313':False,'grants_created':0,'write_enabled':False,
       'verified_at_utc':dt.datetime.now(dt.timezone.utc).isoformat()})
    fd,tmp=tempfile.mkstemp(dir=REPORT.parent,prefix='.native-enrollment-')
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600);json.dump(cur,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(tmp,REPORT)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    ap=argparse.ArgumentParser()
    ap.add_argument('--company',choices=sorted(ALLOW),required=True)
    ap.add_argument('--mode',choices=('status','preflight','enroll'),default='enroll')
    args=ap.parse_args()
    if os.geteuid()!=0:raise RuntimeError('CENTRAL_HOST_ROOT_PROVENANCE_REQUIRED')
    source=FIRST_PARTY
    if source.is_symlink() or not source.is_file() or source.stat().st_uid!=0 or source.stat().st_mode & 0o022:
        raise RuntimeError('CENTRAL_PINNED_SSH_SOURCE_NOT_TRUSTED')
    fp=runpy.run_path(str(source),run_name='central_native_ssh_policy_module')
    rows=[r for r in fp['policy_routes']() if r['company_id']==args.company and r['environment']=='production']
    if len(rows)!=1 or rows[0]['origin']!=ALLOW[args.company]:raise RuntimeError('EXACT_COMPANY_ORIGIN_POLICY_REQUIRED')
    row=rows[0];host=remote_args(fp,row)
    before=ssh_eval(host,row,site_probe())
    already=before.get('profile_exists') is True and before.get('configured') is True
    if already:
        if (before.get('origin')!=ALLOW[args.company]
            or before.get('siteurl')!=ALLOW[args.company]
            or before.get('environment')!='production'
            or before.get('write_enabled') is not False
            or before.get('audit_ready') is not True
            or before.get('php_sha256')!=PHP_SHA
            or not re.fullmatch(r'[0-9a-f]{8}(?:-[0-9a-f]{4}){3}-[0-9a-f]{12}',str(before.get('site_uuid') or ''))
            or not re.fullmatch(r'[a-f0-9]{64}',str(before.get('profile_digest') or ''))):
            raise RuntimeError('EXISTING_PRODUCTION_PROFILE_IDENTITY_MISMATCH')
        print(json.dumps({'state':'ALREADY_ENROLLED_NO_DUPLICATE_WRITE',
          'company':args.company,'origin':ALLOW[args.company],
          'site_uuid':before['site_uuid'],'profile_digest':before['profile_digest'],
          'cached_option_repaired':before.get('option_cache_repaired',False),
          'signed_original313':False,'site_write_enabled':False},sort_keys=True))
        return
    check_scope(row,before,ALLOW[args.company])
    if args.mode=='status':
        print(json.dumps({'state':'READY_FOR_CENTRAL_NATIVE_ENROLLMENT',
            'company':args.company,'site_profile_missing':True,
            'write_authorized':False},sort_keys=True));return
    if args.mode=='preflight':
        print(json.dumps({'state':'EXACT_HOST_CENTRAL_PREFLIGHT_PASS','company':args.company,
         'audit_ready':True,'existing_site_profile':False,'writes':0},sort_keys=True));return
    result=ssh_eval(host,row,apply_code(args.company),authorize=True)
    ensure_after(result,ALLOW[args.company])
    after=ssh_eval(host,row,site_probe())
    if (after.get('site_uuid')!=result['site_uuid'] or after.get('profile_digest')!=result['profile_digest']
        or after.get('configured') is not True or after.get('write_enabled') is not False):
        raise RuntimeError('INDEPENDENT_PINNED_SSH_READBACK_FAILED')
    append_receipt(row,result,int(before['audit_count']),int(after['audit_count']))
    print(json.dumps({'state':'CENTRAL_PINNED_SSH_READONLY_SITE_PROFILE_ENROLLED',
        'company':args.company,'origin':result['origin'],'site_uuid':result['site_uuid'],
        'profile_digest':result['profile_digest'],'audit_verified':True,
        'wp_user_impersonated':False,'governed_writes_enabled':False,'signed_original313':False},sort_keys=True))

if __name__=='__main__':
    try:main()
    except (ValueError,RuntimeError,TypeError,OSError,subprocess.TimeoutExpired,json.JSONDecodeError) as exc:
        print(json.dumps({'state':'HOLD','reason':str(exc)[:160],
          'owner_impersonated':False,'governed_writes_enabled':False}))
        raise SystemExit(2)
