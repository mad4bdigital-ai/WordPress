#!/usr/bin/env python3
"""Stage and atomically apply the audited rc124 Control Plane to one bound Staging site.

Does NOT change WordPress users, grants, permissions, production, or issue 313 receipts.
"""
from __future__ import annotations
import argparse,hashlib,importlib.util,json,os,shlex,subprocess,sys,tempfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning')
BUILDDIR=BASE/'releases/fleet-adaptive-native-write-rc124'
REPORTS=BASE/'reports/fleet-intake-rc124-staging-rollout-20261108'
POLICY=BASE/'policies/company-hostinger-site-binding-map-v1.json'
ROUTING=BASE/'reports/company-hostinger-two-route-bindings-current.json'
EXPECTED_OLD_SHA='62f06b0b4d55b024d7c74736977f552281a6cf6c'
EXPECTED_DELTA={}
PRESERVE={
 'config/functional-gap-contract-evidence.generated.json',
 'data/company-communication-page-schema.json',
 'data/qa28-staging-context-fixtures.json',
 'docs/RUNTIME-CACHE-CONVERGENCE.md'
}
def sha(data):return hashlib.sha256(data).hexdigest()
def invoke(cmd,timeout=60,input=None):
 p=subprocess.run(cmd,input=input,capture_output=True,timeout=timeout)
 if p.returncode:raise RuntimeError('REMOTE_OPERATION_FAILED rc='+str(p.returncode)+' tail='+p.stderr[-150:].decode(errors='replace'))
 return p.stdout.decode(errors='replace')
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('--scope',required=True)
 ap.add_argument('--phase',choices=('plan','stage','apply'),default='plan')
 args=ap.parse_args()
 policy=json.loads(POLICY.read_text())['sites']
 row=next((x for x in policy if x['company_id']+':'+x['environment']==args.scope),None)
 if not row or row['environment']!='staging':raise RuntimeError('STAGING_ENROLLMENT_REQUIRED')
 enrolled=json.loads(ROUTING.read_text())['sites'].get(args.scope)
 if not enrolled or not enrolled['ssh_verified'] or enrolled['canonical_origin']!=row['origin'] or not enrolled['company_site_uuid']:
  raise RuntimeError('SITE_UUID_OR_PINNED_SSH_MISMATCH')
 spec=importlib.util.spec_from_file_location('router',str(BASE/'bin/company-hostinger-two-route-binding-v1.py'))
 router=importlib.util.module_from_spec(spec);spec.loader.exec_module(router)
 ssh=router.ssh_cmd(row['ssh_target'])
 user,hostname,port=router.HOSTS[row['ssh_target']]
 profile=Path('/opt/hostinger-shell-mcp/external-targets')/row['ssh_target']
 build=json.loads((BUILDDIR/'candidate-build-receipt.json').read_text())
 source=BUILDDIR/('mad4b-site-control-plane-'+build['source_commit_sha']+'.zip')
 assert source.is_file() and sha(source.read_bytes())==build['archive_sha256']
 assert build['state']=='CANDIDATE_QA_PASSED_NOT_DEPLOYED' and build['file_count']==946
 home=invoke(ssh+['cd '+shlex.quote(row['ssh_root'])+' && wp option get home --skip-plugins --skip-themes'],timeout=30).strip()
 if home!=row['origin']:raise RuntimeError('CANONICAL_HOME_CHANGED')
 candidate=Path('/home/'+user+'/.mad4b-recovery/.fleet-rc124-incoming-'+build['archive_sha256'][:16]+'.zip')
 args_json={'scope':args.scope,'state':'PLANNED','utc':__import__('datetime').datetime.now(__import__('datetime').timezone.utc).isoformat(),
  'home':home,'uuid':enrolled['company_site_uuid'],'source_sha':build['source_commit_sha'],
  'archive_sha':build['archive_sha256'],'manifest_digest':build['manifest_digest'],
  'production_mutation':False,'grant_created':False}
 REPORTS.mkdir(parents=True,exist_ok=True)
 pre=invoke(ssh+['test -d '+shlex.quote(str(candidate.parent))+
  ' && test "$(stat -c %d '+shlex.quote(row['ssh_root']+'/wp-content/plugins')+')" = "$(stat -c %d '+shlex.quote(str(candidate.parent))+')" && echo STORAGE_OK'])
 if 'STORAGE_OK' not in pre:raise RuntimeError('PRIVATE_ROLLBACK_DEVICE_MISMATCH')
 if args.phase=='plan':
  print(json.dumps(args_json|{'state':'VERIFIED_PLAN','next_step':'stage'}));return
 if args.phase=='stage':
  check=invoke(ssh+['if test -e '+shlex.quote(str(candidate))+';then sha256sum '+shlex.quote(str(candidate))+" | cut -d ' ' -f1;else echo ABSENT;fi"],timeout=20).strip()
  if check=='ABSENT':
   scp=['scp','-B','-P',port,'-o','BatchMode=yes','-o','IdentitiesOnly=yes','-o','StrictHostKeyChecking=yes',
        '-o','UserKnownHostsFile='+str(profile/'known_hosts_verified'),'-i',str(profile/'id_ed25519'),
        str(source),user+'@'+hostname+':'+str(candidate)]
   invoke(scp,timeout=125)
  elif check!=build['archive_sha256']:raise RuntimeError('REMOTE_INCOMING_ARCHIVE_COLLISION')
  verified=invoke(ssh+['sha256sum '+shlex.quote(str(candidate))+" | cut -d ' ' -f1"],timeout=27).strip()
  if verified!=build['archive_sha256']:raise RuntimeError('REMOTE_ARCHIVE_SHA_UNVERIFIED')
  print(json.dumps(args_json|{'state':'ARCHIVE_STAGED_VERIFIED','remote_archive':str(candidate)}));return
 if args.phase!='apply':raise RuntimeError('INVALID_PHASE')
 script=REMOTE_APPLY
 params=[row['ssh_root'],str(candidate),build['archive_sha256'],str(enrolled['company_site_uuid']),
   row['origin'],build['source_commit_sha'],build['manifest_digest'],
   json.dumps(EXPECTED_DELTA),json.dumps(sorted(PRESERVE))]
 command='python3 -c '+shlex.quote(script)+' '+' '.join(shlex.quote(p) for p in params)
 raw=invoke(ssh+[command],timeout=155)
 parsed=[]
 for line in raw.splitlines():
  if line.startswith('MAD4B_RC124_RELEASE:'):
   parsed.append(json.loads(line.split(':',1)[1]))
 if len(parsed)!=1:raise RuntimeError('REMOTE_DEPLOYMENT_RECEIPT_NOT_RETURNED')
 result=parsed[0]
 output=args_json|result
 fd,t=tempfile.mkstemp(dir=REPORTS,prefix='.rc124-rollout-')
 with os.fdopen(fd,'w') as f:json.dump(output,f,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
 os.chmod(t,0o600)
 dest=REPORTS/(args.scope.replace(':','-')+'.json')
 os.replace(t,dest)
 print(json.dumps(output|{'report':str(dest)}))
 if result['state']!='RC124_LIVE_VERIFIED':raise RuntimeError(result['state']+':'+result.get('reason',''))
REMOTE_APPLY=r'''
import sys,json,zipfile,hashlib,os,shutil,subprocess,stat
from pathlib import Path
root=Path(sys.argv[1]);candidate=Path(sys.argv[2]);checksum=sys.argv[3]
uuid=sys.argv[4];origin=sys.argv[5];source_sha=sys.argv[6];manifest_sha=sys.argv[7]
expected=json.loads(sys.argv[8]);preserve=set(json.loads(sys.argv[9]))
slug='mad4b-site-control-plane'
base=root/'wp-content/plugins'
current=base/slug
recovery=Path.home()/'.mad4b-recovery'
stage=recovery/('.rc124-stage-'+checksum[:16])
backup=recovery/('control-plane-before-rc124-'+checksum[:16])
failed=recovery/('.rc124-rejected-'+checksum[:16])
old_moved=False;new_placed=False
def fail(x):raise RuntimeError(x)
def digest(p):
 h=hashlib.sha256()
 with p.open('rb') as f:
  for b in iter(lambda:f.read(1024*1024),b''):h.update(b)
 return h.hexdigest()
try:
 if not all(x.is_dir() and not x.is_symlink() for x in (root,base,current,recovery)):
  fail('LIVE_OR_RECOVERY_DIRECTORY_INVALID')
 if any(x.exists() or x.is_symlink() for x in (stage,backup,failed)):
  fail('RELEASE_BACKUP_OR_STAGE_COLLISION')
 if os.stat(base).st_dev!=os.stat(recovery).st_dev:
  fail('NON_ATOMIC_FILESYSTEM_REJECTED')
 if not candidate.is_file() or candidate.is_symlink() or digest(candidate)!=checksum:
  fail('ARCHIVE_SHA_MISMATCH')
 older=json.loads((current/'MAD4B-BUILD-PROVENANCE.json').read_text())
 if older.get('source_commit_sha')!='62f06b0b4d55b024d7c74736977f552281a6cf6c' or older.get('control_plane_version')!='0.4.0-rc.123':
  fail('UNEXPECTED_INSTALLED_RC122_IDENTITY')
 managed=set(e['path'] for e in older['package_files'])
 managed.add('MAD4B-BUILD-PROVENANCE.json')
 observed=[]
 for e in older['package_files']:
  rel=e['path'];f=current/rel
  if not f.is_file() or f.is_symlink():fail('OLD_PACKAGE_FILE_MISSING_'+rel)
  got=digest(f);need=expected.get(rel,e['sha256'])
  if got!=need:fail('OLD_LIVE_DRIFT_'+rel)
 for path in current.rglob('*'):
  if path.is_symlink():fail('LIVE_SYMLINK_REJECTED')
  if path.is_file():
   rel=path.relative_to(current).as_posix()
   if rel not in managed:observed.append(rel)
 if not set(observed).issubset(preserve):fail('UNKNOWN_SITE_FILE_PRESERVATION_REQUIRED')
 stage.mkdir(mode=0o700)
 staged=stage/slug
 staged.mkdir(mode=0o755)
 with zipfile.ZipFile(candidate) as z:
  entries=z.infolist()
  if len(entries)!=946 or z.testzip() is not None:fail('ARCHIVE_INTEGRITY_FAILED')
  names=set()
  for i in entries:
   if i.is_dir():continue
   parts=Path(i.filename).parts
   if len(parts)<2 or parts[0]!=slug or any(t in ('','.','..') for t in parts):
    fail('ZIP_PATH_ESCAPE')
   if stat.S_ISLNK((i.external_attr>>16)&0o177777):fail('ZIP_SYMLINK_REJECTED')
   rel=Path(*parts[1:]);name=rel.as_posix()
   if name in names:fail('DUPLICATE_ZIP_MEMBER')
   names.add(name)
   path=staged/rel
   path.parent.mkdir(parents=True,exist_ok=True)
   with z.open(i) as h,path.open('xb') as out:
    shutil.copyfileobj(h,out)
   os.chmod(path,0o644)
 metadata=json.loads((staged/'MAD4B-BUILD-PROVENANCE.json').read_text())
 if metadata.get('source_commit_sha')!=source_sha or metadata.get('control_plane_version')!='0.4.0-rc.124' or metadata.get('package_manifest_digest')!=manifest_sha:
  fail('CANDIDATE_PROVENANCE_IDENTITY_MISMATCH')
 lines=[]
 for e in metadata['package_files']:
  rel=e['path'];f=staged/rel
  if not f.is_file() or f.stat().st_size!=e['bytes'] or digest(f)!=e['sha256']:
   fail('NEW_MANIFEST_FILE_MISMATCH_'+rel)
  lines.append(rel+'\0'+str(e['bytes'])+'\0'+e['sha256']+'\n')
 if hashlib.sha256(''.join(sorted(lines)).encode()).hexdigest()!=manifest_sha:
  fail('CANDIDATE_CANONICAL_MANIFEST_DIGEST_MISMATCH')
 for rel in observed:
  old=current/rel;new=staged/rel
  if new.exists() or old.stat().st_size>1048576:fail('SITE_SPECIFIC_FILE_COLLISION')
  new.parent.mkdir(parents=True,exist_ok=True)
  shutil.copy2(old,new)
 for rel in ['mad4b-site-control-plane.php','includes/class-mad4b-scp-server-write-catalog.php',
             'includes/class-mad4b-scp-cli-execution-context.php']:
  result=subprocess.run(['php','-l',str(staged/rel)],capture_output=True,text=True,timeout=20)
  if result.returncode:fail('PHP_LINT_FAILED_'+rel)
 old_moved=True
 os.replace(current,backup)
 os.replace(staged,current)
 new_placed=True
 code=r"""$s=MAD4B_SCP_Site_Profile::status();$p=MAD4B_SCP_Live_Acceptance_Observer::build_provenance_status();$d=MAD4B_SCP_Capability_Descriptor_Registry::describe('mad4b/fleet-intake-save');
echo 'RC124_RESULT:'.wp_json_encode(array('version'=>defined('MAD4B_SCP_VERSION')?MAD4B_SCP_VERSION:null,
'origin'=>untrailingslashit(home_url('/')),'uuid'=>$s['site_uuid']??null,'env'=>wp_get_environment_type(),
'manifest_ready'=>!empty($p['runtime_manifest_match'])&&!empty($p['manifest_valid'])&&!empty($p['source_commit_sha']),
'source_sha'=>$p['source_commit_sha']??null,'intake_write'=>is_array($d)&&!empty($d['execution_eligible'])&&($d['execution_lane']??'')==='write'));"""
 run=subprocess.run(['wp','--path='+str(root),'--skip-themes','eval',code],capture_output=True,text=True,timeout=42)
 readings=[x.split('RC124_RESULT:',1)[1] for x in run.stdout.splitlines() if x.startswith('RC124_RESULT:')]
 if run.returncode or len(readings)!=1:fail('POST_RELEASE_WORDPRESS_BOOT_FAILED')
 read=json.loads(readings[0])
 if read.get('version')!='0.4.0-rc.124' or read.get('origin')!=origin or read.get('uuid')!=uuid or read.get('env')!='staging' or read.get('manifest_ready') is not True or read.get('source_sha')!=source_sha or read.get('intake_write') is not True:
  fail('POST_RELEASE_PROVENANCE_OR_SITE_READBACK_FAILED')
 print('MAD4B_RC124_RELEASE:'+json.dumps({
  'state':'RC124_LIVE_VERIFIED','site':origin,'version':read['version'],
  'source_commit':source_sha,'manifest_verified':True,'intake_write_lane_ready':True,
  'preserved_site_files':observed,'backup_path':str(backup),
  'production_mutation':False,'grant_created':False,'original_content_mutation':False,
  'native_313_receipt_created':False
 },sort_keys=True))
except Exception as e:
 state='RC124_PREFLIGHT_REJECTED'
 if old_moved:
  try:
   if new_placed and current.exists():os.replace(current,failed)
   if backup.exists():os.replace(backup,current)
   state='RC124_REJECTED_ROLLED_BACK' if current.is_dir() else 'RC124_ROLLBACK_FAILED'
  except Exception:state='RC124_ROLLBACK_FAILED'
 print('MAD4B_RC124_RELEASE:'+json.dumps({'state':state,'reason':str(e)[:130],
  'rollback_restored':current.is_dir(),'grant_created':False,'production_mutation':False}))
finally:
 if stage.is_dir():shutil.rmtree(stage)
'''
if __name__=='__main__':main()
