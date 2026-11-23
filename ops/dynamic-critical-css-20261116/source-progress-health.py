#!/usr/bin/env python3
"""Autonomous source CSS *progress* SLO, independent of green systemd timers.
Read-only with respect to WP/Commerce/assets; writes a private monitoring state.
"""
import datetime,json,os,pathlib,re,sys,tempfile,time
from pathlib import Path
ROOT=Path('/opt/mad4b-company-provisioning/reports')
STATUS=ROOT/'critical-css-health-v1/source-progress-latest.json'
CONTRACT='mad4b.public-css.source-progress-slo.v1'
SITES=('source','wayfa')
STALE_WARN=2*3600
STALE_FAIL=6*3600
PROGRESS_FAIL=24*3600
ALERT_COOLDOWN=4*3600
# A content-derived coverage regression is not a certificate failure.
# Allow a complete new Cron cycle before alerting, then escalate through
# the existing OnFailure relay only if recovery is still absent.
REGRESSION_WARN=20*60
REGRESSION_ALERT_TWO=60*60
REGRESSION_ALERT_ONE=6*3600

def safe(path,maxbytes=2000000):
    if path.is_symlink() or not path.is_file():raise ValueError('missing-or-symlink-source-status:'+path.name)
    st=path.stat()
    if st.st_size<20 or st.st_size>maxbytes or st.st_uid!=0 or st.st_mode&0o022:
        raise ValueError('untrusted-source-status-file:'+path.name)
    val=json.loads(path.read_text());
    if not isinstance(val,dict):raise ValueError('non-object-source-status')
    return val

def evaluate(inputs,previous,now):
    previous=previous if isinstance(previous,dict) and previous.get('contract')==CONTRACT else {}
    known=set(previous.get('qualified_proof_keys',[]))
    fresh=set();sites={};all_pending=0;any_stale=False;any_critical_stale=False
    for site in SITES:
        item=inputs[site]
        if (item.get('site')!=site or item.get('contract')!='mad4b.public-css.source-family-registry.v1'):
            raise ValueError('foreign-source-registry:'+site)
        groups=item.get('source_families')
        if not isinstance(groups,list) or len(groups)>25000:raise ValueError('invalid-source-registry:'+site)
        total=item.get('source_groups_total')
        if total!=len(groups):raise ValueError('source-group-count-mismatch:'+site)
        at=int(item.get('_file_mtime',0));age=max(0,now-at)
        if at>now+60:raise ValueError('source-report-from-future:'+site)
        if age>STALE_WARN:any_stale=True
        if age>STALE_FAIL:any_critical_stale=True
        statuses={};proven=0
        for group in groups:
            status=group.get('status','unknown')
            statuses[status]=statuses.get(status,0)+1
            qualified=group.get('proven_market_profiles',{})
            if not isinstance(qualified,dict):raise ValueError('invalid-market-proof:'+site)
            for market,count in qualified.items():
                if not isinstance(market,str) or not re.fullmatch(r'(?:base|static|[A-Z]{2})\|(?:NONE|[A-Z]{3})',market):
                    raise ValueError('invalid-source-market:'+site)
                if not isinstance(count,int) or count<1:raise ValueError('source-market-not-proven:'+site)
                key=group.get('family_key')
                if not isinstance(key,str) or not re.fullmatch('[a-f0-9]{64}',key):
                    raise ValueError('untrusted-source-family-key:'+site)
                fresh.add(site+'|'+key+'|'+market);proven+=1
        pending=sum(v for status,v in statuses.items() if status not in
                    ('source_canary_qualified','source_native_optimizer_unavailable'))
        all_pending+=pending
        sites[site]={'source_groups':total,'status_counts':statuses,
          'new_proven_market_profiles':len([x for x in fresh-known if x.startswith(site+'|')]),
          'current_proven_market_profiles':proven,
          'pending_groups':pending,'registry_age_seconds':age,
          'registry_stale':age>STALE_WARN}
    new=fresh-known
    # The original QA proof keys change when source generations change.
    # Track only a bounded per-company numerical recovery target; never
    # reaccept old proof keys or mark a CSS certificate valid from this.
    prev_regress=previous.get('coverage_regression',{})
    if not isinstance(prev_regress,dict):prev_regress={}
    prev_sites=previous.get('sites',{})
    if not isinstance(prev_sites,dict):prev_sites={}
    recovery={};recovery_over_grace=False;recovery_escalation=False
    for site in SITES:
        current=sites[site]['current_proven_market_profiles']
        prior=prev_sites.get(site,{})
        if not isinstance(prior,dict):prior={}
        baseline=prior.get('current_proven_market_profiles',0)
        if type(baseline) is not int or not 0<=baseline<=25000:baseline=0
        previous_loss=prev_regress.get(site,{})
        if not isinstance(previous_loss,dict):previous_loss={}
        target=previous_loss.get('recovery_target',0)
        if type(target) is not int or not 0<=target<=25000:target=0
        target=max(target,baseline)
        if current>=target:
            recovery[site]={'recovery_target':0,'missing_profiles':0,
                            'since_epoch':0,'age_seconds':0}
            continue
        started=previous_loss.get('since_epoch',0)
        if (type(started) is not int or
            not 0<started<=now or now-started>30*86400):
            started=now
        missing=target-current
        age=now-started
        recovery[site]={'recovery_target':target,
                        'missing_profiles':missing,'since_epoch':started,
                        'age_seconds':age}
        recovery_over_grace|=age>=REGRESSION_WARN
        recovery_escalation|=(missing>=2 and age>=REGRESSION_ALERT_TWO
                              or missing>=1 and age>=REGRESSION_ALERT_ONE)
    new=fresh-known
    start=int(previous.get('started_epoch',now));last=int(previous.get('last_success_epoch',start))
    if new:last=now
    since=max(0,now-last)
    stalled=all_pending>0 and since>=PROGRESS_FAIL
    state=('stalled' if stalled or any_critical_stale else
           'warning' if any_stale or recovery_over_grace else 'active')
    alert_at=int(previous.get('last_escalation_epoch',0))
    escalate=(state=='stalled' or recovery_escalation) and now-alert_at>=ALERT_COOLDOWN
    return {'contract':CONTRACT,'generated_at_utc':datetime.datetime.fromtimestamp(now,datetime.timezone.utc).isoformat(),
       'status':state,'started_epoch':start,'last_success_epoch':last,
       'seconds_since_proven_source_progress':since,
       'new_proven_market_profiles':len(new),
       'current_proven_market_profiles':len(fresh),
       'qualified_proof_keys':sorted(fresh),
       'sites':sites,'pending_groups':all_pending,
       'coverage_regression':recovery,
       'coverage_regression_alert_reason':(
           'previously_proven_source_market_profiles_missing'
           if recovery_escalation else None),
       'escalate':escalate,
       'last_escalation_epoch':now if escalate else alert_at,
       'note':'No certificate minted; this is a freshness and new source-market proof monitor, independent of worker return code.'}

def atom(path,value):
    if path.parent.is_symlink() or path.is_symlink():raise ValueError('unsafe-monitoring-output')
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,temp=tempfile.mkstemp(prefix='.source-progress-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:
            os.fchmod(out.fileno(),0o600)
            json.dump(value,out,sort_keys=True,indent=2);out.write('\n');out.flush();os.fsync(out.fileno())
        os.replace(temp,path)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    now=int(time.time());inputs={}
    for site in SITES:
        path=ROOT/'public-critical-css-v1'/site/'source-families-latest.json'
        item=safe(path,3000000);item['_file_mtime']=int(path.stat().st_mtime)
        inputs[site]=item
    old=safe(STATUS) if STATUS.exists() else {}
    result=evaluate(inputs,old,now);atom(STATUS,result)
    print('MAD4B_CCSS_SOURCE_PROGRESS',result['status'],
          'pending',result['pending_groups'],'source_market_proofs',result['current_proven_market_profiles'],
          'new',result['new_proven_market_profiles'],
          'seconds_since_new_source',result['seconds_since_proven_source_progress'],flush=True)
    # Only a persistent, debounced failure triggers the installed central
    # OnFailure relay. Ordinary low-CPU deferred ticks remain telemetry.
    if result['escalate']:
        print('HOLD_CCSS_COVERAGE_REGRESSION_ESCALATE'
              if result['coverage_regression_alert_reason']
              else 'HOLD_CCSS_SOURCE_PROGRESS_STALLED_ESCALATE',
              file=sys.stderr)
        return 4
    return 0

if __name__=='__main__':
    try:sys.exit(main())
    except (OSError,ValueError,KeyError,TypeError,json.JSONDecodeError) as exc:
        print('HOLD_CCSS_SOURCE_PROGRESS_STATUS',str(exc)[:210],file=sys.stderr);sys.exit(4)
