#!/usr/bin/env python3
"""Read-only exact original Source Staging mobile home admission.

The event gate is NEVER allowed to record ready on a six-locale snapshot
whose newest trusted HTTP CSS/Elementor identity is not covered by a measured
current per-route source manifest. This cannot publish a CSS certificate.
"""
import json,re,sys,time
from pathlib import Path

CONTRACT='mad4b.source-home-css-change-observation.v1'
HOST='tours.dev.mad4b.com'
LANGS={'en','es','de','fr','ar','it'}

def evaluate(report,now=None):
    now=int(time.time() if now is None else now)
    p=Path(report)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>16384:
        return False,'unavailable_or_untrusted'
    try:
        d=json.loads(p.read_text())
    except (OSError,TypeError,ValueError,json.JSONDecodeError):
        return False,'invalid_source_receipt'
    seen=d.get('checked_at')
    if (d.get('contract')!=CONTRACT or d.get('host')!=HOST
        or d.get('scope')!='trigger_exact_locale_browser_QA_not_a_certificate'
        or not isinstance(seen,int) or seen>now+60 or now-seen>5400
        or not isinstance(d.get('fingerprint'),str)
        or not re.fullmatch('[a-f0-9]{64}',d['fingerprint'])):
        return False,'source_receipt_contract_or_age_invalid'
    rows=d.get('sources')
    if not isinstance(rows,dict) or set(rows)!=LANGS:
        return False,'incomplete_wpml_source_coverage'
    for locale,row in rows.items():
        if (not isinstance(row,dict)
            or row.get('route')!=('/' if locale=='en' else '/'+locale+'/')
            or not re.fullmatch(r'[a-f0-9]{64}',str(row.get('sha256','')))
            or not re.fullmatch(r'[a-f0-9]{64}',str(row.get('elementor_doc_fingerprint','')))):
            return False,'unverified_source_locale'
    missing=d.get('languages_without_exact_source_certificate')
    if not isinstance(missing,list) or len(missing)>6 or len(set(missing))!=len(missing) or any(x not in LANGS for x in missing):
        return False,'untrusted_source_drift_detail'
    if d.get('state')=='drift' or missing:
        return False,'unmatched_source_css_or_elementor_graph'
    if d.get('state')!='source_matched':
        return False,'unrecognized_source_status'
    return True,'verified_six_locale_source_identity'

if __name__=='__main__':
    if len(sys.argv)!=2:sys.exit(2)
    okay,reason=evaluate(sys.argv[1])
    print(('READY' if okay else 'HOLD')+'_SOURCE_HOME_DELIVERY_ADMISSION',reason)
    sys.exit(0 if okay else 10)
