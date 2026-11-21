"""Pure acceptance tests of original source owned-browser evidence decisions."""
import ast,time
from pathlib import Path
candidate=Path(__file__).resolve().parents[1]/'site-owned-source-wpcron-worker.py'
source=ast.parse(candidate.read_text())
# Import only the two pure admission functions without loading or mutating
# the live site-owner profile, renderer, WordPress, or Cron.
needed={'source_browser_evidence_due','source_browser_evidence_resource_ok',
        'source_browser_evidence_checkpoint'}
nodes=[x for x in source.body if isinstance(x,ast.FunctionDef) and x.name in needed]
assert len(nodes)==3
m={'_SOURCE_OBSERVER_KEY':'source:original_browser_evidence_refresh',
   '_SOURCE_OBSERVER_LANGS':('en','es','de','fr','ar','it')}
exec(compile(ast.Module(body=nodes,type_ignores=[]),str(candidate),'exec'),m)
due=m['source_browser_evidence_due']
resources=m['source_browser_evidence_resource_ok']
checkpoint=m['source_browser_evidence_checkpoint']
now=int(time.time())
base={'contract':'mad4b.source-css-profile-variance.v1',
      'host':'tours.dev.mad4b.com',
      'checked_at':now,
      'browser_profiles_missing_or_expired':['en','es','de','fr','ar','it']}
history={}
assert due(base,history,now)==(True,'browser_evidence_expired')
print('PASS stale six-locale proof renewed without new CSS cert')
assert due({**base,'browser_profiles_missing_or_expired':[]},history,now)==(False,'existing_browser_proof_fresh')
print('PASS existing fresh proof not recaptured')
assert due(base,{'source:original_browser_evidence_refresh':{'next_attempt':now+2700}},now)==(False,'readback_cooldown')
print('PASS 45-minute successful cooldown')
fresh={**base,'browser_profiles_missing_or_expired':[]}
assert due(fresh,{'source:original_browser_evidence_refresh':
                 {'last_completed':now-2700,'next_attempt':now-5}},now)==(
                 True,'proactive_browser_proof_renewal')
assert due(fresh,{'source:original_browser_evidence_refresh':
                 {'last_completed':now-2600,'next_attempt':now-5}},now)==(
                 False,'existing_browser_proof_fresh')
print('PASS proactive refresh before 90-minute expiry, no premature Chrome')
assert due({**base,'host':'wayfatours.com'},history,now)==(False,'foreign_variance')
print('PASS exact site host admission')
assert due({**base,'checked_at':now-901},history,now)==(False,'stale_variance')
print('PASS no stale source report becomes proof')
budget={'contract':'mad4b.host-cpu-budget.v1','cores':8,'sampled_epoch':now,
       'cpu_idle_pct':63,'host_cpu_pct':37,'load_1m':3.5,
       'cpu_psi_some_avg10':3.1,'cpu_steal_pct':0,'wpcli_processes':1}
assert resources(budget,now,3.5)
print('PASS original conservative host resource thresholds')
assert not resources({**budget,'cpu_psi_some_avg10':12},now,3.5)
assert not resources({**budget,'wpcli_processes':4},now,3.5)
assert not resources({**budget,'sampled_epoch':now-106},now,3.5)
assert not resources({**budget,'cores':16},now,3.5)
print('PASS pressure/slot/staleness/host-core negative cases')
assert "_SOURCE_OBSERVER_IMAGE='mad4b-firecrawl-playwright:20261107-lifecycle-v3'" in candidate.read_text()
assert "'node','/tools/probe-public.js'" in candidate.read_text()
assert "_SOURCE_OBSERVER_LOCK=Path('/run/lock/mad4b-native-lazy-browser-global.lock')" in candidate.read_text()
assert 'CSS_NETWORK_PROFILE' not in m
print('PASS original renderer probe and shared browser lock, no second runtime')
h={'source:original_browser_evidence_refresh':{
  'last_completed':now-2700,'next_attempt':now-5}}
assert due(fresh,h,now)==(True,'proactive_browser_proof_renewal')
assert checkpoint(h,now,600)=={'last_attempt':now,'next_attempt':now+600,
                              'last_completed':now-2700}
assert due(fresh,h,now+600)==(True,'proactive_browser_proof_renewal')
print('PASS failed retry keeps last real successful proof and proactive eligibility')
assert checkpoint(h,now+600,240)=={
 'last_attempt':now+600,'next_attempt':now+840,'last_completed':now-2700}
print('PASS original Chromium busy backoff preserves successful proof')
assert checkpoint(h,now+840,600)=={
 'last_attempt':now+840,'next_attempt':now+1440,'last_completed':now-2700}
print('PASS timeout/non-200 retry preserves original previous success')
assert checkpoint(h,now+1440,2700,completed_at=now+1472)=={
 'last_attempt':now+1440,'next_attempt':now+4140,'last_completed':now+1472}
assert due({**fresh,'checked_at':now+1472},h,now+1472)==(False,'readback_cooldown')
print('PASS new authenticated completion advances last_completed after actual success')
h={'source:original_browser_evidence_refresh':{'next_attempt':now-1}}
assert checkpoint(h,now,600)=={'last_attempt':now,'next_attempt':now+600}
print('PASS unproven source evidence never inherits a fabricated success timestamp')
for bad in (now-1,now+241,False,'invalid'):
 try:checkpoint({},now,600,completed_at=bad)
 except ValueError:pass
 else:raise AssertionError('invalid original browser completion accepted')
print('PASS forged/future/invalid proof completion rejected fail closed')
print('CSS_ORIGINAL_BROWSER_FRESHNESS_TESTS_19_OF_19_PASS')
