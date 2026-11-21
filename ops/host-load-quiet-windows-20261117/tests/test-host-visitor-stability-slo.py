#!/usr/bin/env python3
import sys,importlib.util,pathlib,time
p=pathlib.Path(sys.argv[1])/'host-visitor-stability-slo.py';s=importlib.util.spec_from_file_location('monitor',p);m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
N=0
def check(desc,value):
 global N
 assert value,desc
 N+=1;print('PASS',desc)

def record(t,cpu=38,ajax=450,count=40,probe=200):
 return {'contract':m.SAMPLE_CONTRACT,'ts':t,
  'host_cpu':{'host_cpu_pct':cpu,'load_1m':3.4,'cpu_psi_some_avg10':2.0},
  'wayfa_visitor_ajax_real_traffic':{'count':count,'p95_ms':ajax,'over_2000ms':0,'non200':0},
  'mobile_home_cached_probe':{
   'wayfatours.com':{'status':200,'ttfb_ms':22.0},
   'tours.dev.mad4b.com':{'status':probe,**({'ttfb_ms':40.0} if probe==200 else {'error':'timeout' if probe==0 else 'http'})}}}
now=m.ROLLOUT_EPOCH+1800
r=[record(now-3600),record(now-1500),record(now-100)]
a=m.evaluate(r,now)
check('short post-change window cannot claim 24h stable',a['status']=='under_observation' and not a['full_24h_post_evidence'])
check('stable probe and AJAX without 24h proof is not a false completion',not a['actual_visitor_impact'])
unknown=[record(now-i*300,probe=0) for i in (1,2,3)]
b=m.evaluate(unknown,now)
check('probe timeout not treated as confirmed HTTP failure',not b['actual_visitor_impact'] and b['last_hour']['home_probe_non200']['tours.dev.mad4b.com']==0)
check('inconclusive probes separately tracked',b['last_hour']['home_probe_inconclusive']['tours.dev.mad4b.com']==3)
real=[record(now-i*300,probe=503) for i in (1,2,3)]
c=m.evaluate(real,now)
check('repeated verified HTTP 5xx is real visitor-facing incident',c['actual_visitor_impact'] and c['escalate'])
check('incident alert timestamp persisted for cooldown',c['last_escalation_epoch']==now)
cool=m.evaluate(real,now+600,c)
check('one persistent outage not paged every sample',cool['actual_visitor_impact'] and not cool['escalate'])
spikes=[record(now-i*300,ajax=3800) for i in (1,2,3,4)]
d=m.evaluate(spikes,now)
check('3+ separate real-traffic p95 windows above 3s are impact',d['actual_visitor_impact'])
small=[record(now-i*300,ajax=3800,count=2) for i in (1,2,3)]
e=m.evaluate(small,now)
check('low sample-count spike does not auto-page',not e['actual_visitor_impact'])
long=m.ROLLOUT_EPOCH+24*3600
rows=[record(m.ROLLOUT_EPOCH+i*300,cpu=30) for i in range(289)]
settled=m.evaluate(rows,long)
check('24h requires 220+ records and CPU telemetry',settled['status']=='stable_observed' and settled['full_24h_post_evidence'])
high=[record(m.ROLLOUT_EPOCH+i*300,cpu=96) for i in range(289)]
p=m.evaluate(high,long)
check('high CPU with good visitor response is pressure not incident',p['status']=='host_pressure_without_proven_visitor_impact' and not p['escalate'])
missing=[record(m.ROLLOUT_EPOCH+i*300,cpu=None) for i in range(289)]
f=m.evaluate(missing,long)
check('missing CPU telemetry cannot certify stable host',f['status']=='missing_cpu_telemetry' and not f['full_24h_post_evidence'])
stale=m.evaluate([record(now-2000)],now)
check('stale collector report not claimed healthy',stale['status']=='observer_stale')
check('multiple valid windows preserve bounded p95',m.summarize([record(now-10,ajax=250),record(now-20,ajax=900)])['ajax_window_p95_p95_ms']==900)
try:m.evaluate([],now)
except ValueError:check('no real-observer input rejected',True)
else:raise AssertionError('empty series accepted')
timer=(pathlib.Path(sys.argv[1])/'systemd/mad4b-host-visitor-stability.timer').read_text()
service=(pathlib.Path(sys.argv[1])/'systemd/mad4b-host-visitor-stability.service').read_text()
check('permanent low-cost half-hour timer exists','OnCalendar=*-*-* *:18,48:00 UTC' in timer and 'Persistent=true' in timer)
check('readonly SLO monitor has bounded compute and local alert path','OnFailure=mad4b-alert-handler@%n.service' in service and 'ProtectSystem=strict' in service and 'CPUQuota=20%' in service)
print(f'PASS {N}/{N} nonintrusive host visitor stability invariants')
