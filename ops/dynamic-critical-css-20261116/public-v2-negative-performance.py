#!/usr/bin/env python3
"""Classify *candidate only* when complete ordered-V2 QA shows mobile LCP nonbenefit.

A result can only propose an independently retested native_candidate. It is
NEVER a certified CSS result, a native_preferred terminal decision, an authority
to change release state, or a replacement for original WordPress readback.
"""
import math

WIDTHS=(320,390,414,767,768)
QA='mad4b.public-css.ordered-critical-real-origin-qa.v1'
ADMISSION='mad4b.public-css.ordered-critical-admission.v1'

def number(v):
    return type(v) in (int,float) and math.isfinite(v)

def is_v2_lcp_negative_candidate(admitted,qa):
    if (not isinstance(admitted,dict) or not isinstance(qa,dict)
        or admitted.get('contract')!=ADMISSION
        or qa.get('contract')!=QA
        or admitted.get('decision')!='hold_performance_or_interaction'
        or qa.get('pass') is not False
        or qa.get('smoke_only') is not False
        or qa.get('public_certificate_issued') is not False
        or qa.get('fastcgi_purged') is not False
        or admitted.get('source_manifest_sha256')!=qa.get('manifest_sha256')
        or admitted.get('host')!=qa.get('host')
        or admitted.get('path')!=qa.get('path')
        or admitted.get('performance_source')!='real-original-css-urls'
        or not isinstance(qa.get('viewport_results'),list)
        or len(qa['viewport_results'])!=len(WIDTHS)):
        return False
    negative=0
    for width,row in zip(WIDTHS,qa['viewport_results']):
        if not isinstance(row,dict) or row.get('width')!=width:return False
        source=row.get('source');critical=row.get('critical')
        if not isinstance(source,dict) or not isinstance(critical,dict):return False
        for metric in ('fcp','lcp'):
            if (not number(source.get(metric)) or source[metric]<=0
                or not number(critical.get(metric)) or critical[metric]<=0):
                return False
        for metric in ('cls',):
            if (not number(source.get(metric)) or not number(critical.get(metric))
                or source[metric]<0 or critical[metric]<0):
                return False
        if (not number(row.get('fcp_gain_ms'))
            or not number(row.get('lcp_reg_ms'))
            or row.get('layout_pass') is not True
            or row.get('unequal_late_lcp') is not False
            or row.get('failed_css')!=[]
            or row.get('errors')!=[]
            or critical['cls']>max(.10,source['cls']+.02)
            or (source.get('popup') is True and critical.get('popup') is not True)):
            return False
        if row.get('peer_order_confirmation') is not None:
            # An opposite-order failure is noise, not stable nonbenefit.
            proof=row['peer_order_confirmation']
            if not isinstance(proof,dict) or proof.get('pass') is not True:
                return False
        bound=max(400,source['lcp']*.1)
        if width<768:
            if (row.get('real_css_links_loaded') is not True
                or type(row.get('stylesheet_count')) is not int
                or row['stylesheet_count']<=0
                or row['fcp_gain_ms']<150):
                return False
            if row['lcp_reg_ms']>bound:
                if row.get('pass') is not False:return False
                negative+=1
            elif row.get('pass') is not True:return False
        elif row.get('pass') is not True:
            return False
    return negative>=1
