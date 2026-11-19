#!/usr/bin/env python3
"""Independent complete per-locale Chromium QA admission.

Never treats an incomplete viewport series as a pass, even if the aggregate
test runner succeeded. Source may publish each successful v2 certificate
without holding back other translations. Wayfa keeps its existing v1
per-locale exact-low-preload-off strategy, with independent 4-width evidence.
"""
import json,re,sys
from pathlib import Path

# CSS coverage is captured at the native four widths. The separate QA
# stage additionally tests 767px, the exact Elementor mobile edge, on source.
CAPTURE_VIEWPORTS={320,390,414,768}
VIEWPORTS={'source':{320,390,414,767,768},'wayfa':{320,390,414,768}}
SITE_HOST={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}
def evaluate(site,batch,inventory,qa,selection):
    if site not in VIEWPORTS:raise ValueError('Invalid site')
    valid=VIEWPORTS[site]
    if not batch or len(batch)>2 or len(set(batch))!=len(batch) or any(not re.fullmatch(r'[a-z]{2}',x) for x in batch):
        raise ValueError('Invalid WPML QA batch')
    if (inventory.get('contract')!='mad4b.mobile-critical.candidates.v1'
        or inventory.get('host')!=SITE_HOST[site] or inventory.get('errors')
        or selection.get('site')!=site):
        raise ValueError('Untrusted site-scoped captured CSS')
    if qa.get('contract')!='mad4b.mobile-critical.qa.v1' or not isinstance(qa.get('results'),list):
        raise ValueError('Untrusted Chromium result')
    rows=inventory.get('candidates',[])
    if len(rows)!=len(batch) or {r['lang'] for r in rows}!=set(batch):
        raise ValueError('Incomplete or foreign candidates')
    expected={r['locale']:r for r in selection['routes']}
    accepted=[];held=[]
    for row in rows:
        lang=row['lang']
        selected=expected.get(lang,{})
        if (selected.get('status')!='needs_native_browser_qa'
            or selected.get('css_sha')!=row.get('source_sha')
            or selected.get('route')!=row.get('route')):
            held.append({'locale':lang,'decision':'hold_source_sha_or_route_drift'})
            continue
        if (set(row.get('widths',[]))!=CAPTURE_VIEWPORTS
            or len(row['widths'])!=len(CAPTURE_VIEWPORTS)
            or not re.fullmatch(r'[a-f0-9]{64}',str(row.get('critical_sha','')))):
            held.append({'locale':lang,'decision':'hold_capture_or_critical_asset_invalid'})
            continue
        gate=qa.get('gates',{}).get(lang,{})
        # Only source v2 can carry a narrower critical cap. Its certificate
        # requires seven Chromium viewports, including both newly introduced
        # 599/600px and original Elementor 767/768px boundaries.
        measured_widths=({320,390,414,599,600,767,768}
            if site=='source' and gate.get('adaptiveMaxWidth')==599 else valid)
        measured=[v for v in qa['results'] if v.get('lang')==lang]
        good=(gate.get('decision')=='passed_evidence'
             and set(gate.get('widths',[]))==measured_widths
             and len(gate.get('widths',[]))==len(measured_widths)
             and {v.get('width') for v in measured}==measured_widths
             and len(measured)==len(measured_widths)
             and all(v.get('pass') is True and isinstance(v.get('fcpGain'),(int,float))
                     and isinstance(v.get('lcpReg'),(int,float)) for v in measured))
        if site=='wayfa' and gate.get('adaptiveMaxWidth',0) not in (0,767):
            good=False
        if good:accepted.append(row)
        else:held.append({'locale':lang,'decision':gate.get('decision','missing'),
                           'viewports':[v.get('width') for v in measured]})
    report={'contract':'mad4b.mobile-critical.locale-admission.v1',
            'site':site,'accepted':[x['lang'] for x in accepted],'held':held,
            'expected_viewports':sorted(valid)}
    subset={**inventory,'candidates':accepted}
    return report,subset

def main():
    site,batchfile,candidatefile,qafile,selectionfile,reportfile,subsetfile=sys.argv[1:]
    batch=[x for x in Path(batchfile).read_text().strip().split(',') if x]
    c=json.loads(Path(candidatefile).read_text())
    q=json.loads(Path(qafile).read_text())
    s=json.loads(Path(selectionfile).read_text())
    report,subset=evaluate(site,batch,c,q,s)
    Path(reportfile).write_text(json.dumps(report,sort_keys=True,indent=2)+'\n')
    Path(subsetfile).write_text(json.dumps(subset,sort_keys=True,indent=2)+'\n')
    print('PER_LOCALE_QA_ADMISSION',json.dumps(report,sort_keys=True))
    # Both source v2 and enrolled Wayfa v1 can now process independently
    # certified locales. The legacy publisher still checks its exact strategy,
    # four measured viewports, consumer SHA, assets and final source identity.
    if not report['accepted']:
        sys.exit(3)
    return

if __name__=='__main__':
    try:main()
    except (AssertionError,ValueError,TypeError,KeyError,IndexError,OSError) as e:
        print('HOLD_QA_LOCALE_ADMISSION',str(e),file=sys.stderr)
        sys.exit(4)
