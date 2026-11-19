#!/usr/bin/env python3
"""Evidence-only per-locale LCP remediation for source WPML critical CSS.

When and only when a fully measured primary 767px QA fails exclusively
on a 767px LCP regression, schedule an independent narrower 599px trial.
Keep the baseline failure report; accept only all-seven-viewport passing
evidence with the original exact CSS/Elementor capture identity.
"""
import json,sys,re,shutil,hashlib
from pathlib import Path

PRIMARY={320,390,414,767,768}
SAFE={320,390,414,599,600,767,768}
def read(path):return json.loads(Path(path).read_text())
def qualifies_for_independent_599_trial(primary,lang):
    gate=primary.get('gates',{}).get(lang,{})
    cases=[r for r in primary.get('results',[]) if r.get('lang')==lang]
    if (gate.get('decision')!='hold' or set(gate.get('widths',[]))!=PRIMARY
        or gate.get('adaptiveMaxWidth')!=767
        or len(cases)!=len(PRIMARY) or {r.get('width') for r in cases}!=PRIMARY
        or any(r.get('pass') is not True for r in cases if r['width'] not in (767,768))):
        return False
    desktop=next(c for c in cases if c['width']==768)
    if desktop.get('pass') is False:
        b=desktop.get('baseline',{});v=desktop.get('variant',{})
        gain=desktop.get('fcpGain');reg=desktop.get('lcpReg')
        if (not isinstance(gain,(int,float)) or gain<-500
            or not isinstance(reg,(int,float)) or reg>400
            or b.get('status')!=200 or v.get('status')!=200
            or v.get('rewritten') is not True
            or v.get('errors') or v.get('failedCss')):
            return False
    elif desktop.get('pass') is not True:
        return False
    failed=next(c for c in cases if c['width']==767)
    base=failed.get('baseline',{});cand=failed.get('variant',{})
    lcp=failed.get('lcpReg',0)
    return (failed.get('pass') is False
        and isinstance(lcp,(int,float)) and isinstance(base.get('lcp'),(int,float))
        and lcp>max(400,base['lcp']*0.1)
        and cand.get('status')==200 and base.get('status')==200
        and cand.get('rewritten') is True
        and not cand.get('errors') and not cand.get('failedCss'))

def safe_candidates(primary,inventory,selection,source):
    if (primary.get('contract')!='mad4b.mobile-critical.qa.v1'
        or inventory.get('contract')!='mad4b.mobile-critical.candidates.v1'
        or inventory.get('host')!='tours.dev.mad4b.com'
        or selection.get('site')!='source' or source.get('site_host')!='tours.dev.mad4b.com'
        or source.get('native_breakpoint')!=767):raise ValueError('source-QA-contract-mismatch')
    expected={r['locale']:r for r in selection['routes']}
    chosen=[]
    for row in inventory['candidates']:
        lang=row['lang'];route=row['route']
        if (not re.fullmatch('[a-z]{2}',lang) or
            expected.get(lang,{}).get('status')!='needs_native_browser_qa'
            or expected.get(lang,{}).get('route')!=route
            or expected.get(lang,{}).get('css_sha')!=row['source_sha']):
            continue
        if not qualifies_for_independent_599_trial(primary,lang):
            continue
        chosen.append(row)
    return chosen

def select(qa_path,inventory_path,selection_path,state_path,outdir):
    primary,inventory,selection,state=map(read,(qa_path,inventory_path,selection_path,state_path))
    rows=safe_candidates(primary,inventory,selection,state)
    out=Path(outdir)
    out.mkdir(mode=0o700,parents=True,exist_ok=True)
    result={'contract':'mad4b.critical-css.safe-cap-attempt.v1',
            'site':'tours.dev.mad4b.com',
            'source_epoch':state['css_epoch'],
            'languages':[r['lang'] for r in rows]}
    (out/'batch.txt').write_text(','.join(result['languages'])+'\n')
    (out/'decision.json').write_text(json.dumps(result,indent=2)+'\n')
    if rows:
        (out/'candidates.json').write_text(json.dumps({**inventory,'candidates':rows},indent=2)+'\n')
        for r in rows:
            name=r['critical_file']
            if not re.fullmatch('[a-f0-9]{64}[.]css',name):
                raise ValueError('critical-css-filename-invalid')
            src=Path(qa_path).parent/name;dest=out/name
            if src.is_symlink() or not src.is_file() or src.stat().st_size!=r['critical_bytes'] \
                or hashlib.sha256(src.read_bytes()).hexdigest()!=r['critical_sha']:
                raise ValueError('critical-source-hash-invalid')
            shutil.copyfile(src,dest)
    return result

def merge(primary_path,trial_path,decision_path):
    primary=read(primary_path);trial=read(trial_path);decision=read(decision_path)
    expected=decision.get('languages',[])
    if (decision.get('contract')!='mad4b.critical-css.safe-cap-attempt.v1'
        or decision.get('site')!='tours.dev.mad4b.com'
        or not expected or len(expected)>2 or len(expected)!=len(set(expected))
        or trial.get('contract')!='mad4b.mobile-critical.qa.v1'
        or set(trial.get('gates',{}))!=set(expected)):
        raise ValueError('untrusted-safe-cap-fallback-evidence')
    accepted=[]
    for lang in expected:
        g=trial['gates'][lang]
        cases=[r for r in trial.get('results',[]) if r.get('lang')==lang]
        good=(g.get('decision')=='passed_evidence'
           and g.get('adaptiveMaxWidth')==599
           and set(g.get('widths',[]))==SAFE and len(g.get('widths',[]))==len(SAFE)
           and len(cases)==len(SAFE) and {r.get('width') for r in cases}==SAFE
           and all(r.get('pass') is True for r in cases))
        if not good:continue
        # Fail closed when the fallback label changes or the primary failed
        # at some other width. Recheck the original reason before promoting.
        original=[r for r in primary['results'] if r.get('lang')==lang]
        if not qualifies_for_independent_599_trial(primary,lang):
            raise ValueError('original-failed-locale-not-eligible')
        primary['results']=[r for r in primary['results'] if r.get('lang')!=lang]+cases
        primary['gates'][lang]=g
        accepted.append(lang)
    primary['tested']=len(primary['results'])
    return primary,accepted

def main():
    if sys.argv[1]=='select':
        r=select(*sys.argv[2:])
        print('SOURCE_SAFE_CAP_SELECT',json.dumps(r,sort_keys=True))
    elif sys.argv[1]=='merge':
        source,trial,decision,target=sys.argv[2:]
        merged,accepted=merge(source,trial,decision)
        temp=Path(target).with_suffix('.safe-cap-next.json')
        temp.write_text(json.dumps(merged,indent=2)+'\n')
        temp.replace(target)
        print('SOURCE_SAFE_CAP_MERGE',json.dumps({'accepted':accepted,'tested':merged['tested']}))
    else:raise ValueError('Unsupported safe-cap operation')
if __name__=='__main__':
    try:main()
    except (ValueError,KeyError,TypeError,IndexError,OSError,AssertionError) as e:
        print('HOLD_SAFE_CAP_FALLBACK',str(e),file=sys.stderr)
        sys.exit(4)
