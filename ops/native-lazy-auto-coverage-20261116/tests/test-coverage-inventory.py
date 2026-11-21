from pathlib import Path
import subprocess,tempfile,json,sys,collections
base=Path(sys.argv[1]);fixtures=base/'evidence'
with tempfile.TemporaryDirectory() as tmp:
 output=Path(tmp)/'matrix.json'
 p=subprocess.run(['python3',str(base/'classify-coverage.py'),
  str(fixtures/'observed-source-manifest.json'),str(fixtures/'observed-ssr-390-1920.jsonl'),str(output)],
  capture_output=True,text=True)
 assert p.returncode==0,p.stderr
 doc=json.loads(output.read_text())
 assert doc['discovered_count']==12
 assert doc['language_count']==6
 assert len(doc['configured_currencies'])==4
 assert doc['statuses']=={'native_ssr_low_volume':8,'conditional_not_rendered':1,
     'certified_active':2,'requires_native_signed_ajax_evidence':1},doc['statuses']
 assert len(doc['queue'])==1
 c=doc['queue'][0]
 assert c['locale']=='it' and c['market']=='de-usd'
 assert c['widget_id'] and c['query_id']>0 and c['listing_id']>0
 assert set(doc['configured_but_unobserved_currencies'])=={'EUR','EGP','GBP'}
 assert doc['has_written_certificates'] is False
 # Increasing a low-volume language to 100 cards does not automatically grant a cert.
 manifest=json.loads((fixtures/'observed-source-manifest.json').read_text())
 target=next(x for x in manifest['candidates'] if x['locale']=='fr' and x['widget_id']=='78d62e0')
 target['runtime_admitted']=False
 m=Path(tmp)/'source.json';m.write_text(json.dumps(manifest))
 rows=[]
 for line in (fixtures/'observed-ssr-390-1920.jsonl').read_text().splitlines():
  if not line.startswith('SSR_RESULT '):rows.append(line);continue
  x=json.loads(line[11:])
  if x['locale']=='fr' and x['widget']=='78d62e0':x['count']=100
  rows.append('SSR_RESULT '+json.dumps(x))
 s=Path(tmp)/'rows.jsonl';s.write_text('\n'.join(rows)+'\n')
 p=subprocess.run(['python3',str(base/'classify-coverage.py'),str(m),str(s),str(output)],capture_output=True,text=True)
 assert p.returncode==0,p.stderr
 doc=json.loads(output.read_text())
 assert any(x['locale']=='fr' for x in doc['queue'])
 assert all(x['market']=='de-usd' for x in doc['queue'])
 assert doc['has_written_certificates'] is False
 # A previously enrolled but invalidated source is not a new listing.
 changed=json.loads((fixtures/'observed-source-manifest.json').read_text())
 for item in changed['candidates']:
  if item['locale'] in ('en','es') and item['widget_id']=='78d62e0':
   item['runtime_admitted']=False
   item['status']='pass'
   item['version_match']=False
 changed_file=Path(tmp)/'changed.json'
 changed_file.write_text(json.dumps(changed))
 result=subprocess.run(['python3',str(base/'classify-coverage.py'),str(changed_file),
   str(fixtures/'observed-ssr-390-1920.jsonl'),str(output)],capture_output=True,text=True)
 assert result.returncode==0,result.stderr
 revised=json.loads(output.read_text())
 assert revised['statuses']['recertify_changed_query_source']==2
 assert [x['locale'] for x in revised['queue']]==['en','es','it']
 assert [x['priority'] for x in revised['queue']]==[0,0,1]
 assert revised['has_written_certificates'] is False
print('PASS 15/15 adaptive native lazy coverage: discovery, volume veto, market isolation and queue-only transitions')
