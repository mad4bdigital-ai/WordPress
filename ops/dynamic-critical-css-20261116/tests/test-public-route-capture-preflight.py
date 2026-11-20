"""Source-backed route QA eligibility: stable source vs uncertain CSS identity."""
import pathlib,json,copy,importlib.util,sys
D=pathlib.Path(sys.argv[1]);P=pathlib.Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
spec=importlib.util.spec_from_file_location('safe',D/'public-route-capture-preflight.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
count=0
def ok(label,result):
 global count
 assert result,label
 count+=1;print('PASS',label)
def case(where):
 f=P/where
 return tuple(json.loads((f/(name+'.json')).read_text()) for name in ('capture-index','candidates','route'))
a,b,c=case('source/20261117T051005Z')
ok('passing German public route qualifies for real five-width QA',m.examine(a,b,c)['decision']=='admit_qa')
a2,b2,c2=case('source/20261117T042611Z')
ok('non-certifiable WordPress posts index needs no repeated Chromium',m.examine(a2,b2,c2)['reason']=='market_context_unavailable')
a3,b3,c3=case('wayfa/20261117T051806Z')
result=m.examine(a3,b3,c3)
ok('real Wayfatours post changes four distinct CSS contents',result['reason']=='unstable_css_source' and result['source_shas']==4)
a4,b4,c4=case('wayfa/20261117T043344Z')
ok('WPML blog archive has no server market headers',m.examine(a4,b4,c4)['reason']=='market_context_unavailable')
bad=copy.deepcopy(a);bad['scenarios'][0]['width']=390
try:m.examine(bad,b,c)
except ValueError:ok('reused viewport cannot fake independent four-width proof',True)
else:raise AssertionError
bad=copy.deepcopy(a);bad['scenarios'][0]['dom_sha']='f'*64
ok('different Elementor document graph stops before QA',m.examine(bad,b,c)['reason']=='unstable_elementor_graph')
bad=copy.deepcopy(a);bad['scenarios'][0]['resolved_currency']='EUR'
ok('mixed WCML currencies stop before QA',m.examine(bad,b,c)['reason']=='unstable_market_currency')
bad=copy.deepcopy(b);bad['candidates'][0]['source_sha']='f'*64
ok('fabricated published candidate hash cannot pass preflight',m.examine(a,bad,c)['reason']=='candidate_incomplete')
bad=copy.deepcopy(a);bad['scenarios'][0]['uri']='/fake/'
try:m.examine(bad,b,c)
except ValueError:ok('cross-route captured HTML cannot pass proof',True)
else:raise AssertionError
bad=copy.deepcopy(a);bad['scenarios'][0]['error']='Some other network failure'
ok('untrusted browser network errors not mistaken for market contract',m.examine(bad,b,c)['reason']=='capture_error')
print(f'PASS {count}/{count} original public route stability preflight invariants')
