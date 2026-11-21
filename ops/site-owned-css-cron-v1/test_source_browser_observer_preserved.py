#!/usr/bin/env python3
"""Preserve Source first-party Browser evidence with no Wayfa side effects."""
from test_site_worker import load_worker,sample
import time
now=int(time.time());s=load_worker(sample('source'));w=load_worker(sample('wayfa'))
n=0
def check(label,ok):
 global n
 assert ok,label
 n+=1;print('PASS',label)
base={'contract':'mad4b.source-css-profile-variance.v1',
      'host':'tours.dev.mad4b.com',
      'checked_at':now,'browser_profiles_missing_or_expired':['en']}
check('source browser refresher retained in latest source worker',
      callable(s.source_browser_evidence_refresh))
check('source missing browser evidence is due for read-only renewal',
      s.source_browser_evidence_due(base,{},now)[0])
check('foreign browser proof never triggers source',
      not s.source_browser_evidence_due({**base,'host':'wayfatours.com'}, {},now)[0])
check('stale profile proof cannot trigger browser',
      not s.source_browser_evidence_due({**base,'checked_at':now-901},{},now)[0])
check('wayfa always rejects source-only background Chrome',
      w.source_browser_evidence_refresh({},now) is False)
r={'contract':'mad4b.host-cpu-budget.v1','cores':8,'sampled_epoch':now,
   'host_cpu_pct':25,'cpu_idle_pct':70,'load_1m':3,
   'cpu_psi_some_avg10':2,'cpu_steal_pct':1,'wpcli_processes':0}
check('source read-only observer requires host resources',
      s.source_browser_evidence_resource_ok(r,now,3))
check('source observer stays blocked when host is busy',
      not s.source_browser_evidence_resource_ok({**r,'load_1m':8},now,8))
history={}
s.source_browser_evidence_checkpoint(history,now,600)
check('source browser attempt never mints a fake completion',
      history[s._SOURCE_OBSERVER_KEY].get('last_completed') is None)
s.source_browser_evidence_checkpoint(history,now,2700,completed_at=now)
check('only genuine completed result saves a completion timestamp',
      history[s._SOURCE_OBSERVER_KEY].get('last_completed')==now)
s.source_browser_evidence_checkpoint(history,now,600)
check('subsequent failed retry preserves genuine previous completion',
      history[s._SOURCE_OBSERVER_KEY].get('last_completed')==now)
print('PASS',n,'source browser proof preservation invariants')
