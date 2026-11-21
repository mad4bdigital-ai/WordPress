#!/usr/bin/env python3
"""Read-only company-bound MAD4B Git-to-mounted-WordPress code drift report."""
import argparse
import collections
import datetime as dt
import hashlib
import json
import os
import pathlib
import subprocess
import sys
import tempfile

SOURCE=pathlib.Path('/opt/mad4b-company-provisioning/reports/source-unification-20261119/single-source-reconcile-20261121')
OUT=pathlib.Path('/opt/mad4b-company-provisioning/reports/workspace-lifecycle-20261121/live-source-parity')
SITES={
 'toursdev_staging':('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy','php-wkgtkzta35wkkgouwt6ruljc'),
 'wayfatours_production':('/opt/wayfa-rebuild-20261102/site-copy','php-guxhp9y6vgh7oouuw8plxrlr')
}
def run(*cmd):
 p=subprocess.run(cmd,capture_output=True,timeout=45)
 if p.returncode:raise RuntimeError('read-only command failed: '+str(cmd[:2])+' '+p.stderr[-180:].decode('utf-8','replace'))
 return p.stdout
def sha(path):
 d=hashlib.sha256()
 with path.open('rb') as f:
  for chunk in iter(lambda:f.read(262144),b''):d.update(chunk)
 return d.hexdigest()
def classified(path):
 pieces=pathlib.PurePosixPath(path).parts
 return 'development_or_qa' if any(x in ('tests','test','docs','node_modules','vendor','ops') for x in pieces) or path.endswith(('.md','.map','.ts')) else 'runtime_candidate'
def select(paths):
 selected=[]
 for name in paths:
  parts=pathlib.PurePosixPath(name).parts
  if len(parts)>=4 and parts[:2]==('wp-content','plugins') and parts[2].startswith('mad4b-'):
   selected.append(name)
 return selected
def audit_paths(source,live,paths):
 summaries=collections.defaultdict(lambda:collections.Counter())
 mismatches=[];source_only=[];identical=0
 for rel in paths:
  parts=pathlib.PurePosixPath(rel).parts
  plugin=parts[2];category=classified(rel)
  s=source/rel;l=live/rel
  if not s.is_file() and not s.is_symlink():raise RuntimeError('source git checkout missing '+rel)
  if not l.is_file() and not l.is_symlink():
   summaries[plugin]['source_only']+=1
   if len(source_only)<100:source_only.append({'path':rel,'role':category})
  elif s.is_symlink() or l.is_symlink():
   summaries[plugin]['unresolved_symlink']+=1
   if len(mismatches)<1500:mismatches.append({'path':rel,'role':category,'reason':'symlink'})
  elif s.stat().st_size==l.stat().st_size and sha(s)==sha(l):
   identical+=1;summaries[plugin]['exact']+=1
  else:
   summaries[plugin]['different']+=1
   summaries[plugin]['different_'+category]+=1
   if len(mismatches)<1500:mismatches.append({'path':rel,'role':category,'source_sha256':sha(s),'installed_sha256':sha(l)})
 return {'plugin_counts':{name:dict(stats) for name,stats in sorted(summaries.items())},'exact':identical,
         'different':sum(v['different'] for v in summaries.values()),'source_only':sum(v['source_only'] for v in summaries.values()),
         'mismatches':mismatches,'source_only_samples':source_only}
def verify_mount(path,container):
 running=run('docker','inspect','-f','{{.State.Running}}',container).decode().strip()
 if running!='true':raise RuntimeError('site PHP container not running: '+container)
 mounts=json.loads(run('docker','inspect','-f','{{json .Mounts}}',container))
 bound=[m.get('Source') for m in mounts if m.get('Destination')=='/var/www/html']
 if bound!=[str(path)]:raise RuntimeError('site PHP container mount does not match intended source: '+container)
def report():
 if not SOURCE.is_dir():raise RuntimeError('Missing source worktree')
 if run('git','-C',str(SOURCE),'status','--porcelain'):raise RuntimeError('Candidate Git source is dirty')
 head=run('git','-C',str(SOURCE),'rev-parse','HEAD').decode().strip()
 tree=run('git','-C',str(SOURCE),'rev-parse','HEAD^{tree}').decode().strip()
 names=[os.fsdecode(p) for p in run('git','-C',str(SOURCE),'ls-files','-z').split(b'\0') if p]
 selected=select(names)
 output={'as_of':dt.datetime.now(dt.timezone.utc).isoformat(),'source_head':head,'source_tree':tree,'mode':'READ_ONLY_NO_PROMOTION','tracked_plugin_files':len(selected),'sites':{}}
 for site,(sitepath,container) in SITES.items():
  actual=pathlib.Path(sitepath)
  if not actual.is_dir():raise RuntimeError('Missing mounted PHP path: '+site)
  verify_mount(actual,container)
  output['sites'][site]=audit_paths(SOURCE,actual,selected)
  output['sites'][site]['mounted_php_container']=container
 return output
def atomic_json(path,obj):
 path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
 temp=path.with_suffix('.tmp')
 fd=os.open(temp,os.O_CREAT|os.O_WRONLY|os.O_TRUNC,0o600)
 with os.fdopen(fd,'w') as f:
  json.dump(obj,f,indent=2,ensure_ascii=False);f.write('\n');f.flush();os.fsync(f.fileno())
 os.replace(temp,path)
def selftest():
 with tempfile.TemporaryDirectory(prefix='mad4b-parity-test-') as name:
  root=pathlib.Path(name)
  s=root/'src';l=root/'live'
  for p in (s,l): (p/'wp-content/plugins/mad4b-test').mkdir(parents=True)
  for p in (s,l):
   (p/'wp-content/plugins/mad4b-test/same.php').write_text('same')
  (s/'wp-content/plugins/mad4b-test/different.php').write_text('new')
  (l/'wp-content/plugins/mad4b-test/different.php').write_text('old')
  (s/'wp-content/plugins/mad4b-test/missing.php').write_text('source only')
  p=['wp-content/plugins/mad4b-test/'+x for x in ('same.php','different.php','missing.php')]
  x=audit_paths(s,l,p)
  assert x['exact']==1 and x['different']==1 and x['source_only']==1
  assert x['plugin_counts']['mad4b-test']['different_runtime_candidate']==1
  print('PASS parity exact/different/source-only and nonmutating file comparisons')
def main():
 ap=argparse.ArgumentParser()
 ap.add_argument('--selftest',action='store_true')
 ap.add_argument('--run',action='store_true')
 a=ap.parse_args()
 if a.selftest:selftest();return
 if not a.run:ap.error('Use --run or --selftest')
 report_data=report()
 OUT.mkdir(parents=True,exist_ok=True,mode=0o700)
 latest=OUT/'latest.json'
 atomic_json(latest,report_data)
 today=dt.datetime.now(dt.timezone.utc).strftime('%Y%m%dT%H%M%SZ')
 atomic_json(OUT/('source-parity-'+today+'.json'),report_data)
 b=OUT/'baseline.json'
 if not b.is_file():
  atomic_json(b,{'as_of':report_data['as_of'],'source_head':report_data['source_head'],
                 'counts':{name:{k:r[k] for k in ('exact','different','source_only')} for name,r in report_data['sites'].items()}})
 print(json.dumps({'head':report_data['source_head'],'sites':{name:{k:v[k] for k in ('exact','different','source_only')} for name,v in report_data['sites'].items()},'writes_to_sites':False}))
if __name__=='__main__':main()
