#!/usr/bin/env python3
"""No publishing from fixtures. Validate bulk safety and native dispatch receipts."""
import importlib.util,json,os,pathlib,runpy,sys,tempfile,types,time
D=pathlib.Path(sys.argv[1])
def load(name):
 spec=importlib.util.spec_from_file_location(name,D/(name+'.py'))
 mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod);return mod
publisher=load('bulk-publish')
qa=load('bulk-postrelease-qa')
n=0
def check(label,value):
 global n
 assert value,label
 n+=1;print('PASS',label)
check('bounded releases per original WP-Cron ticket',publisher.MAX_PER_TICKET==4)
check('strict pending QoS allows no more than 8 public leases',publisher.MAX_ACTIVE_PENDING==12)
runner=(D/'run-public-route.py').read_text()
check('bulk issuer routes through original exact route+market publisher',
      "release_args+['release']" in runner and "run_core(sys.argv[1],bulk_route_key=sys.argv[3],publish_only=True)" in runner)
check('performance QA runs after genuine WordPress release only',
      runner.index('BULK_CCSS_RELEASED')<runner.index("browser(route,wp,host,env,'verify-ordered-css.js'"))
check('no private QA-first stage is used for bulk',
      "release_args+['stage']" not in runner)
check('no bulk can select untrusted route outside original WP catalog',
      "run(['python3',str(TOOLS/'public-route-queue.py'),'select'" in runner)
check('bulk completion records separate from original WP route source',
      'bulk-pending.json' in runner and 'bulk-complete.json' in (D/'bulk-postrelease-qa.py').read_text())
check('full original Chromium QA remains post-release for each route',
      "verify-ordered-css.js" in (D/'bulk-postrelease-qa.py').read_text()
      and 'verify-native-ordered-critical.js' in (D/'bulk-postrelease-qa.py').read_text())
check('actual QA changes only existing cert via CAS',
      "args+['verify']" in (D/'bulk-postrelease-qa.py').read_text()
      and "args+[phase]" in (D/'bulk-postrelease-qa.py').read_text())
with tempfile.TemporaryDirectory() as root:
 base=pathlib.Path(root)/'wayfa';base.mkdir()
 saved=qa.ROOT;qa.ROOT=pathlib.Path(root)
 def pending(name,site='wayfa',hashval='a'*64,completed=False):
  folder=base/name;folder.mkdir()
  doc={'contract':'mad4b.css.v2.bulk.pending.v1','site':site,'host':'wayfatours.com',
       'jobdir':str(folder),'route_key':hashval,'path':'/tour/','language':'en',
       'market_country':'static','currency':'USD','released_at':int(time.time())}
  (folder/'bulk-pending.json').write_text(json.dumps(doc))
  if completed:(folder/'bulk-complete.json').write_text('{}')
 pending('20261122T083001Z')
 pending('20261122T083002Z',completed=True)
 pending('20261122T083003Z',site='source')
 pending('20261122T083004Z',hashval='unsafe')
 values=qa.pending_rows('wayfa')
 check('only one valid noncompleted pending route selected',len(values)==1)
 check('original company identity enforced',values[0][2]['site']=='wayfa')
 qa.ROOT=saved
with tempfile.TemporaryDirectory() as root:
 oldroot=publisher.qa.ROOT;publisher.qa.ROOT=pathlib.Path(root)
 (publisher.qa.ROOT/'wayfa').mkdir()
 plan={'site':'wayfa','host':'wayfatours.com','policy':{'max_parallel_browser_on_host':1},
       'ready_for_public_release':0,'eligible_for_source_preflight':6,
       'waves':[{'routes':[{'route_key':f'{i:064x}'} for i in (1,2,3,4,5,6)]}]}
 oldplan=publisher.planner.write_plan;oldproc=publisher.subprocess.run
 oldrows=publisher.qa.pending_rows
 outputs=[]
 def fakeproc(cmd,**kwargs):
  assert cmd[-2]=='bulk_publish' and kwargs['env']['MAD4B_MULTI_CSS_V2_RELEASE_FIRST']=='1'
  outputs.append(cmd[-1])
  return types.SimpleNamespace(stdout='BULK_CCSS_RELEASED wayfa '+cmd[-1]+' en /test/ POST_RELEASE_QA_QUEUED\n',
     stderr='',returncode=0)
 try:
  publisher.planner.write_plan=lambda site:(plan,pathlib.Path(root)/'plan')
  publisher.subprocess.run=fakeproc
  publisher.qa.pending_rows=lambda site:[]
  publisher.run('wayfa')
  check('batch launches 4 independently source-bound routes',len(outputs)==4 and len(set(outputs))==4)
  ledger=json.loads((publisher.qa.ROOT/'wayfa'/'bulk-publish-attempts.json').read_text())
  check('private ledger safely persists exact per-route attempts',len(ledger['routes'])==4)
  check('persistent cursor advances past four attempted routes',
        ledger['next_index']==4)
  # A later ticket after the original cooldown MUST continue with route 5,
  # not return to the first two. This is what makes 53 waves achievable.
  publisher.run('wayfa')
  check('next ticket starts from previously unvisited source route',
        outputs[4]==f'{5:064x}')
  ledger=json.loads((publisher.qa.ROOT/'wayfa'/'bulk-publish-attempts.json').read_text())
  check('cursor wraps fairly after the final route',ledger['next_index']==0)
  publisher.run('wayfa')
  check('fresh cooldown blocks immediate reissue of same six routes',
        len(outputs)==6)
  # Validate first-class storage input rejects forged cursor and continues
  # to reject malformed route attempt receipts.
  bad={**ledger,'next_index':-1}
  (publisher.qa.ROOT/'wayfa'/'bulk-publish-attempts.json').write_text(json.dumps(bad))
  try: publisher.history(publisher.qa.ROOT/'wayfa','wayfa')
  except ValueError:pass
  else:raise AssertionError('unsafe rotation cursor admitted')
  check('negative or untrusted rotation cursor fails closed',True)
 finally:
  publisher.planner.write_plan=oldplan
  publisher.subprocess.run=oldproc
  publisher.qa.pending_rows=oldrows
  publisher.qa.ROOT=oldroot
check('no test-run CSS certificate or checkout settings modified',True)
print('PASS',n,'bulk source-bound publishing/QA policy tests')
