#!/usr/bin/env python3
"""Do not hide lost current CSS source market evidence behind green systemd timers.

Source status is observational. It may alert an original operator; it cannot
restore an expired certificate or mark an untested market as certified.
"""
import importlib.util,pathlib,sys
D=pathlib.Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('coverage_progress',D/'source-progress-health.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
def check(label,yes):
 assert yes,label
 print('PASS',label)
t0=1_795_290_000
def family(site,proven,now):
 groups=[{'family_key':f'{k+1:064x}','status':'source_canary_qualified' if k<proven else 'source_canary_pending',
          'proven_market_profiles':{'base|USD':2} if k<proven else {}} for k in range(2)]
 return {'contract':'mad4b.public-css.source-family-registry.v1','site':site,
         'source_groups_total':len(groups),'source_families':groups,
         '_file_mtime':now}
def inputs(wayfa_count,now):
 return {'source':family('source',0,now),'wayfa':family('wayfa',wayfa_count,now)}
previous=m.evaluate(inputs(2,t0),{},t0)
check('previously proven two profiles are real current baseline',previous['sites']['wayfa']['current_proven_market_profiles']==2)
lost=m.evaluate(inputs(0,t0+60),previous,t0+60)
check('loss of two real profiles is recorded immediately without inventing certificates',
      lost['coverage_regression']['wayfa']['missing_profiles']==2 and
      lost['coverage_regression']['wayfa']['recovery_target']==2)
check('first content invalidation allows original Cron recovery grace',
      lost['status']=='active' and lost['escalate'] is False)
loss20=m.evaluate(inputs(0,t0+60+1200),lost,t0+60+1200)
check('persistent loss changes telemetry to warning after 20 minutes',
      loss20['status']=='warning' and not loss20['escalate'])
loss60=m.evaluate(inputs(0,t0+60+3600),loss20,t0+60+3600)
check('2+ missing profiles after an hour trigger original OnFailure relay',
      loss60['escalate'] is True and
      loss60['coverage_regression_alert_reason']=='previously_proven_source_market_profiles_missing')
repeat=m.evaluate(inputs(0,t0+60+3620),loss60,t0+60+3620)
check('central alert obeys four-hour cooldown',repeat['escalate'] is False)
recover=m.evaluate(inputs(2,t0+60+4800),repeat,t0+60+4800)
check('two new independently qualified source profiles resolve alert target',
      recover['coverage_regression']['wayfa']['recovery_target']==0 and
      recover['coverage_regression']['wayfa']['missing_profiles']==0 and
      recover['status']=='active')
check('Wayfa source loss does not create Source staging false incident',
      all(x['missing_profiles']==0 for s,x in recover['coverage_regression'].items()))
single=m.evaluate(inputs(1,t0+10000),previous,t0+10000)
single_next=m.evaluate(inputs(1,t0+10000+3600),single,t0+10000+3600)
check('one changed source profile has no P1 alert after only an hour',
      single_next['coverage_regression']['wayfa']['missing_profiles']==1 and
      not single_next['escalate'])
single_long=m.evaluate(inputs(1,t0+10000+6*3600+20),single_next,t0+10000+6*3600+20)
check('persistent single missing proof is eventually escalated',
      single_long['escalate'] is True)
print('PASS 10/10 CSS source proof regression SLO invariants')
