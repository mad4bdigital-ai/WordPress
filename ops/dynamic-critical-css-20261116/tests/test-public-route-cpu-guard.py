import importlib.util,pathlib,sys,tempfile,json
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('guard',D/'public-route-cpu-guard.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=1800000000
base={'contract':'mad4b.host-cpu-budget.v1','sampled_epoch':now,'cores':8,
      'host_cpu_pct':30,'load_1m':3,'cpu_steal_pct':0,'cpu_psi_some_avg10':3,'wpcli_processes':1}
count=0
def test(name,condition):
 global count
 assert condition,name
 count+=1;print('PASS',name)
test('quiet CPU window admits optional browser',m.decision(base,now=now,load=3)==(True,'host_quiet_window'))
for key,value,reason in [
 ('host_cpu_pct',50,'cpu_headroom_insufficient'),
 ('load_1m',7,'cpu_headroom_insufficient'),
 ('cpu_steal_pct',21,'hypervisor_steal_pressure'),
 ('cpu_psi_some_avg10',25,'cpu_contention_pressure'),
 ('wpcli_processes',2,'central_wpcli_slots_reserved'),
 ('cores',4,'cpu_host_topology_changed')]:
 test(f'reject {key} budget',m.decision({**base,key:value},now=now,load=3)==(False,reason))
test('reject live load despite calm earlier sample',m.decision(base,now=now,load=8)[0] is False)
test('refuse telemetry older than 105 seconds',not m.decision(base,now=now+106,load=3)[0])
test('refuse sample from future',not m.decision(base,now=now-6,load=3)[0])
test('refuse missing WPCLI measurement',not m.decision({k:v for k,v in base.items() if k!='wpcli_processes'},now=now,load=3)[0])
test('refuse forged type in telemetry',not m.decision({**base,'host_cpu_pct':False},now=now,load=3)[0])
with tempfile.TemporaryDirectory() as path:
 p=pathlib.Path(path)/'status.json';p.write_text(json.dumps(base))
 test('stale fixture cannot be trusted as current sample',m.examine(p)[0] is False)
 p.unlink();p.symlink_to('/etc/passwd');test('symlinked telemetry rejected',m.examine(p)[0] is False)
 p.unlink();p.write_text(json.dumps(base));p.chmod(0o666);test('world-writable telemetry rejected',m.examine(p)[0] is False)
code=(D/'run-public-route.py').read_text()
test('worker verifies budget before WPCLI site observation',
    code.index("'public-route-cpu-guard.py'")<code.index('    state=obs(php,host,env)')
    and 'DEFER_PUBLIC_CCSS_RESOURCE_WINDOW' in code)
def phases(name):
    data=(D/'systemd'/name).read_text()
    for line in data.splitlines():
        if line.startswith('OnCalendar='):
            raw=line.split('*:',1)[1].split(':',1)[0]
            return [int(v) for v in raw.split(',')]
    raise AssertionError('missing schedule')
a=phases('mad4b-public-critical-css-source.timer')
b=phases('mad4b-public-critical-css-wayfa.timer')
test('twice as many source certification slots with no missing hours',
    len(a)==8 and len(set(a))==8 and max((a[(i+1)%8]-a[i])%60 for i in range(8))<=9)
test('company timer phases staggered to avoid collision',
    len(b)==8 and len(set(b))==8 and not set(a)&set(b)
    and all(min(abs(x-y),60-abs(x-y))>=3 for x in a for y in b))
print(f'PASS {count}/{count} optional browser CPU admission invariants')
