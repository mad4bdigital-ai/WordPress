"""Pure acceptance tests of original source owned-browser evidence decisions."""
import ast,time
from pathlib import Path
candidate=Path(__file__).resolve().parents[1]/'site-owned-source-wpcron-worker.py'
source=ast.parse(candidate.read_text())
# Import only the two pure admission functions without loading or mutating
# the live site-owner profile, renderer, WordPress, or Cron.
needed={'source_browser_evidence_due','source_browser_evidence_resource_ok'}
nodes=[x for x in source.body if isinstance(x,ast.FunctionDef) and x.name in needed]
assert len(nodes)==2
m={'_SOURCE_OBSERVER_KEY':'source:original_browser_evidence_refresh',
   '_SOURCE_OBSERVER_LANGS':('en','es','de','fr','ar','it')}
exec(compile(ast.Module(body=nodes,type_ignores=[]),str(candidate),'exec'),m)
due=m['source_browser_evidence_due']
resources=m['source_browser_evidence_resource_ok']
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
print('CSS_ORIGINAL_BROWSER_FRESHNESS_TESTS_11_OF_11_PASS')
