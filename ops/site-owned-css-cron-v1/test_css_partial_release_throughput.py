#!/usr/bin/env python3
"""Isolated original 422 partial-release and source-observer priority regression.

No CSS issuance, WordPress access, browser, network, or production write.
"""
import ast,contextlib,importlib.util,io,json,pathlib,tempfile,time,types
from unittest.mock import patch
HERE=pathlib.Path(__file__).resolve().parent
RENDERER=HERE.parent/'dynamic-critical-css-20261116'
count=0

def ok(name,condition):
 global count
 assert condition,name
 count+=1
 print('PASS',name)

def module_from_path(path,name):
 spec=importlib.util.spec_from_file_location(name,path)
 mod=importlib.util.module_from_spec(spec)
 spec.loader.exec_module(mod)
 return mod

publisher=module_from_path(RENDERER/'bulk-publish.py','css_test_bulk_publisher')
source=ast.parse((HERE/'worker.py').read_text())
consumer=next(x for x in source.body if isinstance(x,ast.FunctionDef) and x.name=='verified_task_outcome')
sc={'json':json};exec(compile(ast.Module(body=[consumer],type_ignores=[]),str(HERE/'worker.py'),'exec'),sc)
checker=sc['verified_task_outcome']

for site in ('source','wayfa'):
 with tempfile.TemporaryDirectory() as tmp:
  root=pathlib.Path(tmp);(root/site).mkdir()
  keys=('a'*64,'b'*64)
  plan={'site':site,'host':{'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}[site],
   'policy':{'max_parallel_browser_on_host':1},'ready_for_public_release':0,
   'waves':[{'routes':[{'route_key':x} for x in keys]}],
   'eligible_for_source_preflight':2}
  with (patch.object(publisher.planner,'write_plan',lambda s:(plan,'unused')),
        patch.object(publisher.qa,'pending_rows',lambda s:[]),
        patch.object(publisher,'history',lambda r,s:({},root/'attempts.json',0)),
        patch.object(publisher,'recertification_debt_route',lambda *a,**kw:None),
        patch.object(publisher.qa,'ROOT',root)):
   for issued in (False,True):
    record=root/'attempts.json'
    if record.exists():record.unlink()
    executed=[]
    def fake_run(argv,**kwargs):
     key=argv[-1];executed.append(key)
     if issued and len(executed)==1:
      return types.SimpleNamespace(stdout=f'BULK_CCSS_RELEASED {site} {key} en /test/ POST_RELEASE_QA_QUEUED\n',stderr='',returncode=0)
     return types.SimpleNamespace(stdout=f'DEFER_PUBLIC_CCSS_RESOURCE_WINDOW {site} cpu_headroom_insufficient\n',stderr='',returncode=0)
    out=io.StringIO()
    with patch.object(publisher.subprocess,'run',fake_run),contextlib.redirect_stdout(out):
     publisher.run(site)
    result=out.getvalue()
    if issued:
     ok(site+' exact source released once',len(executed)==2 and 'released 1 attempted 1' in result)
     ok(site+' partial source progress ACKs original ticket',checker(site,'bulk_publish',0,result,'')==(True,'verified_bounded_bulk_publication'))
     ok(site+' deferral does not consume next source cursor',set(json.loads(record.read_text())['routes'])=={keys[0]} and json.loads(record.read_text())['next_index']==1)
     ok(site+' release-only marker isolates legitimate subsequent resource defer','BULK_CCSS_PUBLISH_NEXT_ROUTE_DEFER' in result and 'BULK_CCSS_PUBLISH_RESOURCE_DEFER' not in result)
    else:
     ok(site+' zero-release resource defer preserves WP ticket',checker(site,'bulk_publish',0,result,'')==(False,'host_resource_deferred'))
     ok(site+' zero-release resource defer preserves cursor',not record.exists())

# Isolate the actual native source main branch without invoking the site worker.
fn=next(x for x in source.body if isinstance(x,ast.FunctionDef) and x.name=='main')
block=next(s for s in fn.body if isinstance(s,ast.If) and "SITE" in ast.unparse(s.test) and "source" in ast.unparse(s.test))
events=[]
now=int(time.time());state={'pending':now+1500}
sc={'SITE':'source','sys':types.SimpleNamespace(argv=['worker.py']),
    'history':{},'now':now,
    'observe_source_home_without_browser':lambda:events.append('home'),
    'observe_source_css_profiles':lambda:events.append('profiles'),
    'original_qa_lease_deadline':lambda *a:state['pending'],
    'source_browser_evidence_refresh':lambda *a:events.append('optional_chrome') or False}
exec(compile(ast.Module(body=[block],type_ignores=[]),'isolated_source_main','exec'),sc)
ok('source native QA suppresses optional browser observer','optional_chrome' not in events and events==['home','profiles'])
events.clear();state['pending']=None
exec(compile(ast.Module(body=[block],type_ignores=[]),'isolated_source_main','exec'),sc)
ok('source optional observer resumes after QA lease','optional_chrome' in events)
print('PASS',count,'/',count,'CSS throughput release invariants')
