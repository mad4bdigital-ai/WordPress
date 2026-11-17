import importlib.util,pathlib,sys
D=pathlib.Path(sys.argv[1]);spec=importlib.util.spec_from_file_location('progress',D/'source-progress-health.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
now=1800000000;count=0
def test(name,cond):
 global count
 assert cond,name
 count+=1;print('PASS',name)
def registry(site,mtime,cert=False):
 groups=[{'family_key':('a' if site=='source' else 'b')*64,
          'status':'source_canary_qualified' if cert else 'source_canary_pending',
          'proven_market_profiles':{'base|USD':2} if cert else {}}]
 return {'contract':'mad4b.public-css.source-family-registry.v1','site':site,
    '_file_mtime':mtime,'source_groups_total':1,'source_families':groups}
def inputs(t=now,qualified=False):return {'source':registry('source',t,qualified),'wayfa':registry('wayfa',t,False)}
first=m.evaluate(inputs(),{},now)
test('successful timer is only active, not source-qualified',first['status']=='active' and first['current_proven_market_profiles']==0 and first['pending_groups']==2)
test('first heartbeat starts no-progress clock without fake QA pass',first['last_success_epoch']==now and first['new_proven_market_profiles']==0)
test('short CPU deferral does not trigger alert',m.evaluate(inputs(now+3600),first,now+3600)['status']=='active')
warning=m.evaluate(inputs(now+4*3600),first,now+4*3600)
test('stale source register is detectable independently from service exit',warning['status']=='active')
stale=m.evaluate(inputs(now),first,now+6*3600+1)
test('unrefreshed 6-hour registry is an incident',stale['status']=='stalled' and stale['escalate'])
stall=m.evaluate(inputs(now+24*3600),first,now+24*3600)
test('24 hours without new source-market proof is a stalled goal',stall['status']=='stalled' and stall['escalate'])
test('stalled source never pretends certificate was granted',stall['current_proven_market_profiles']==0)
repeat=m.evaluate(inputs(now+24*3600+3600),stall,now+24*3600+3600)
test('repeated stalled alarms suppressed during cooldown',repeat['status']=='stalled' and not repeat['escalate'])
renewed=m.evaluate(inputs(now+24*3600+3600,True),stall,now+24*3600+3600)
test('new exact source-market proof clears stalled status',renewed['status']=='active' and renewed['last_success_epoch']==now+24*3600+3600)
test('certificate evidence counted separately from 2 pending groups',renewed['current_proven_market_profiles']==1 and renewed['pending_groups']==1)
again=m.evaluate(inputs(now+24*3600+7200,True),renewed,now+24*3600+7200)
test('repeated report with same proof key does not reset progress timer',again['new_proven_market_profiles']==0 and again['last_success_epoch']==renewed['last_success_epoch'])
try:m.evaluate({'source':registry('wayfa',now),'wayfa':registry('wayfa',now)},first,now)
except ValueError:test('cross-company source register rejected',True)
else:raise AssertionError('untrusted company accepted')
try:m.evaluate({'source':registry('source',now),'wayfa':{**registry('wayfa',now),'source_groups_total':2}},first,now)
except ValueError:test('source group count mismatch rejected',True)
else:raise AssertionError('false group count accepted')
svc=(D/'systemd/mad4b-public-source-progress-health.service').read_text();timer=(D/'systemd/mad4b-public-source-progress-health.timer').read_text()
test('persistent independent SLO timer installed in manifest',all(x in timer for x in ('OnCalendar=*-*-* *:14:00 UTC','Persistent=true','Unit=mad4b-public-source-progress-health.service')))
test('independent central OnFailure escalation uses read-only locked-down service',all(x in svc for x in ('OnFailure=mad4b-alert-handler@%n.service','ProtectSystem=strict','CapabilityBoundingSet=','ReadWritePaths=/opt/mad4b-company-provisioning/reports/critical-css-health-v1')))
print(f'PASS {count}/{count} source SLO/progress invariants')
