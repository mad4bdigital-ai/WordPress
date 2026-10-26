#!/usr/bin/env python3
"""MAD4B Source Hub: run every approved site-bound source via bundled worker.
Run using an authorized host scheduler; never modifies WordPress directly.
"""
import argparse,json,pathlib,subprocess,sys
p=argparse.ArgumentParser();p.add_argument('--vault',required=True);a=p.parse_args()
root=pathlib.Path(a.vault).resolve(strict=True)
manifest=json.loads((root/'manifest.json').read_text())
if manifest.get('contract')!='mad4b.source-hub-worker-manifest.v1' or not manifest.get('site_uuid'):raise SystemExit('Invalid source manifest')
sources=manifest.get('sources',{})
if sources==[]:sources={}
if not isinstance(sources,dict) or len(sources)>60:raise SystemExit('Source list invalid')
worker=pathlib.Path(__file__).with_name('source-hub-worker.py')
errors=[];succeeded=0
for source_id in sources:
 result=subprocess.run([sys.executable,str(worker),'--vault',str(root),'--source',source_id],capture_output=True,text=True,timeout=55)
 if result.returncode:errors.append({'source_id':source_id,'failure':result.stderr[-200:] or result.stdout[-200:]})
 else:succeeded+=1
print(json.dumps({'contract':'mad4b.source-hub-batch.v1','eligible':len(sources),'fetched':succeeded,'errors':errors,
 'wordpress_mutations':0,'published_content':0}))
if errors:sys.exit(2)
