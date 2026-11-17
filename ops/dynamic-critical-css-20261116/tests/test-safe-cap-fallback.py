import hashlib,importlib.util,json,sys,copy,tempfile
from pathlib import Path
d=Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('safecap',d/'safe-cap-fallback.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
sha=lambda t:hashlib.sha256(t.encode()).hexdigest()
widths=[320,390,414,767,768]
row={'lang':'de','route':'/de/','critical_file':sha('crit')+'.css',
     'critical_sha':sha('crit'),'critical_bytes':60000,'source_sha':sha('src')}
source={'contract':'mad4b.critical-css.site-source.v1',
        'site_host':'tours.dev.mad4b.com','environment':'staging',
        'native_breakpoint':767,'css_epoch':129,'critical_css':'on'}
inventory={'contract':'mad4b.mobile-critical.candidates.v1',
           'host':'tours.dev.mad4b.com','candidates':[row],'errors':[]}
selection={'site':'source','routes':[{'locale':'de','route':'/de/',
                                    'css_sha':row['source_sha'],'status':'needs_native_browser_qa'}]}
def primary_case(w):
 r={'lang':'de','width':w,'pass':w!=767,'fcpGain':2000,'lcpReg':7000 if w==767 else -10,
    'baseline':{'lcp':3700,'status':200},
    'variant':{'status':200,'rewritten':True,'errors':[],'failedCss':[]}}
 return r
primary={'contract':'mad4b.mobile-critical.qa.v1',
         'gates':{'de':{'decision':'hold','widths':widths,'adaptiveMaxWidth':767}},
         'results':[primary_case(w) for w in widths],'tested':5}
cases=[]
def ok(label,cond):
 assert cond,label
 cases.append(label);print('PASS',label)
ok('only LCP 767 regression selected for adaptive trial',
   [r['lang'] for r in m.safe_candidates(primary,inventory,selection,source)]==['de'])
modified=copy.deepcopy(primary)
modified['results'][0]['pass']=False
ok('other failing viewport blocks safe-cap fallback',
   not m.safe_candidates(modified,inventory,selection,source))
modified=copy.deepcopy(primary)
modified['results'][3]['lcpReg']=16
ok('non-LCP regression does not trigger fallback',
   not m.safe_candidates(modified,inventory,selection,source))
try:m.safe_candidates(primary,inventory,selection,dict(source,site_host='wayfatours.com'))
except ValueError:ok('other company cannot trigger source fallback',True)
else:raise AssertionError('cross-company source candidate accepted')
decision={'contract':'mad4b.critical-css.safe-cap-attempt.v1',
          'site':'tours.dev.mad4b.com','languages':['de']}
safe={320,390,414,599,600,767,768}
trial={'contract':'mad4b.mobile-critical.qa.v1',
       'gates':{'de':{'decision':'passed_evidence','widths':sorted(safe),
                      'adaptiveMaxWidth':599}},
       'results':[{'lang':'de','width':w,'pass':True,'fcpGain':2400 if w<=599 else -40,
                   'lcpReg':100} for w in sorted(safe)],
       'tested':7}
def merge_records(primary,trial,decision):
 with tempfile.TemporaryDirectory() as t:
  t=Path(t)
  for name,obj in [('source',primary),('trial',trial),('decision',decision)]:
   (t/(name+'.json')).write_text(json.dumps(obj))
  return m.merge(t/'source.json',t/'trial.json',t/'decision.json')
combined,accepted=merge_records(primary,trial,decision)
ok('all seven-width passing replacements supersede only failed locale',
   accepted==['de'] and combined['gates']['de']['adaptiveMaxWidth']==599
   and len(combined['results'])==7)
modified=copy.deepcopy(trial)
modified['results'].pop(4)
combined,accepted=merge_records(primary,modified,decision)
ok('missing 600px proof never activates narrower cap',not accepted)
modified=copy.deepcopy(trial)
modified['results'][5]['pass']=False
combined,accepted=merge_records(primary,modified,decision)
ok('new failing 767px edge blocks narrower cap',not accepted)
try:merge_records(primary,trial,{**decision,'site':'wayfatours.com'})
except ValueError:ok('cross-company admission impossible',True)
else:raise AssertionError('wrong site accepted')
print('PASS %d/%d safe-cap automatic source QA retry invariants'%(len(cases),len(cases)))
