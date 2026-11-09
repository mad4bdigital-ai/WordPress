#!/usr/bin/env python3
"""Canonical format full package candidate: rc123 -> rc124 exact Staging grant policy."""
import datetime,hashlib,json,os,shutil,subprocess,zipfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-provisioning/releases')
PRIOR=BASE/'fleet-adaptive-native-write-rc123'
OUT=BASE/'fleet-adaptive-native-write-rc124'
SRC=OUT/'source/mad4b-site-control-plane'
FILE='includes/class-mad4b-scp-staging-write-grant-reconciliation.php'
PINS={
 'mad4b/firecrawl-research-capture':'core',
 'mad4b/fleet-intake-auto':'core',
 'mad4b/fleet-intake-plan':'core',
 'mad4b/fleet-intake-save':'core'
}
def hashb(x):return hashlib.sha256(x).hexdigest()
def git(*args,env=None):
 p=subprocess.run(['git','-C',str(OUT/'source'),*args],capture_output=True,text=True,env=env,timeout=30,check=True)
 return p.stdout.strip()
def main():
 if OUT.exists():raise RuntimeError('CANDIDATE_DIRECTORY_COLLISION')
 manifest=json.loads((PRIOR/'source/mad4b-site-control-plane/MAD4B-BUILD-PROVENANCE.json').read_text())
 assert manifest['control_plane_version']=='0.4.0-rc.123'
 assert len(manifest['package_files'])==945
 shutil.copytree(PRIOR/'source',OUT/'source',ignore=shutil.ignore_patterns('.git'))
 (SRC/'MAD4B-BUILD-PROVENANCE.json').unlink()
 p=SRC/FILE;s=p.read_text()
 needle="\t\t\t\t'mad4b/hub-source-sync-request' => 'core',"
 assert s.count(needle)==1
 for name in PINS:
  assert "'"+name+"' =>" not in s
 addition="\n\t\t\t\t// Exact reviewed Staging abilities. Inclusion only makes a future\n\t\t\t\t// plan eligible; it never creates a grant or bypasses owner approval.\n"
 for name,provider in PINS.items():
  addition+="\t\t\t\t'"+name+"' => '"+provider+"',\n"
 s=s.replace(needle,needle+addition)
 p.write_text(s)
 for name in ('mad4b-site-control-plane.php','MAD4B-RUNTIME-BUILD.txt','README.md'):
  f=SRC/name;b=f.read_bytes()
  if b'0.4.0-rc.123' not in b:raise RuntimeError('VERSION_ANCHOR_MISSING_'+name)
  f.write_bytes(b.replace(b'0.4.0-rc.123',b'0.4.0-rc.124'))
 git('init','-q')
 git('add','mad4b-site-control-plane')
 env={**os.environ,'GIT_AUTHOR_NAME':'MAD4B Package Builder','GIT_AUTHOR_EMAIL':'builder@mad4b.local',
  'GIT_COMMITTER_NAME':'MAD4B Package Builder','GIT_COMMITTER_EMAIL':'builder@mad4b.local',
  'GIT_AUTHOR_DATE':'2026-11-08T21:50:00Z','GIT_COMMITTER_DATE':'2026-11-08T21:50:00Z'}
 git('commit','-q','-m','build(mad4b): rc124 exact Staging grant policy and original intake',env=env)
 source_sha=git('rev-parse','HEAD')
 entries=[]
 for f in sorted(x for x in SRC.rglob('*') if x.is_file()):
  blob=f.read_bytes();entries.append({'path':f.relative_to(SRC).as_posix(),'bytes':len(blob),'sha256':hashb(blob)})
 assert len(entries)==945
 lines=[e['path']+'\0'+str(e['bytes'])+'\0'+e['sha256']+'\n' for e in entries]
 digest=hashb(''.join(sorted(lines)).encode())
 m=dict(manifest)
 workflow='MAD4B Fleet Authority Reconciliation Builder (canonical format)'
 m.update({'source_commit_sha':source_sha,'control_plane_version':'0.4.0-rc.124',
  'artifact_identity':'mad4b-site-control-plane-0.4.0-rc.124-'+source_sha,
  'canonical_archive_identity':'mad4b-site-control-plane-0.4.0-rc.124-'+source_sha,
  'build_fingerprint':hashb((source_sha+'\n'+digest+'\n'+workflow+'\n').encode()),
  'package_manifest_digest':digest,'package_files':entries,'manifest_file_count':945,
  'archive_file_count':946,'build_workflow':workflow,'build_run_id':''})
 (SRC/'MAD4B-BUILD-PROVENANCE.json').write_text(json.dumps(m,indent=2,sort_keys=True)+'\n')
 zipf=OUT/('mad4b-site-control-plane-'+source_sha+'.zip')
 with zipfile.ZipFile(zipf,'w',compression=zipfile.ZIP_STORED) as z:
  for f in sorted(x for x in SRC.rglob('*') if x.is_file()):
   name='mad4b-site-control-plane/'+f.relative_to(SRC).as_posix()
   zi=zipfile.ZipInfo(name,(1980,1,1,0,0,0));zi.compress_type=zipfile.ZIP_STORED;zi.external_attr=0o100644<<16
   z.writestr(zi,f.read_bytes())
 with zipfile.ZipFile(zipf) as z:
  assert z.testzip() is None and len(z.namelist())==946
  for e in entries:assert hashb(z.read('mad4b-site-control-plane/'+e['path']))==e['sha256']
 receipt={'contract':'mad4b.fleet-intake-authority-candidate.v1','utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
  'source_sha':source_sha,'build_fingerprint':m['build_fingerprint'],'manifest_digest':digest,
  'archive_sha256':hashb(zipf.read_bytes()),'archive_path':str(zipf),
  'authority_allowlist_exact_staging_pairs':PINS,
  'artifact_count':946,'state':'CANDIDATE_BUILT_NOT_DEPLOYED','grants_created':0,'production_changed':False}
 (OUT/'candidate-build-receipt.json').write_text(json.dumps(receipt,indent=2)+'\n')
 print(json.dumps(receipt))
if __name__=='__main__':main()
