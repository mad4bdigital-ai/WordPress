import hashlib,importlib.util,sys
from pathlib import Path
d=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('coverage_queue',d/'coverage-queue.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sha=lambda x:hashlib.sha256(x.encode()).hexdigest()
now=1794860000
site={'site_host':'tours.dev.mad4b.com','environment':'staging','css_epoch':127,
      'wpml_languages':['en','es','de','fr','ar','it'],'default_language':'en'}
selection={'contract':'mad4b.critical-css.dynamic-selection.v1','site':'source',
 'css_epoch':127,'routes':[
 {'locale':l,'route':'/' if l=='en' else '/'+l+'/',
  'status':'certified_active' if l in ['en','es'] else 'needs_native_browser_qa',
  'css_sha':sha(l)}
 for l in site['wpml_languages']]}
checks=[]
def check(title,condition):
 assert condition,title
 checks.append(title);print('PASS',title)
history=m.load_history(Path('/tmp/does-not-exist-mad4b-wpml-20261117'),site)
check('new active WPML locales first',m.select(selection,site,history,now)==['de','fr'])
qa={'gates':{'de':{'decision':'hold','widths':[320,390,414,767,768]},
             'fr':{'decision':'passed_evidence','widths':[320,390,414,767,768]}},
 'results':[{'lang':l,'width':w,'pass':l=='fr'}
            for l in ['de','fr'] for w in [320,390,414,767,768]]}
m.record(selection,site,history,['de','fr'],qa,now)
check('a failed locale has a bounded cooldown',history['attempts']['de']['retry_after']==now+900)
check('QA-pass locale retains immediate eligibility',history['attempts']['fr']['retry_after']==now)
check('never starves untouched Arabic and Italian',m.select(selection,site,history,now+1)==['ar','it'])
m.record(selection,site,history,['ar','it'],None,now+1,'browser_capture_failure')
check('capture failure enters cooldown',history['attempts']['ar']['retry_after']==now+901)
check('held German does not block successful French',m.select(selection,site,history,now+2)==['fr'])
m.record(selection,site,history,['fr'],{'gates':{'fr':{'decision':'hold','widths':[]}},'results':[]},now+2)
check('no busy-loop when all missing locales are in backoff',m.select(selection,site,history,now+3)==[])
check('failed German is automatically retried after expiry',m.select(selection,site,history,now+902)==['de','ar'])
changed={**selection,'css_epoch':128,'routes':[dict(r,css_sha=sha('new'+r['locale'])) for r in selection['routes']]}
new_site={**site,'css_epoch':128}
check('new CSS SHA/epoch resets prior failure cooldown',m.select(changed,new_site,history,now+3)==['de','fr'])
future={**site,'wpml_languages':site['wpml_languages']+['nl']}
future_s={**selection,'routes':selection['routes']+[{'locale':'nl','route':'/nl/','status':'needs_native_browser_qa','css_sha':sha('nl')}]}
check('future WPML language discovered automatically',m.select(future_s,future,history,now+3)==['nl'])
different_default={**site,'default_language':'fr'}
different_routes={**selection,'routes':[dict(r,route='/' if r['locale']=='fr' else '/en/' if r['locale']=='en' else r['route']) for r in selection['routes']]}
check('default-language change follows current WPML',m.select(different_routes,different_default,history,now+3)==[])
for title,bad_site,bad_sel in [
 ('foreign site history rejected',{**site,'site_host':'other.site'},selection),
 ('duplicate WPML entry rejected',{**site,'wpml_languages':site['wpml_languages']+['fr']},selection),
 ('unsupported locale format rejected',{**site,'wpml_languages':site['wpml_languages']+['pt-br']},selection),
 ('unexpected WPML route rejected',site,{**selection,'routes':[dict(r,route='/wrong/') if r['locale']=='fr' else r for r in selection['routes']]})]:
 try:
  if 'history' in title:m.load_history(Path('/tmp/does-not-exist-mad4b-wpml-20261117'),bad_site)
  m.assert_contract(bad_sel,bad_site)
  if 'history' in title:
   data={'contract':'mad4b.critical-css.wpml-coverage.v1','host':'tours.dev.mad4b.com','attempts':{}}
   import tempfile,json
   with tempfile.TemporaryDirectory() as t:
    p=Path(t)/'history.json';p.write_text(json.dumps(data))
    m.load_history(p,bad_site)
 except (ValueError,AssertionError):check(title,True)
 else:raise AssertionError(title)
print('PASS %d/%d WPML dynamic fair-retry invariants'%(len(checks),len(checks)))
