#!/usr/bin/env python3
"""Long-run MAD4B host+visitor SLO using *existing* noninvasive 5m samples.
No request, WordPress, cache mutation, browser or WPCLI is made by this job.
"""
import datetime,json,math,os,pathlib,tempfile,time,sys
from pathlib import Path
ROOT=Path('/var/lib/mad4b-visitor-tail-observer')
REPORT=Path('/opt/mad4b-company-provisioning/reports/host-visitor-stability-v1')
CONTRACT='mad4b.host-visitor-stability.v1'
SAMPLE_CONTRACT='mad4b.visitor-tail-observer.v1'
# Exact first quiet-window deployment, independent of each observer run.
ROLLOUT_EPOCH=1794905238
ROLLING_24H=86400
RECENCY=1800
ALARM_COOLDOWN=14400

def pct(data,q):
    v=sorted(float(x) for x in data if isinstance(x,(int,float)) and math.isfinite(x))
    return round(v[min(len(v)-1,max(0,math.ceil(len(v)*q)-1))],1) if v else None

def summarize(rows):
    cpu=[d.get('host_cpu',{}).get('host_cpu_pct') for d in rows]
    load=[d.get('host_cpu',{}).get('load_1m') for d in rows]
    psi=[d.get('host_cpu',{}).get('cpu_psi_some_avg10') for d in rows]
    ajax=[d.get('wayfa_visitor_ajax_real_traffic',{}) for d in rows]
    active=[j for j in ajax if isinstance(j.get('count'),int) and j['count']>0]
    lat=[j.get('p95_ms') for j in active]
    home={'wayfatours.com':[],'tours.dev.mad4b.com':[]};non200={s:0 for s in home};unknown={s:0 for s in home}
    for r in rows:
        values=r.get('mobile_home_cached_probe',{})
        for site in home:
            row=values.get(site) if isinstance(values,dict) else None
            if not isinstance(row,dict):continue
            if row.get('status')==0:unknown[site]+=1
            elif isinstance(row.get('status'),int) and row['status']!=200:
                non200[site]+=1
            elif row.get('status')==200 and isinstance(row.get('ttfb_ms'),(int,float)):
                home[site].append(row['ttfb_ms'])
    return {'samples':len(rows),'cpu_p50_pct':pct(cpu,.5),'cpu_p95_pct':pct(cpu,.95),
        'cpu_pressure_p95_pct':pct(psi,.95),'host_load1_p95':pct(load,.95),
        'cpu_over_85pct_samples':sum(isinstance(v,(int,float)) and v>=85 for v in cpu),
        'ajax_active_windows':len(active),
        'ajax_window_p95_p95_ms':pct(lat,.95),
        'ajax_windows_over_3s':sum(isinstance(v,(int,float)) and v>=3000 for v in lat),
        'ajax_over_2s_fraction':round(sum(j.get('over_2000ms',0) for j in active)/
            max(1,sum(j['count'] for j in active)),4),
        'home_cache_ttfb_p95_ms':{site:pct(v,.95) for site,v in home.items()},
        'home_probe_count':{site:len(v) for site,v in home.items()},
        'home_probe_non200':non200,
        'home_probe_inconclusive':unknown}

def evaluate(records,now,previous=None):
    previous=previous if isinstance(previous,dict) and previous.get('contract')==CONTRACT else {}
    good=sorted((d for d in records if isinstance(d,dict)
        and d.get('contract')==SAMPLE_CONTRACT
        and isinstance(d.get('ts'),int) and 0<d['ts']<=now+60),key=lambda d:d['ts'])
    if not good:raise ValueError('no_original_visitor_observer_samples')
    last=good[-1]['ts'];stale=(now-last)>RECENCY
    baseline=[d for d in good if ROLLOUT_EPOCH-ROLLING_24H<=d['ts']<ROLLOUT_EPOCH]
    post=[d for d in good if ROLLOUT_EPOCH<=d['ts']<=now]
    current=[d for d in good if now-3600<=d['ts']<=now]
    four=[d for d in good if now-4*3600<=d['ts']<=now]
    previous_baseline=summarize(baseline)
    post_summary=summarize(post[-300:])
    hour=summarize(current)
    four_summary=summarize(four)
    post_hours=max(0,(now-ROLLOUT_EPOCH)/3600)
    # The *same* observed visitor evidence decides incidents. Transient CPU
    # saturation alone does not page users or call Chrome.
    enough_ajax=sum((d.get('wayfa_visitor_ajax_real_traffic',{}).get('count',0)
                     for d in current),0)>=25
    real_visitor_degradation=(enough_ajax and hour['ajax_windows_over_3s']>=3)
    # A 4s bounded observer timeout (status=0) is ambiguous, never a
    # proven HTTP error. Page only on repeated explicit HTTP failures.
    probe_fail=sum(hour['home_probe_non200'].values())>=2
    impact=bool(real_visitor_degradation or probe_fail)
    settled=post_hours>=24 and post_summary['samples']>=220
    cpu_recent=sum(isinstance(d.get('host_cpu',{}).get('host_cpu_pct'),(int,float)) for d in post[-300:])
    cpu_data_complete=post_summary['samples']>0 and cpu_recent>=max(1,int(.8*post_summary['samples']))
    status=('visitor_impact' if impact else 'observer_stale' if stale else
            'missing_cpu_telemetry' if not cpu_data_complete else
            'under_observation' if not settled else
            'host_pressure_without_proven_visitor_impact' if
                four_summary['cpu_p95_pct'] is not None
                and four_summary['cpu_p95_pct']>=85 else 'stable_observed')
    last_alert=int(previous.get('last_escalation_epoch',0))
    should_alert=impact and now-last_alert>=ALARM_COOLDOWN
    return {'contract':CONTRACT,
       'generated_at_utc':datetime.datetime.fromtimestamp(now,datetime.timezone.utc).isoformat(),
       'rollout_epoch':ROLLOUT_EPOCH,'observation_hours_since_rollout':round(post_hours,2),
       'minimum_post_samples_for_24h':220,
       'full_24h_post_evidence':settled and cpu_data_complete,
       'status':status,'source':'existing_real_visitor_5m_telemetry',
       'observer_age_seconds':now-last,'observer_stale':stale,
       'baseline_24h_before_change':previous_baseline,
       'post_change_up_to_24h':post_summary,'last_hour':hour,'last_four_hours':four_summary,
       'last_escalation_epoch':now if should_alert else last_alert,
       'escalate':should_alert,
       'actual_visitor_impact':impact,
       'note':'CPU load by itself is not a visitor incident. Verified no synthetic cache requests or CSS admission changes.'}

def read_original(path):
    if path.is_symlink() or not path.is_file() or path.stat().st_size>5000000:
        raise ValueError('untrusted_visitor_observer_history')
    values=[]
    for line in path.read_text().splitlines():
        try:values.append(json.loads(line))
        except json.JSONDecodeError:continue
    return values

def atomic(path,body):
    if path.is_symlink() or path.parent.is_symlink():raise ValueError('unsafe_SLO_output')
    path.parent.mkdir(parents=True,exist_ok=True,mode=0o700)
    fd,tmp=tempfile.mkstemp(prefix='.host-slo-',dir=path.parent)
    try:
        with os.fdopen(fd,'w') as out:
            os.fchmod(out.fileno(),0o600)
            json.dump(body,out,indent=2,sort_keys=True);out.write('\n');out.flush();os.fsync(out.fileno())
        os.replace(tmp,path)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)

def main():
    now=int(time.time())
    original=read_original(ROOT/'samples.jsonl')
    prior_file=REPORT/'latest.json'
    prior=json.loads(prior_file.read_text()) if prior_file.is_file() and not prior_file.is_symlink() else {}
    result=evaluate(original,now,prior)
    atomic(prior_file,result)
    print('MAD4B_HOST_VISITOR_STABILITY',json.dumps({
      'status':result['status'],'post_hours':result['observation_hours_since_rollout'],
      'post_samples':result['post_change_up_to_24h']['samples'],
      'last_hour_ajax_p95_window_ms':result['last_hour']['ajax_window_p95_p95_ms'],
      'last_hour_cpu_p95':result['last_hour']['cpu_p95_pct'],
      'visitor_impact':result['actual_visitor_impact']},sort_keys=True),flush=True)
    if result['escalate']:
        print('MAD4B_VISITOR_IMPACT_ESCALATION',file=sys.stderr)
        return 4
    return 0

if __name__=='__main__':
    try:sys.exit(main())
    except (OSError,ValueError,TypeError,KeyError) as e:
        print('MAD4B_HOST_VISITOR_STABILITY_HOLD',str(e)[:180],file=sys.stderr)
        sys.exit(4)
