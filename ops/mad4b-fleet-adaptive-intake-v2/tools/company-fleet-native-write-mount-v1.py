#!/usr/bin/env python3
"""Deploy only the reviewed MAD4B exact Adaptive Intake native write catalogue binding."""
import argparse,datetime,hashlib,importlib.util,json,os,shlex,subprocess,tempfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
REPORT=BASE/'reports/fleet-adaptive-native-write-mount-20261108'
CENTRAL=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php')
POLICY=BASE/'policies/company-hostinger-site-binding-map-v1.json'
EXPECTED_REMOTE_SHA='0161dc2a1df70701bb5cc9487359f086c64975195b9c40f2b03c7d77ebc78acb'
def sha(data):return hashlib.sha256(data).hexdigest()
def shell(ssh,cmd,data=None,seconds=24):
 r=subprocess.run(ssh+[cmd],input=data,capture_output=True,timeout=seconds)
 if r.returncode:raise RuntimeError('SSH_STEP_FAILED_'+str(r.returncode)+'_'+r.stderr[-80:].decode(errors='replace'))
 return r.stdout
def route():
 p=BASE/'bin/company-hostinger-two-route-binding-v1.py'
 s=importlib.util.spec_from_file_location('two_route',str(p));m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
 return m
def main():
 ap=argparse.ArgumentParser();ap.add_argument('--scope',required=True);ap.add_argument('--execute',action='store_true');args=ap.parse_args()
 sites=json.loads(POLICY.read_text())['sites']
 entry=next((v for v in sites if v['company_id']+':'+v['environment']==args.scope),None)
 if entry is None or entry['environment']!='staging':raise RuntimeError('ONLY_ENROLLED_STAGING')
 rdata=json.loads((BASE/'reports/company-hostinger-two-route-bindings-current.json').read_text())
 row=rdata['sites'].get(args.scope)
 if not row or not row['ssh_verified'] or row['canonical_origin']!=entry['origin'] or row['company_site_uuid'] is None:raise RuntimeError('SITE_SSH_IDENTITY_NOT_VERIFIED')
 ssh=route().ssh_cmd(entry['ssh_target'])
 root=entry['ssh_root']
 path=root+'/wp-content/plugins/mad4b-site-control-plane/includes/class-mad4b-scp-server-write-catalog.php'
 canonical=shell(ssh,'cd '+shlex.quote(root)+' && wp option get home --skip-plugins --skip-themes').decode().strip()
 if canonical!=entry['origin']:raise RuntimeError('SITE_HOME_MISMATCH')
 data=shell(ssh,'cat '+shlex.quote(path),seconds=28)
 digest=sha(data)
 if digest!=EXPECTED_REMOTE_SHA:raise RuntimeError('REMOTE_SOURCE_DRIFT_'+digest)
 core=CENTRAL.read_text()
 marker='\t\t// MAD4B Fleet Adaptive Intake: exact enrolled original Write abilities.'
 anchor='\t\t// Canonical classification only: the existing Universal VPS queue'
 assert core.count(marker)==1 and core.count(anchor)==1
 block=marker+core.split(marker,1)[1].split(anchor,1)[0]
 old=data.decode()
 if old.count(anchor)!=1 or marker in old:raise RuntimeError('REMOTE_CATALOG_LAYOUT_COLLISION')
 revised=old.replace(anchor,block+anchor).encode()
 receipt={'contract':'mad4b.company-fleet-intake-write-mount.v1','scope':args.scope,
    'site':entry['origin'],'utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
    'before_sha256':digest,'after_sha256':sha(revised),'target':entry['ssh_target'],
    'write_grant_issued':False,'original_operation_executed':False,'mode':'DEPLOY' if args.execute else 'AUDIT'}
 if not args.execute:
  print(json.dumps({**receipt,'status':'CANDIDATE_READY'}));return
 REPORT.mkdir(parents=True,exist_ok=True)
 backup=REPORT/(args.scope.replace(':','-')+'-'+digest[:14]+'.php.before')
 if not backup.exists():
  fd,t=tempfile.mkstemp(prefix='.native-mount-',dir=REPORT)
  with os.fdopen(fd,'wb') as f:f.write(data);f.flush();os.fsync(f.fileno())
  os.chmod(t,0o600);os.replace(t,backup)
 candidate=path+'.native-intake-candidate'
 wrote=False;committed=False
 try:
  shell(ssh,'test ! -e '+shlex.quote(candidate)+' && umask 077 && cat > '+shlex.quote(candidate),revised)
  wrote=True
  readsha=shell(ssh,'sha256sum '+shlex.quote(candidate)+' | cut -d " " -f1').decode().strip()
  if readsha!=sha(revised):raise RuntimeError('REMOTE_CANDIDATE_INTEGRITY_FAILED')
  syntax=shell(ssh,'php -l '+shlex.quote(candidate)).decode()
  if 'No syntax errors detected' not in syntax:raise RuntimeError('REMOTE_CANDIDATE_PHP_LINT_FAILED')
  shell(ssh,'test "$(sha256sum '+shlex.quote(path)+' | cut -d " " -f1)" = '+digest+
        ' && chmod --reference='+shlex.quote(path)+' '+shlex.quote(candidate)+
        ' && mv '+shlex.quote(candidate)+' '+shlex.quote(path))
  committed=True
  script=r'''
$names=array('mad4b/fleet-intake-auto','mad4b/fleet-intake-plan','mad4b/fleet-intake-save','mad4b/fleet-intake-status');
foreach($names as $name){
 $r=MAD4B_SCP_Capability_Descriptor_Registry::describe($name);
 if(is_wp_error($r)) { echo 'ERR:'.$r->get_error_code();exit(2);}
 $lane=$name==='mad4b/fleet-intake-status'?'read':'write';
 if($r['classification']!==$lane || $r['execution_lane']!==$lane || !$r['execution_eligible'])
 {echo 'BAD:'.$name.':'.$r['execution_blocker'];exit(2);}
}
echo wp_json_encode(array('passed'=>true,'write_capabilities'=>3,'read_capabilities'=>1,'enrollment'=>home_url()));
'''
  readback=shell(ssh,'cd '+shlex.quote(root)+' && wp eval '+shlex.quote(script)+' --skip-themes',seconds=36).decode()
  attested=json.loads(readback.strip().splitlines()[-1])
  if not attested.get('passed') or attested['enrollment']!=entry['origin']:raise RuntimeError('REMOTE_RUNTIME_DESCRIPTOR_FAILED')
  receipt.update({'status':'LIVE_VERIFIED','runtime':attested,'backup':str(backup)})
 except BaseException:
  if wrote:
   try:shell(ssh,'rm -f '+shlex.quote(candidate))
   except Exception:pass
  if committed:
   try:
    current=shell(ssh,'sha256sum '+shlex.quote(path)+' | cut -d " " -f1').decode().strip()
    if current==sha(revised):
     rollback=path+'.native-intake-rollback'
     shell(ssh,'test ! -e '+shlex.quote(rollback)+' && umask 077 && cat > '+shlex.quote(rollback),data)
     shell(ssh,'test "$(sha256sum '+shlex.quote(rollback)+' | cut -d " " -f1)" = '+digest+
         ' && chmod --reference='+shlex.quote(path)+' '+shlex.quote(rollback)+
         ' && mv '+shlex.quote(rollback)+' '+shlex.quote(path))
   except Exception:pass
  raise
 dest=REPORT/(args.scope.replace(':','-')+'.json')
 fd,temp=tempfile.mkstemp(prefix='.mount-receipt-',dir=REPORT)
 with os.fdopen(fd,'w') as f:json.dump(receipt,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
 os.chmod(temp,0o600);os.replace(temp,dest)
 print(json.dumps({'status':receipt['status'],'scope':args.scope,
    'sha':receipt['after_sha256'],'receipt':str(dest),'runtime':attested}))
if __name__=='__main__':main()
