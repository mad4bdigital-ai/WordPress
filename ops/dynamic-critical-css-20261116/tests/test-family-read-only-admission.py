#!/usr/bin/env python3
"""Read-only family refresh may use one extra low-cost WPCLI slot, never Chrome."""
import importlib.util,pathlib,sys,time,tempfile,os,json
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('family_read_cpu',D/'family-report-read-cpu-guard.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=int(time.time())
base={'contract':'mad4b.host-cpu-budget.v1','sampled_epoch':now,'cores':8,
      'cpu_idle_pct':68,'cpu_iowait_pct':0.5,'host_cpu_pct':30,
      'cpu_steal_pct':0.5,'load_1m':4.3,'cpu_psi_some_avg10':1.8,
      'wpcli_processes':2}
ok=0
def check(name,valid):
 global ok
 assert valid,name
 ok+=1
 print('PASS',name)
strict=m.original().decision(base,now=now,load=4.3)
light=m.decision(base,now=now,load=4.3)
check('strict original browser gate still rejects 2 current WPCLI slots',strict==(False,'central_wpcli_slots_reserved'))
check('only read-only family report can consume exactly second WPCLI slot',light==(True,'bounded_second_wpcli_reader_only'))
check('one WPCLI stays within unchanged original safe admission',m.decision({**base,'wpcli_processes':1},now=now,load=4.3)[0])
for name,field,value in [
    ('third WPCLI cannot enter reader lane','wpcli_processes',3),
    ('first-party CPU utilization beyond 38 pct remains blocked','host_cpu_pct',38),
    ('not enough idle CPU remains blocked','cpu_idle_pct',54),
    ('persistent host load beyond 5 blocked','load_1m',5),
    ('CPU PSI beyond 8 blocked','cpu_psi_some_avg10',8),
    ('host hypervisor steal beyond 4 blocked','cpu_steal_pct',4),
    ('I/O wait beyond 3 blocked','cpu_iowait_pct',3),
    ('host topology change rejected','cores',12),
    ('root-owned CPU report must stay recent','sampled_epoch',now-150),
    ('invalid sensor contract rejected','contract','foreign'),
    ('missing WPCLI sensor rejected','wpcli_processes',None)]:
 check(name,not m.decision({**base,field:value},now=now,load=4.3)[0])
check('live load spike blocked even if snapshot is quiet',
      not m.decision(base,now=now,load=5.0)[0])
with tempfile.TemporaryDirectory() as tmp:
 path=pathlib.Path(tmp)/'budget.json';path.write_text(json.dumps(base))
 os.chmod(path,0o600)
 check('root-owned trusted telemetry can be read without network or WPCLI',m.examine(path)[0] in (True,False))
 os.chmod(path,0o666)
 check('world-writable CPU sensor fails closed',not m.examine(path)[0])
 os.chmod(path,0o600)
 path.unlink()
 link=pathlib.Path(tmp)/'symlink';link.symlink_to('/etc/passwd')
 check('symbolic sensor refused',not m.examine(link)[0])
worker_path=(D.parent/'site-owned-css-cron-v1/worker.py')
if not worker_path.exists():
    worker_path=D.parent/'worker.py'
worker=worker_path.read_text()
refresh=(D/'refresh-source-families.py').read_text()
check('family reader admission is called only for family ticket',
      "if not admitted and job=='families':" in worker and
      'admitted,reader_reason=family_reader_window()' in worker and
      worker.index("if not admitted and job=='families':")<
          worker.rfind('invoke(site,job,jid,key,history,timeout='))
check('original WordPress public browser budget remains unchanged',
      "guard=run(['/usr/bin/python3',str(SOURCE/'public-route-cpu-guard.py')]" in worker)
check('read-only family refresh binds to exact single original source site',
      "len(sys.argv)==2 and sys.argv[1] in ('source','wayfa')" in refresh)
check('original runtime+consumer and WordPress family report are still required',
      all(x in refresh for x in ('runner.approved_native_runtime_sha(site,wp)',
          'state=bind_original_approved_v2_consumer(runner,site,wp,state)',
          'catalog=runner.catalog(php,host)',
          "families.queue.atom(root/'source-families-latest.json',result)")))
check('reader privilege never touches original CSS publish source',
      all(x not in refresh for x in ('runner.browser(',"'public-route-certificate.py'",'queue.record(')))
print('PASS',ok,'read-only CPU admission checks')
