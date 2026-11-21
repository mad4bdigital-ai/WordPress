#!/usr/bin/env python3
"""Site-scoped WPML coverage with SHA-keyed fair retries and per-language gates.

Source of truth is the authenticated site's current WPML language list.
Every *eligible* active locale gets a retry; a held locale cannot starve new
or independently certifiable locales. The only promoted decisions still come
from first-party Chromium QA and the installed original CSS publisher.
"""
import json,os,re,sys,time,tempfile
from pathlib import Path

def valid_locale(lang):
    return isinstance(lang,str) and re.fullmatch(r'[a-z]{2}',lang) is not None
def assert_contract(selection,site):
    loc=site.get('wpml_languages',[])
    assert 1<=len(loc)<=20 and len(set(loc))==len(loc) and all(map(valid_locale,loc)), 'Invalid WPML language registry'
    assert site.get('default_language') in loc, 'Missing WPML default language'
    rows=selection.get('routes',[])
    assert selection.get('contract')=='mad4b.critical-css.dynamic-selection.v1', 'Wrong selection contract'
    assert selection.get('css_epoch')==site['css_epoch'] and len(rows)==len(loc),'CSS epoch or WPML membership drift'
    assert len({r['locale'] for r in rows})==len(rows) and {r['locale'] for r in rows}==set(loc),'Unknown or omitted locale'
    for r in rows:
        l=r['locale']
        expected='/' if l==site['default_language'] else '/'+l+'/'
        if r.get('route',expected)!=expected:raise ValueError('Unexpected WPML homepage route '+l)
    return rows
def load_history(file,site):
    if not file.exists():return {'contract':'mad4b.critical-css.wpml-coverage.v1','host':site['site_host'],'attempts':{}}
    h=json.loads(file.read_text())
    if h.get('contract')!='mad4b.critical-css.wpml-coverage.v1' or h.get('host')!=site['site_host']:
        raise ValueError('Cross-company coverage history refused')
    if not isinstance(h.get('attempts'),dict):raise ValueError('Malformed coverage history')
    return h
def current_entry(history,lang,sha,epoch):
    x=history['attempts'].get(lang,{})
    if x.get('css_sha')==sha and x.get('css_epoch')==epoch:return x
    return {'css_sha':sha,'css_epoch':epoch,'last_attempt':0,'failures':0,'retry_after':0}
def select(selection,site,history,now,batch_limit=2):
    rows=assert_contract(selection,site)
    assert 1<=batch_limit<=2
    expected={r['locale']:r for r in rows}
    backlog=[]
    for lang in site['wpml_languages']:
        row=expected[lang]
        if row.get('status')!='needs_native_browser_qa':continue
        sha=row.get('css_sha')
        if not isinstance(sha,str) or not re.fullmatch(r'[a-f0-9]{64}',sha):continue
        state=current_entry(history,lang,sha,site['css_epoch'])
        if now < state.get('retry_after',0):continue
        backlog.append((int(state.get('last_attempt',0)),lang))
    # Untested and least-recently-tested first. WPML list is the authority;
    # no EN/ES/FR/DE/AR/IT or company-specific language priority.
    order={v:i for i,v in enumerate(site['wpml_languages'])}
    backlog.sort(key=lambda e:(e[0],order[e[1]]))
    return [lang for _,lang in backlog[:batch_limit]]
def record(selection,site,history,batch,qa,now,reason=None):
    rows=assert_contract(selection,site);indexed={r['locale']:r for r in rows}
    if not batch or len(batch)>2 or len(set(batch))!=len(batch):raise ValueError('Invalid attempted batch')
    for lang in batch:
        r=indexed[lang]
        if r['status']!='needs_native_browser_qa':raise ValueError('Cannot record an active or ineligible locale')
        sha=r.get('css_sha')
        if not isinstance(sha,str) or not re.fullmatch(r'[a-f0-9]{64}',sha):raise ValueError('No trustworthy source SHA')
        current=current_entry(history,lang,sha,site['css_epoch'])
        gate=(qa or {}).get('gates',{}).get(lang,{})
        measured=[v for v in (qa or {}).get('results',[]) if v.get('lang')==lang]
        expected={320,390,414,767,768} if selection.get('site')=='source' else {320,390,414,768}
        passed=(reason is None and gate.get('decision')=='passed_evidence'
             and expected.issubset(set(gate.get('widths',[])))
             and {v['width'] for v in measured}==expected
             and all(v.get('pass') is True for v in measured))
        fails=0 if passed else min(20,int(current.get('failures',0))+1)
        # Runtime constraints don't disappear: retries are spaced but finite
        # and a CSS SHA / CSS epoch change invalidates old cooldown entirely.
        backoff=0 if passed else min(4*3600,15*60*2**min(fails-1,4))
        history['attempts'][lang]={
            'css_sha':sha,'css_epoch':site['css_epoch'],'last_attempt':int(now),
            'failures':fails,'retry_after':int(now)+backoff,
            'last_result':'browser_qa_pass' if passed else 'hold_'+str(reason or gate.get('decision','incomplete'))
        }
    return history
def atomic_write(file,data):
    file.parent.mkdir(parents=True,exist_ok=True)
    fd,path=tempfile.mkstemp(prefix='.coverage-',dir=file.parent)
    try:
        with os.fdopen(fd,'w') as f:
            os.fchmod(f.fileno(),0o600);json.dump(data,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.replace(path,file)
    finally:
        if os.path.exists(path):os.unlink(path)
def main():
    action,sel,st,progress,batch,*other=sys.argv[1:]
    selection=json.loads(Path(sel).read_text());site=json.loads(Path(st).read_text())
    assert_contract(selection,site)
    hist=load_history(Path(progress),site)
    now=int(time.time())
    if action=='select':
        chosen=select(selection,site,hist,now)
        Path(batch).write_text(','.join(chosen))
        print('WPML_AUTO_COVERAGE',json.dumps({'active_languages':site['wpml_languages'],
          'certified':[r['locale'] for r in selection['routes'] if r['status']=='certified_active'],
          'batch':chosen,'deferred':[r['locale'] for r in selection['routes'] if r['status']=='needs_native_browser_qa' and r['locale'] not in chosen],
          'holds':[r['locale'] for r in selection['routes'] if r['status'].startswith('hold_')]},sort_keys=True))
        return
    if action=='record':
        langs=[x for x in Path(batch).read_text().split(',') if x]
        qa=json.loads(Path(other[0]).read_text()) if other and Path(other[0]).is_file() else None
        reason=other[1] if len(other)>1 and other[1]!='none' else None
        hist=record(selection,site,hist,langs,qa,now,reason)
        atomic_write(Path(progress),hist)
        print('WPML_BATCH_OUTCOMES',json.dumps({l:hist['attempts'][l] for l in langs},sort_keys=True))
        return
    raise ValueError('Unknown coverage action')
if __name__=='__main__':
    try:main()
    except (ValueError,TypeError,KeyError,IndexError,AssertionError,OSError) as e:
        print('HOLD_WPML_COVERAGE_CONTRACT',str(e),file=sys.stderr);sys.exit(4)
