import hashlib,importlib.util,sys
from pathlib import Path
d=Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('admission',d/'qa-admission.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
h=lambda x:hashlib.sha256(x.encode()).hexdigest()
valid={320,390,414,767,768}
rows=[{'lang':l,'source_sha':h(l),'critical_sha':h('crit'+l),
       'route':'/'+l+'/','widths':[320,390,414,768]} for l in ['fr','de']]
inventory={'contract':'mad4b.mobile-critical.candidates.v1','host':'tours.dev.mad4b.com','errors':[],'candidates':rows}
selection={'contract':'mad4b.critical-css.dynamic-selection.v1','site':'source','routes':[
 {'locale':l,'css_sha':h(l),'route':'/'+l+'/','status':'needs_native_browser_qa'} for l in ['fr','de']]}
qa={'contract':'mad4b.mobile-critical.qa.v1','tested':10,
 'gates':{'fr':{'decision':'hold','widths':sorted(valid)},
          'de':{'decision':'passed_evidence','widths':sorted(valid)}},
 'results':[{'lang':l,'width':w,'pass':l=='de','fcpGain':200,'lcpReg':0}
            for l in ['fr','de'] for w in sorted(valid)]}
checks=[]
def check(label,result):
 assert result,label
 checks.append(label);print('PASS',label)
report,subset=m.evaluate('source',['fr','de'],inventory,qa,selection)
check('separate successful German from failed French',report['accepted']==['de'] and [x['locale'] for x in report['held']]==['fr'])
check('actual v2 browser source candidates pruned to accepted',len(subset['candidates'])==1 and subset['candidates'][0]['lang']=='de')
bad={**qa,'gates':{'fr':{'decision':'hold','widths':sorted(valid)},
                  'de':{'decision':'hold','widths':sorted(valid)}}}
report,_=m.evaluate('source',['fr','de'],inventory,bad,selection)
check('never admit all-failed batch',not report['accepted'])
q2={**qa,'results':[r for r in qa['results'] if not(r['lang']=='de' and r['width']==767)]}
report,_=m.evaluate('source',['fr','de'],inventory,q2,selection)
check('missing 767px denies German certificate',not report['accepted'])
q3={**qa,'results':[dict(r,pass_=False) if r['lang']=='de' and r['width']==320 else r for r in qa['results']]}
q3['results']=[dict(r,**{'pass':False}) if r['lang']=='de' and r['width']==320 else r for r in qa['results']]
report,_=m.evaluate('source',['fr','de'],inventory,q3,selection)
check('single failed viewport denies entire language',not report['accepted'])
changed={**selection,'routes':[dict(r,css_sha=h('changed')) if r['locale']=='de' else r for r in selection['routes']]}
changed_report,_=m.evaluate('source',['fr','de'],inventory,qa,changed)
check('new CSS SHA invalidates only the affected language',
      not changed_report['accepted'] and any(x['locale']=='de' and x['decision']=='hold_source_sha_or_route_drift' for x in changed_report['held']))
changed_qa={**qa,'gates':{**qa['gates'],'fr':{'decision':'passed_evidence','widths':sorted(valid)}},
 'results':[dict(r,**{'pass':True}) if r['lang']=='fr' else r for r in qa['results']]}
changed_report,changed_subset=m.evaluate('source',['fr','de'],inventory,changed_qa,changed)
check('failing independent source drift cannot block a valid sibling',
      changed_report['accepted']==['fr'] and len(changed_subset['candidates'])==1)

viewports={320,390,414,768}
wc={'contract':'mad4b.mobile-critical.candidates.v1','host':'wayfatours.com',
    'errors':[],'candidates':[{**r,'widths':sorted(viewports)} for r in rows]}
ws={'contract':'mad4b.critical-css.dynamic-selection.v1','site':'wayfa',
    'routes':[dict(x) for x in selection['routes']]}
wq={'contract':'mad4b.mobile-critical.qa.v1','tested':8,
    'gates':{l:{'decision':'passed_evidence','widths':sorted(viewports)} for l in ['fr','de']},
    'results':[{'lang':l,'width':w,'pass':True,'fcpGain':200,'lcpReg':-1}
               for l in ['fr','de'] for w in sorted(viewports)]}
report,_=m.evaluate('wayfa',['fr','de'],wc,wq,ws)
check('Wayfa keeps each native four-viewport contract',len(report['accepted'])==2)
# 767px capture is neither required nor safe to infer; only measured QA
# may add the extra device breakpoint to its performance proof.
wrongcap={**inventory,'candidates':[dict(r,widths=[320,390,414,767,768]) for r in rows]}
wrongreport,_=m.evaluate('source',['fr','de'],wrongcap,qa,selection)
check('incorrect five-width capture contract rejected',
      not wrongreport['accepted'] and len(wrongreport['held'])==2)
check('Wayfa capture and QA both use four widths',report['expected_viewports']==[320,390,414,768])

# Source 599px mobile safety fallback: every width must be measured, and
# the existing 767/768px Elementor edge still needs a passing result.
full={320,390,414,599,600,767,768}
row599=[{'lang':'de','source_sha':h('de'),
         'critical_sha':h('critde'),'route':'/de/','widths':[320,390,414,768]}]
inv599={**inventory,'candidates':row599}
sel599={**selection,'routes':[dict(r) for r in selection['routes'] if r['locale']=='de']}
qa599={'contract':'mad4b.mobile-critical.qa.v1',
    'gates':{'de':{'decision':'passed_evidence','widths':sorted(full),
                  'adaptiveMaxWidth':599}},
    'results':[{'lang':'de','width':w,'pass':True,'fcpGain':2200 if w<=599 else -60,
                'lcpReg':100} for w in sorted(full)]}
report,subset=m.evaluate('source',['de'],inv599,qa599,sel599)
check('source accepts measured seven-viewport 599px proof',
      report['accepted']==['de'] and len(subset['candidates'])==1)
bad599={**qa599,'results':[v for v in qa599['results'] if v['width']!=600]}
report,_=m.evaluate('source',['de'],inv599,bad599,sel599)
check('source rejects 599px fallback with missing 600px evidence',not report['accepted'])
bad599={**qa599,'results':[dict(v,**{'pass':False}) if v['width']==767 else v for v in qa599['results']]}
report,_=m.evaluate('source',['de'],inv599,bad599,sel599)
check('source rejects 599px fallback if native Elementor edge regresses',not report['accepted'])
print('PASS %d/%d independent locale QA admission invariants'%(len(checks),len(checks)))
