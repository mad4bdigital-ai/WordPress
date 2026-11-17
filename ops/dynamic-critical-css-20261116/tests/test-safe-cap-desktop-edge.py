"""Previous Italian LCP failure at 767 plus minor 768 FCP loss remains
eligible for an independent 599 test. Seven-width proof still mandatory."""
import copy,importlib.util,json,pathlib,sys,tempfile
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('fallback',D/'safe-cap-fallback.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
orig=json.load(open('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/20261117T022240Z/verification.json'))
candidate=json.load(open('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/20261117T022240Z/candidates.json'))
state=json.load(open('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/20261117T022240Z/site.json'))
selection=json.load(open('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/20261117T022240Z/selection.json'))
trial=json.load(open('/opt/mad4b-company-provisioning/reports/dynamic-critical-css-20261116/source/safe-599-responsive-experiment-20261117/verification.json'))
n=0
def ok(label,v):
 global n
 assert v,label;n+=1;print('PASS',label)
ok('real Italian 767+768 QA eligible for independently measured 599px',
 m.qualifies_for_independent_599_trial(orig,'it'))
rows=m.safe_candidates(orig,candidate,selection,state)
ok('real Italian source CSS candidate selected without certifying',len(rows)==1 and rows[0]['lang']=='it')
full=copy.deepcopy(trial);full['gates']={'it':full['gates']['it']}
full['results']=[x for x in full['results'] if x['lang']=='it']
ok('seven-width independent Italian result genuinely passing',set(x['width'] for x in full['results'])==m.SAFE and full['gates']['it']['decision']=='passed_evidence')
def merge(primary,trial):
 with tempfile.TemporaryDirectory() as t:
  names=[pathlib.Path(t)/x for x in ('primary.json','trial.json','decision.json')]
  for p,data in zip(names,[primary,trial,{'contract':'mad4b.critical-css.safe-cap-attempt.v1','site':'tours.dev.mad4b.com','languages':['it']}]):
   p.write_text(json.dumps(data))
  return m.merge(*names)
combined,accepted=merge(copy.deepcopy(orig),full)
ok('independent seven-width proof eligible for real admission',accepted==['it'] and combined['gates']['it']['adaptiveMaxWidth']==599 and len(combined['results'])==7)
bad=copy.deepcopy(orig)
next(x for x in bad['results'] if x['width']==768)['fcpGain']=-600
ok('severe desktop FCP loss refuses fallback trial',not m.qualifies_for_independent_599_trial(bad,'it'))
bad=copy.deepcopy(orig)
next(x for x in bad['results'] if x['width']==768)['variant']['errors']=['broken']
ok('interaction failure at desktop not smuggled into CSS fallback',not m.qualifies_for_independent_599_trial(bad,'it'))
bad=copy.deepcopy(orig)
next(x for x in bad['results'] if x['width']==390)['pass']=False
ok('independent mobile failure blocks fallback',not m.qualifies_for_independent_599_trial(bad,'it'))
badfull=copy.deepcopy(full)
next(x for x in badfull['results'] if x['width']==600)['pass']=False
_,accepted=merge(copy.deepcopy(orig),badfull)
ok('failed 599->600 transition never issues CSS certificate',accepted==[])
print(f'PASS {n}/{n} seven-width Italian fallback admission safety invariants')
