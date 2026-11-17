import importlib.util,pathlib,json,sys,tempfile
D=pathlib.Path(sys.argv[1]);total=0
def load(name,module):
 spec=importlib.util.spec_from_file_location(name,D/module);x=importlib.util.module_from_spec(spec);spec.loader.exec_module(x);return x
watch=load('css_watch','performance-continuity-watchdog.py')
priority=load('css_priority','home-recovery-priority.py')
gate=load('css_gate','event-gate.py')
def assert_ok(label,test):
 global total
 assert test,label
 total+=1
 print('PASS',label)
t=1_000_000
observed={'en':{'http_status':200,'css_ready':0,'native_complete':1},
          'es':{'http_status':200,'css_ready':0,'native_complete':1}}
p=watch.evaluate({},observed,t)
assert_ok('native full fallback is degraded not ready',p['state']=='degraded' and p['degraded_since']==t)
p2=watch.evaluate(p,observed,t+900)
assert_ok('sustained fallback alerts at 15m',p2['alert'] and p2['degraded_seconds']==900)
assert_ok('urgent home before public route',priority.decision(p2,t+900)==('defer','home_restore_urgent_under_2h'))
ready={'en':{'http_status':200,'css_ready':1,'native_complete':0},
       'es':{'http_status':200,'css_ready':1,'native_complete':0}}
p3=watch.evaluate(p2,ready,t+901)
assert_ok('actual ready recovers without inheriting degraded_since',p3['state']=='healthy' and p3['degraded_since'] is None)
assert_ok('post recovery releases public route priority',priority.decision(p3,t+901)[0]=='allow')
broken=dict(observed);broken['en']={'http_status':503,'css_ready':None,'native_complete':None}
p4=watch.evaluate({},broken,t)
assert_ok('unavailable locale cannot issue green',p4['state']=='degraded' and not p4['alert'])
broken['es']={'http_status':503,'css_ready':None,'native_complete':None}
assert_ok('both unreachable classify unknown',watch.evaluate({},broken,t)['state']=='unknown')
assert_ok('invalid host denies ownership of degraded state',priority.decision(dict(p2,host='invalid.site'),t+1000)[0]=='allow')
assert_ok('stale monitor cannot lock public queue',priority.decision(p2,t+3000)[0]=='allow')
p5=dict(p2,degraded_since=t-8000,observed_at=t)
assert_ok('long degradation reserves most browser windows',priority.decision(p5,t)[0]=='defer' if (t//420)%4!=0 else True)
slot=next(x for x in range(t,t+10000,420) if (x//420)%4==0)
p5['observed_at']=slot
assert_ok('long degradation grants 1/4 fair public window',priority.decision(p5,slot)[0]=='allow')
with tempfile.TemporaryDirectory() as folder:
 path=pathlib.Path(folder)/'state.json'
 watch.atom(path,p2)
 assert_ok('atomic readback preserves host identity',watch.load(path)==p2)
 assert_ok('file priority reads trusted health receipt',priority.from_file(path,t+900)[0]=='defer')
 path.write_text('bad-json')
 assert_ok('corrupt watchdog receipt never permanently starves other routes',priority.from_file(path,t+900)[0]=='allow')
with tempfile.TemporaryDirectory() as folder:
 state=pathlib.Path(folder)/'state.json';gate.write(state,{'contract':gate.CONTRACT,'site':'wayfa','key':'abc','status':'pending','checked_at':t})
 assert_ok('atomic event ledger pending readback',gate.read(state,'wayfa')['status']=='pending')
 assert_ok('resource deferral does not trigger heavy immediate retry',gate.decide('abc',gate.read(state,'wayfa'),t+10)[0]=='skip')
 assert_ok('resource deferral retries after 2min instead of 1hour',gate.decide('abc',gate.read(state,'wayfa'),t+121)==('inspect','retry_pending_resource_generation'))
 assert_ok('actual browser failure keeps one-hour cooldown',gate.decide('abc',dict(gate.read(state,'wayfa'),status='hold'),t+121)[0]=='skip')
 assert_ok('actual browser failure retries after full hour',gate.decide('abc',dict(gate.read(state,'wayfa'),status='hold'),t+3601)[0]=='inspect')
 assert_ok('CSS source change triggers immediately regardless of pending',gate.decide('def',gate.read(state,'wayfa'),t+1)[0]=='inspect')
 assert_ok('ready source still skips redundant Chromium',gate.decide('abc',dict(gate.read(state,'wayfa'),status='ready'),t+60)[0]=='skip')
run=(D/'run-site.sh').read_text()
public=(D/'run-public-route.py').read_text()
assert_ok('every global browser collision is short pending', 'GATE_REASON=browser_busy' in run and 'GATE_STATUS=pending' in run)
assert_ok('load deferral is not treated as failed QA', 'GATE_REASON=host_cpu_busy' in run)
assert_ok('company home priority runs before global browser lock',public.index('DEFER_PUBLIC_CCSS_HOME_RECOVERY')<public.index("b=locked('/run/lock/mad4b-native-lazy-browser-global.lock')"))
assert_ok('no critical CSS made ready by watchdog',all(x not in (D/'performance-continuity-watchdog.py').read_text() for x in ('update_option(', 'wp_remote_post(', 'wp-cron.php','docker run')))
print(f'PASS {total}/{total} Performance Continuity v2 core and safety tests')
