#!/usr/bin/env python3
"""Classify fully measured but non-beneficial Critical CSS as native-preferred.

This is NOT a performance certification. It prevents repeated 5-viewport
Chromium runs when full native CSS is consistently the safer/faster route.
A partial capture, broken interaction or source drift is still a retry HOLD.
"""
import json,math,sys
WIDTHS={320,390,414,767,768}
def is_native_preferred(result,lang):
    if (not isinstance(result,dict) or result.get('tested')!=5):
        return False
    gate=result.get('gates',{}).get(lang,{})
    if gate.get('decision')!='hold' or set(gate.get('widths',[]))!=WIDTHS:
        return False
    rows=result.get('results')
    if not isinstance(rows,list) or len(rows)!=5 or {r.get('width') for r in rows}!=WIDTHS:
        return False
    lacking_gain=False
    for row in rows:
        if row.get('lang')!=lang or row.get('pass') not in (True,False):return False
        b=row.get('baseline');v=row.get('variant')
        if not isinstance(b,dict) or not isinstance(v,dict):return False
        for x in (b,v):
            if x.get('error') or x.get('status')!=200:return False
            if not isinstance(x.get('fcp'),(int,float)) or not math.isfinite(x['fcp']) or x['fcp']<=0:
                return False
            if not isinstance(x.get('lcp'),(int,float)) or not math.isfinite(x['lcp']) or x['lcp']<=0:
                return False
        if (v.get('rewritten') is not True or v.get('styleLoaded') is not True
            or v.get('cssMedia')!='all' or v.get('failedCss')!=[]
            or v.get('errors')!=[] or v.get('overflow',1)>0):return False
        if (v.get('h1')!=b.get('h1') or v.get('hero')!=b.get('hero')
            or (b.get('popupOpened') and not v.get('popupOpened'))
            or (b.get('calendarOpened') and not v.get('calendarOpened'))):return False
        if not isinstance(v.get('cls'),(int,float)) or v['cls']>0.10 or v['cls']>b.get('cls',0)+0.02:
            return False
        gain=row.get('fcpGain')
        if not isinstance(gain,(int,float)) or not math.isfinite(gain):return False
        threshold=-250 if row['width']>767 else 150
        if row['pass'] is False:
            if gain>=threshold:return False  # failure was not just poor FCP gain
            lacking_gain=True
    return lacking_gain
if __name__=='__main__':
    if len(sys.argv)!=3:raise SystemExit(4)
    try:
        q=json.load(open(sys.argv[1]));print('NATIVE_PREFERRED',is_native_preferred(q,sys.argv[2]))
    except (OSError,ValueError,TypeError,KeyError):raise SystemExit(4)
