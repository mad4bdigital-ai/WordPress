#!/usr/bin/env python3
"""MAD4B Adaptive CSS RUM decision engine v1 (pure; never writes public HTML).

All inputs are first-party, source-scoped aggregates. No raw IP, URL, user ID,
cookie or browsing history is processed. A recommendation is NOT permission to
issue or expose an unqualified CSS certificate.
"""
import hashlib,json,math,re
from collections import defaultdict

CONTRACT='mad4b.adaptive-css.policy.v1'
COHORT_KEYS=('site','host','language','market','currency','device','template',
             'css_epoch','dependency_sha','candidate_sha')
STEPS=(0,1,5,25,100)
METRICS=('lcp','inp','cls')
# Values: milliseconds for LCP/INP, thousandths for CLS.
BOUNDS={
 'lcp':(500,1000,1500,2000,2500,3000,4000,5000,6000,8000,10000,15000,20000,30000),
 'inp':(50,100,150,200,250,300,400,500,700,1000,1500,2000,3000,5000),
 'cls':(10,25,50,75,100,125,150,200,250,400,600,1000,2000)}
GOOD={'lcp':2500,'inp':200,'cls':100}
MIN_PROMOTE={1:200,5:400,25:800,100:1200}
MIN_BAD=50
HEX=re.compile(r'^[0-9a-f]{64}$')

def source_variant(payload):
    if not isinstance(payload,dict) or set(payload)!=set(COHORT_KEYS):
        raise ValueError('invalid_cohort_fields')
    if payload['site'] not in ('source','wayfa'):
        raise ValueError('unenrolled_site')
    expected={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}
    if payload['host']!=expected[payload['site']]:
        raise ValueError('site_host_mismatch')
    for k in ('language','market','currency','device','template'):
        if not isinstance(payload[k],str) or not re.fullmatch(r'[a-zA-Z0-9:_.-]{1,50}',payload[k]):
            raise ValueError('invalid_variant_dim_'+k)
    if payload['device'] not in ('mobile','desktop'):
        raise ValueError('invalid_device')
    if type(payload['css_epoch']) is not int or not 0<=payload['css_epoch']<10**12:
        raise ValueError('invalid_css_epoch')
    for k in ('dependency_sha','candidate_sha'):
        if not isinstance(payload[k],str) or not HEX.fullmatch(payload[k]):
            raise ValueError('invalid_source_pin_'+k)
    return hashlib.sha256(json.dumps(payload,separators=(',',':'),
                        sort_keys=True).encode()).hexdigest()

def histogram_quantile(counts,metric,p=.75):
    limits=BOUNDS[metric]
    if not isinstance(counts,list) or len(counts)!=len(limits)+1:
        raise ValueError('wrong_histogram_width')
    if any(type(v) is not int or v<0 or v>10**8 for v in counts):
        raise ValueError('untrusted_histogram_count')
    total=sum(counts)
    if total==0:return None
    threshold=math.ceil(total*p)
    running=0
    for value,count in zip((*limits,float('inf')),counts):
        running+=count
        if running>=threshold:return value
    raise AssertionError('histogram_quantile_unreachable')

def select(rows,arm,metric,variant):
    result=None
    for row in rows:
        if not isinstance(row,dict) or set(row)!= {'variant','arm','metric','histogram'}:
            raise ValueError('invalid_rum_row')
        if not isinstance(row['variant'],str) or not HEX.fullmatch(row['variant']):
            raise ValueError('invalid_variant_identity')
        if row['arm'] not in ('baseline','canary') or row['metric'] not in METRICS:
            raise ValueError('invalid_metric_arm')
        if row['variant']==variant and row['arm']==arm and row['metric']==metric:
            if result is not None:raise ValueError('duplicate_metric_arm')
            result=row['histogram']
    return result

def decision(cohort,rows,current_percent,proof):
    """Fail closed; never return an executable public rollout command."""
    variant=source_variant(cohort)
    if current_percent not in STEPS or type(current_percent) is not int:
        raise ValueError('invalid_rollout_step')
    if (not isinstance(proof,dict) or set(proof)!=
        {'site','variant','source_current','qa_5_viewports',
         'cache_separation_verified','signed_release_proof'}):
        raise ValueError('missing_release_proof')
    if proof['site']!=cohort['site'] or proof['variant']!=variant:
        raise ValueError('wrong_site_or_source_revision')
    report={'contract':CONTRACT,'variant':variant,'current_percent':current_percent,
            'recommended_percent':current_percent,'action':'hold',
            'reason':'evidence_insufficient','metrics':{},
            'public_traffic_changed':False,'can_issue_certificate':False}
    if not all(proof[k] is True for k in ('source_current','qa_5_viewports',
                   'cache_separation_verified','signed_release_proof')):
        report['reason']='first_party_source_qa_or_cache_gate_incomplete'
        return report
    if current_percent==0:
        report.update(action='eligible_for_manual_canary',recommended_percent=1,
                      reason='all_first_party_release_gates_passed')
        return report
    comparable=True
    bad=[]
    for metric in METRICS:
        base=select(rows,'baseline',metric,variant)
        trial=select(rows,'canary',metric,variant)
        if base is None or trial is None:
            comparable=False;report['metrics'][metric]={'status':'missing'}
            continue
        a=histogram_quantile(base,metric)
        b=histogram_quantile(trial,metric)
        n0=sum(base);n1=sum(trial)
        report['metrics'][metric]={'baseline_p75_upper':a,'canary_p75_upper':b,
                                  'baseline_samples':n0,'canary_samples':n1}
        if n0<MIN_BAD or n1<MIN_BAD:
            comparable=False;continue
        # Coarse histogram boundaries intentionally bias to HOLD instead of
        # claiming small performance improvements that were not measured.
        if b>max(GOOD[metric]*1.2,a*1.25):
            bad.append(metric)
    if bad:
        report.update(action='rollback_recommended',recommended_percent=0,
            reason='rum_p75_regression:'+','.join(sorted(bad)))
        return report
    next_index=STEPS.index(current_percent)+1
    if next_index>=len(STEPS):
        report.update(action='maintain',reason='max_rollout_reached')
        return report
    required=MIN_PROMOTE[STEPS[next_index]]
    if not comparable or any(
        item.get('baseline_samples',0)<required or
        item.get('canary_samples',0)<required
        for item in report['metrics'].values()):
        report['reason']='insufficient_per_metric_comparable_samples'
        return report
    if any(report['metrics'][m]['canary_p75_upper']>GOOD[m] or
           report['metrics'][m]['canary_p75_upper']>
            report['metrics'][m]['baseline_p75_upper']*1.02
           for m in METRICS):
        report['reason']='absolute_or_relative_p75_guard'
        return report
    report.update(action='eligible_for_manual_ramp',
                  recommended_percent=STEPS[next_index],
                  reason='rum_and_release_proofs_passed')
    return report
