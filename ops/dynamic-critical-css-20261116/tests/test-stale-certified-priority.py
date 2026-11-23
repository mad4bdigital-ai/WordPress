#!/usr/bin/env python3
"""Original 421-source-bounded stale certificate scheduling, no WP mutations."""
import importlib.util,json,pathlib,hashlib,sys,tempfile,time,types
p=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('css_v2_stale_source_priority',p/'bulk-publish.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
n=0
def ok(label,passed):
 global n
 assert passed,label
 n+=1;print('PASS',label)
now=int(time.time())
site='wayfa';host='wayfatours.com'
def route(i,**args):
 return {'route_key':f'{i:064x}','language':'en','path':f'/tours/test-{i}/',
   'type':'tours-and-activities','revision':args.get('revision',0),
   'source_profile':args.get('source_profile','tour:en'),
   'source_graph_sha256':'d'*64}
rows=[route(i) for i in (1,2,3,4,5,6)]
plan={'site':site,'host':host,'global_generation':8,
      'listing_generation':105,'css_epoch':26,
      'runtime_sha':'a'*64,'consumer_v2_sha':'b'*64,
      'policy':{'max_parallel_browser_on_host':1},
      'ready_for_public_release':0,'eligible_for_source_preflight':6,
      'waves':[{'routes':rows}]}
with tempfile.TemporaryDirectory() as temp:
 wp=pathlib.Path(temp)/'wp';certs=wp/'mad4b-public-critical-css-v1/certs-v2'
 certs.mkdir(parents=True,mode=0o755)
 def make(i,*,status='certified',market='base',currency='USD',
          gen=103,globalgen=8,epoch=26,runtime='a'*64,consumer='b'*64,
          object_id=5, revision=0,dynamic=True):
  r=rows[i-1]
  doc={'contract':'mad4b.public-critical-css.route-certificate.v2',
       'status':status,'host':host,'object_id':object_id,'language':r['language'],
       'path':r['path'],'type':r['type'],'market_country':market,'currency':currency,
       'runtime_sha':runtime,'consumer_sha':consumer,'css_epoch':epoch,
       'dependencies':{'global_generation':globalgen,'route_generation':revision,
          'listing_generation':gen,'contains_dynamic_listing':dynamic},
       'evidence':{'decision':'passed_evidence','tested':5}}
  key=hashlib.sha256('|'.join(str(doc[x]) for x in (
       'host','language','path','market_country','currency')).encode()).hexdigest()
  f=certs/(key+'.json');f.write_text(json.dumps(doc,sort_keys=True));f.chmod(0o644)
  return f,doc
 def pick(*,attempts=None,pending=None,when=now,plan_override=None,site_override=site):
  return m.recertification_debt_route(site_override,plan if plan_override is None else plan_override,
       rows,{} if attempts is None else attempts,
       set() if pending is None else pending,when,wp)
 ok('no source certified proof yields no stale reissue',pick() is None)
 f1,c1=make(1)
 got=pick()
 ok('listing generation source drift schedules stale certified route as a fresh attempt',
    got is not None and got[0]['route_key']==rows[0]['route_key'] and got[1]==('listing',))
 ok('certified previous source remains read-only and byte-for-byte untouched',
    json.loads(f1.read_text())==c1)
 ok('30m stale route retry does not override hard 2h repair cooldown',
    pick(attempts={rows[0]['route_key']:now-1800}) is None)
 ok('old stale source gets a fair repair attempt after cooldown',
    pick(attempts={rows[0]['route_key']:now-7200}) is not None)
 ok('original WordPress QA ownership blocks any duplicate active route release',
    pick(pending={rows[0]['route_key']}) is None)
 f1.unlink()
 make(1,gen=105)
 ok('exact matching original listing generation is never classified as stale',
    pick() is None)
 f1.unlink()
 make(1,status='released_pending_qa',gen=102)
 ok('already provisional public V2 cannot be misclassified as stale certified',
    pick() is None)
 f1.unlink()
 f2,d2=make(2,globalgen=7,gen=105)
 got=pick()
 ok('global CSS change can schedule source recertification without making old cert Ready',
    got is not None and got[0]['route_key']==rows[1]['route_key'] and got[1]==('global',))
 f2.unlink()
 make(2,gen=103,globalgen=7,epoch=25,runtime='f'*64,consumer='d'*64)
 got=pick()
 ok('multiple independently changed source/runtime/CSS/422 generations are explicitly recorded as debt',
    got is not None and {'global','listing','runtime','consumer','epoch'}==set(got[1]))
 bad=pick(plan_override={**plan,'host':'other.company'})
 ok('different company site never authorizes stale source repair',bad is None)
 bad=pick(site_override='source')
 ok('source company cannot inherit Wayfa certificate routes',bad is None)
 for f in certs.glob('*.json'):
  data=json.loads(f.read_text());data['market_country']='DE'
  f.write_text(json.dumps(data))
 ok('market-inconsistent source certificate file SHA rejected',pick() is None)
 for f in certs.glob('*.json'):f.unlink()
 f1,c1=make(1,gen=103)
 f1.rename(certs/('f'*64+'.json'))
 ok('misnamed untrusted 422 certificate rejected',pick() is None)
 for f in certs.glob('*.json'):f.unlink()
 f1,c1=make(1,gen=103)
 f1.unlink();f1.symlink_to('/etc/passwd')
 ok('symlink certificate cannot enter repair ranking',pick() is None)
 f1.unlink()
 f1,c1=make(1,gen=103)
 f1.chmod(0o666)
 ok('world writable CSS source certificate cannot enter repair ranking',pick() is None)
 f1.chmod(0o644)
 f1.unlink()
 make(1,gen=103);make(2,gen=103,globalgen=7)
 got=pick(attempts={rows[0]['route_key']:now-6000})
 ok('repair selection skips recent attempts, preserves other eligible verified source',
    got and got[0]['route_key']==rows[1]['route_key'])
 # Full original publisher dispatcher: one admitted repair must NOT skip
 # normal page 3-6 or rewrite the native cursor using debt selection.
 oldroot=m.qa.ROOT;oldplan=m.planner.write_plan
 oldpending=m.qa.pending_rows;oldrun=m.subprocess.run
 oldselector=m.recertification_debt_route
 try:
  m.qa.ROOT=pathlib.Path(temp)/'reports'
  (m.qa.ROOT/'wayfa').mkdir(parents=True)
  m.planner.write_plan=lambda _: (plan,m.qa.ROOT/'plan.json')
  m.qa.pending_rows=lambda _: []
  m.recertification_debt_route=lambda *a,**kw:(rows[1],('listing',))
  outputs=[]
  def original_capture(args,**kw):
   assert args[-2]=='bulk_publish'
   assert kw['env']['MAD4B_MULTI_CSS_V2_RELEASE_FIRST']=='1'
   outputs.append(args[-1])
   return types.SimpleNamespace(
    stdout='BULK_CCSS_RELEASED wayfa '+args[-1]+' en /safe/ POST_RELEASE_QA_QUEUED\n',
    stderr='',returncode=0)
  m.subprocess.run=original_capture
  m.run('wayfa')
  ok('one reissue starts the 2-route original production-limited ticket',
      len(outputs)==2 and outputs[0]==rows[1]['route_key'])
  ok('one original family-ordered fresh route follows stale repair',
      outputs[1:]==[rows[0]['route_key']])
  ledger=json.loads((m.qa.ROOT/'wayfa/bulk-publish-attempts.json').read_text())
  ok('stale retry does not consume old coverage cursor prior to ordinary routes',
      ledger['next_index']==1)
  ok('all accepted routes remain original WordPress plan route hashes only',
      set(outputs).issubset({r['route_key'] for r in rows}))
 finally:
  m.qa.ROOT=oldroot;m.planner.write_plan=oldplan
  m.qa.pending_rows=oldpending;m.subprocess.run=oldrun
  m.recertification_debt_route=oldselector
print('PASS',n,'strict old-certified source reissue scheduling + route fairness fixtures')
