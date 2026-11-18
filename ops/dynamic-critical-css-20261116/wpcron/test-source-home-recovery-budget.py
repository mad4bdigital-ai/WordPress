#!/usr/bin/env python3
from pathlib import Path
from importlib.machinery import SourceFileLoader
from importlib.util import spec_from_loader,module_from_spec
base=Path(__file__).resolve().parent
p=base/'css-wpcron-worker.py'
spec=spec_from_loader('wpcss_recovery_budget',SourceFileLoader('wpcss_recovery_budget',str(p)))
m=module_from_spec(spec);spec.loader.exec_module(m)
base_record={'contract':'mad4b.host-cpu-budget.v1','cores':8,
  'sampled_epoch':100000,'host_cpu_pct':54,'cpu_idle_pct':43,
  'load_1m':4.7,'cpu_psi_some_avg10':4,'cpu_steal_pct':0.4,
  'wpcli_processes':2}
checks=[]
def good(name,record=None,drift=True,now=100010,load=4.7,expected=True):
  record=record if record is not None else base_record
  result,reason=m.source_recovery_budget(record,drift,now=now,load=load)
  assert result==expected,(name,result,reason)
  checks.append(name);print('PASS',name)
good('original CPU guard may reserve actual home fallback under 8-core headroom')
good('no unproven CSS drift cannot borrow browser lane',drift=False,expected=False)
good('stale root CPU evidence refused',now=100400,expected=False)
good('two WP-CLI slots maximum',record=dict(base_record,wpcli_processes=3),expected=False)
good('CPU idle safety preserved',record=dict(base_record,cpu_idle_pct=22),expected=False)
good('PSI CPU contention still prevents admission',record=dict(base_record,cpu_psi_some_avg10=13),expected=False)
good('host over 60pct CPU still prevents admission',record=dict(base_record,host_cpu_pct=70),expected=False)
good('real load over 5.9 still prevents admission',load=6.1,expected=False)
good('topology change cannot reuse fixed capacity',record=dict(base_record,cores=16),expected=False)
good('wrong data contract denied',record=dict(base_record,contract='forged'),expected=False)
good('booleans cannot impersonate WP CLI count',record=dict(base_record,wpcli_processes=True),expected=False)
print('SOURCE_HOME_RECOVERY_CPU_GUARD',len(checks))
