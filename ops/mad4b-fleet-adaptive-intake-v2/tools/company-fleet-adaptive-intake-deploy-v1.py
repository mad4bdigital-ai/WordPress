#!/usr/bin/env python3
"""Deploy audited MAD4B Fleet Adaptive Intake to enrolled sites over pinned SSH.

Staging only by default. No production mutation and no credential handling.
"""
import argparse,base64,datetime,hashlib,json,os,shlex,subprocess,sys,tempfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
POLICY=BASE/'policies/company-hostinger-site-binding-map-v1.json'
ROUTING=BASE/'reports/company-hostinger-two-route-bindings-current.json'
SOURCE=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/mu-plugins/319-mad4b-fleet-adaptive-intake.php')
REPORT_DIR=BASE/'reports/fleet-adaptive-intake-deployment-20261108'
sys.path.insert(0,str(BASE/'bin'))
import importlib.util
spec=importlib.util.spec_from_file_location('two_route',str(BASE/'bin/company-hostinger-two-route-binding-v1.py'))
routing=importlib.util.module_from_spec(spec);spec.loader.exec_module(routing)
def invoke(cmd,timeout=27,input=None):
    r=subprocess.run(cmd,input=input,capture_output=True,timeout=timeout)
    if r.returncode:raise RuntimeError('REMOTE_COMMAND_REFUSED exit='+str(r.returncode)+' output='+r.stderr[-220:].decode('utf-8','replace'))
    return r.stdout.decode('utf-8','replace').strip()
def run_ssh(target,command,timeout=27,input=None):
    return invoke(routing.ssh_cmd(target)+[command],timeout=timeout,input=input)
def canonical(scope):
    candidates=json.loads(POLICY.read_text())['sites']
    entry=next((v for v in candidates if v['company_id']+':'+v['environment']==scope),None)
    if not entry:raise RuntimeError('SITE_NOT_REGISTERED')
    data=json.loads(ROUTING.read_text())['sites'].get(scope)
    if not data or not data['ssh_verified'] or data['canonical_origin']!=entry['origin']:
        raise RuntimeError('LIVE_SSH_ROUTE_IDENTITY_NOT_VERIFIED')
    if not data.get('company_site_uuid'):raise RuntimeError('REGISTERED_SITE_UUID_MISSING')
    return entry,data
def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--scope',required=True)
    parser.add_argument('--execute',action='store_true')
    parser.add_argument('--allow-production',action='store_true')
    parser.add_argument('--upgrade',action='store_true')
    args=parser.parse_args()
    entry,data=canonical(args.scope)
    if entry['environment']=='production' and not args.allow_production:
        raise RuntimeError('PRODUCTION_DEPLOYMENT_REQUIRES_EXPLICIT_FLAG')
    payload=SOURCE.read_bytes();sha=hashlib.sha256(payload).hexdigest()
    root=entry['ssh_root'];target=entry['ssh_target']
    if root!=entry['ssh_root'] or not root.startswith('/home/'+entry['ssh_username']+'/domains/'):
        raise RuntimeError('REMOTE_PATH_OUT_OF_SCOPE')
    path=root+'/wp-content/mu-plugins/319-mad4b-fleet-adaptive-intake.php'
    candidate=path+'.deploy-candidate'
    assert shlex.quote(path)
    pre=run_ssh(target,
        'cd '+shlex.quote(root)+
        ' && test -d wp-content/mu-plugins && wp option get home --skip-plugins --skip-themes')
    if pre!=entry['origin']:raise RuntimeError('REMOTE_WORDPRESS_HOME_MISMATCH')
    live=run_ssh(target,
        'cd '+shlex.quote(root)+' && wp eval '+
        shlex.quote('echo wp_json_encode(array("home"=>home_url(),"env"=>wp_get_environment_type(),"php"=>PHP_VERSION));')+
        ' --skip-themes')
    environment=json.loads(live)
    if environment['env']!=entry['environment']:raise RuntimeError('REMOTE_WP_ENVIRONMENT_MISMATCH')
    existing=run_ssh(target,'if test -e '+shlex.quote(path)+';then sha256sum '+shlex.quote(path)+' | cut -d " " -f1;else echo ABSENT;fi')
    outcome={'contract':'mad4b.fleet-adaptive-intake-deploy-receipt.v1',
      'scope':args.scope,'canonical_origin':entry['origin'],'site_uuid':data['company_site_uuid'],
      'target':target,'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
      'source_sha256':sha,'prior_state':existing,'mode':'DEPLOY' if args.execute else 'AUDIT',
      'secrets_read':False,'production':entry['environment']=='production','automatic_write_authorization':False}
    if not args.execute:
        print(json.dumps({**outcome,'status':'ELIGIBLE_READ_ONLY'}));return
    prior_bytes=None
    if existing not in ('ABSENT',sha):
        prior_receipt=REPORT_DIR/(args.scope.replace(':','-')+'.json')
        if not args.upgrade or not prior_receipt.is_file():
            raise RuntimeError('REMOTE_PLUGIN_DRIFT_REQUIRES_RECONCILIATION')
        old=json.loads(prior_receipt.read_text())
        if old.get('source_sha256')!=existing or old.get('scope')!=args.scope:
            raise RuntimeError('REMOTE_PLUGIN_SHA_UNMATCHED_TO_PREVIOUS_DEPLOY')
        old_cmd=subprocess.run(routing.ssh_cmd(target)+['cat '+shlex.quote(path)],
            capture_output=True,timeout=24)
        if old_cmd.returncode or hashlib.sha256(old_cmd.stdout).hexdigest()!=existing:
            raise RuntimeError('REMOTE_PLUGIN_BACKUP_READBACK_MISMATCH')
        prior_bytes=old_cmd.stdout
        REPORT_DIR.mkdir(parents=True,exist_ok=True)
        backup=REPORT_DIR/(args.scope.replace(':','-')+'-'+existing[:16]+'.php.backup')
        if not backup.exists():
            backup.write_bytes(prior_bytes);os.chmod(backup,0o600)
        outcome['remote_backup']=str(backup)
    if existing!=sha:
        run_ssh(target,
          'test ! -e '+shlex.quote(candidate)+
          ' && umask 077 && cat > '+shlex.quote(candidate),
          timeout=25,input=payload)
        try:
            digest=run_ssh(target,'sha256sum '+shlex.quote(candidate)+' | cut -d " " -f1')
            if digest!=sha:raise RuntimeError('REMOTE_STAGED_SHA_MISMATCH')
            lint=run_ssh(target,'php -l '+shlex.quote(candidate),timeout=18)
            if 'No syntax errors' not in lint:raise RuntimeError('REMOTE_PHP_LINT_FAILED')
            confirm=('test ! -e '+shlex.quote(path)) if existing=='ABSENT' else (
                'test "$(sha256sum '+shlex.quote(path)+' | cut -d " " -f1)" = '+existing)
            run_ssh(target,confirm+
                ' && chmod 640 '+shlex.quote(candidate)+' && mv '+shlex.quote(candidate)+' '+shlex.quote(path))
        except BaseException:
            try:run_ssh(target,'rm -f '+shlex.quote(candidate))
            except Exception:pass
            raise
    bind={'company_id':entry['company_id'],'environment':entry['environment'],
        'origin':entry['origin'],'site_uuid':data['company_site_uuid']}
    encoded=base64.b64encode(json.dumps(bind,separators=(',',':')).encode()).decode()
    php=(
      '$x=json_decode(base64_decode('+json.dumps(encoded)+'),true);'
      '$existing=get_option("mad4b_fleet_adaptive_intake_site_v2",null);'
      'if(home_url()!==$x["origin"]){throw new RuntimeException("HOME_SCOPE_CHANGED");}'
      'if($existing===null){add_option("mad4b_fleet_adaptive_intake_site_v2",$x,"",false);}'
      'elseif($existing!==$x){throw new RuntimeException("SITE_BINDING_COLLISION");}'
      'echo wp_json_encode(array("home"=>home_url(),"env"=>wp_get_environment_type(),'
      '"bound"=>get_option("mad4b_fleet_adaptive_intake_site_v2"),'
      '"module"=>class_exists("MAD4B_Fleet_Adaptive_Intake_V2"),'
      '"plan"=>function_exists("wp_has_ability")&&wp_has_ability("mad4b/fleet-intake-plan"),'
      '"save"=>function_exists("wp_has_ability")&&wp_has_ability("mad4b/fleet-intake-save"),'
      '"status"=>function_exists("wp_has_ability")&&wp_has_ability("mad4b/fleet-intake-status")));'
    )
    try:
        verify=json.loads(run_ssh(target,'cd '+shlex.quote(root)+' && wp eval '+shlex.quote(php)+' --skip-themes',timeout=32))
        if verify['bound']!=bind or not all(verify.get(x) for x in ('module','plan','save','status')):
            raise RuntimeError('REMOTE_MODULE_AND_ABILITIES_READBACK_FAILED')
        outcome['status']='DEPLOYED_AND_VERIFIED'
        outcome['abilities']=['mad4b/fleet-intake-plan','mad4b/fleet-intake-save','mad4b/fleet-intake-status']
    except BaseException:
        if existing=='ABSENT':
            try:run_ssh(target,
                'test "$(sha256sum '+shlex.quote(path)+' | cut -d " " -f1)" = '+sha+
                ' && rm -f '+shlex.quote(path))
            except Exception:pass
        elif prior_bytes is not None:
            # A verified rollback is allowed only if no concurrent writer has
            # changed the deployed candidate after this operation.
            try:
                current=run_ssh(target,
                    'sha256sum '+shlex.quote(path)+' | cut -d " " -f1')
                if current==sha:
                    rollback=path+'.rollback'
                    run_ssh(target,'test ! -e '+shlex.quote(rollback)+
                        ' && umask 077 && cat > '+shlex.quote(rollback),
                        input=prior_bytes)
                    run_ssh(target,'test "$(sha256sum '+shlex.quote(rollback)+
                        ' | cut -d " " -f1)" = '+existing+
                        ' && chmod 640 '+shlex.quote(rollback)+
                        ' && mv '+shlex.quote(rollback)+' '+shlex.quote(path))
            except Exception:pass
        raise
    REPORT_DIR.mkdir(parents=True,exist_ok=True)
    dest=REPORT_DIR/(args.scope.replace(':','-')+'.json')
    fd,temp=tempfile.mkstemp(prefix='.adaptive-deploy-',dir=REPORT_DIR)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(outcome,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(temp,0o600);os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)
    print(json.dumps({'scope':args.scope,'status':outcome['status'],'source_sha256':sha,
        'abilities':outcome['abilities'],'report':str(dest)}))
if __name__=='__main__':main()
