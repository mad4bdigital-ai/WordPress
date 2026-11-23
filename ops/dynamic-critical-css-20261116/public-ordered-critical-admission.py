#!/usr/bin/env python3
"""Read-only approval boundary for offline multi-CSS Critical CSS candidates.

A source manifest and browser parity do NOT prove performance. Only a
complete, context-pinned five-viewport performance proof can advance to
delivery review. This emits no public certificates and never touches WP.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
import pathlib
import re
import sys
from pathlib import Path
HERE=Path(__file__).resolve().parent
sp=importlib.util.spec_from_file_location('css_dependency_manifest',HERE/'public-css-dependency-manifest.py')
css=importlib.util.module_from_spec(sp);sp.loader.exec_module(css)
CONTRACT='mad4b.public-css.ordered-critical-admission.v1'
VIEWPORTS=[320,390,414,767,768]
def load(path):
    p=Path(path)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>2_000_000:
        raise ValueError('untrusted-private-qa-artifact')
    return json.loads(p.read_text())
def sha(data):return hashlib.sha256(data).hexdigest()
def proof(directory):
    directory=Path(directory)
    if directory.is_symlink() or not directory.is_dir():
        raise ValueError('untrusted-private-qa-directory')
    manifest=load(directory/'css-dependencies.json')
    observer=load(directory/'multi-css-source-report.json')
    candidate=load(directory/'ordered-critical-candidate.json')
    qa=load(directory/'ordered-native-critical-qa.json')
    parity=load(directory/'browser-parity.json')
    host=manifest.get('host')
    key=manifest.get('manifest_sha256')
    if (manifest.get('contract')!=css.CONTRACT or
        not re.fullmatch('[a-f0-9]{64}',key or '') or
        sha(css.canonical({k:v for k,v in manifest.items()
            if k!='manifest_sha256'}))!=key):
        raise ValueError('tampered-ordered-css-manifest')
    if (observer.get('contract')!='mad4b.public-css.manifest-observer.v1'
        or observer.get('manifest_sha256')!=key
        or observer.get('host')!=host
        or not observer.get('css_source_proven')
        or observer.get('browser_fcp_lcp_cls_qa_complete')
        or observer.get('public_css_route_certificate_issued')
        or observer.get('fastcgi_ready_for_promotion')):
        raise ValueError('source-context-or-proof-not-immutable')
    frozen=directory/'source.html'
    if (not re.fullmatch('[a-f0-9]{64}',observer.get('original_html_sha256',''))
        or frozen.is_symlink() or not frozen.is_file()
        or sha(frozen.read_bytes())!=observer['original_html_sha256']):
        raise ValueError('original-html-snapshot-unbound')
    if (candidate.get('contract')!='mad4b.public-css.ordered-critical-candidate.v1'
        or candidate.get('manifest_sha256')!=key
        or candidate.get('host')!=host
        or candidate.get('path')!=observer.get('path')
        or candidate.get('viewports')!=[320,390,414,767,768]
        or candidate.get('critical_viewports')!=[320,390,414,767]
        or candidate.get('desktop_validation_viewports')!=[768]
        or candidate.get('critical_total_bytes',-1)>1048576
        or candidate.get('source_cascade_slots')!=observer.get('cascade_slots')
        or candidate.get('observation_count')!=5
        or candidate.get('public_route_certificate_issued') is not False):
        raise ValueError('untrusted-critical-source-observation')
    if (parity.get('contract')!='mad4b.ordered-css.browser-parity.qa.v1'
        or parity.get('host')!=host or parity.get('pathname')!=observer.get('path')
        or parity.get('manifest_sha256')!=key or parity.get('pass') is not True
        or set(parity.get('widths',[]))!=set(VIEWPORTS)
        or len(parity.get('results',[]))!=5):
        raise ValueError('missing-original-full-css-cascade-parity')
    if (qa.get('contract')!='mad4b.public-css.ordered-critical-real-origin-qa.v1'
        or qa.get('manifest_sha256')!=key or qa.get('host')!=host
        or qa.get('path')!=observer.get('path')
        or qa.get('source_css_mode')!='original-css-urls'
        or not re.fullmatch('[a-f0-9]{64}',qa.get('critical_candidate_sha256',''))
        or qa['critical_candidate_sha256']!=sha((directory/'ordered-critical-candidate.json').read_bytes())
        or len(qa.get('viewport_results',[]))!=5
        or qa.get('public_certificate_issued') is not False
        or qa.get('fastcgi_purged') is not False):
        raise ValueError('incomplete-or-unpinned-real-origin-performance-QA')
    source_chunks=load(directory/'compiled-chunks.json')
    source=source_chunks.get('chunks')
    crit=candidate.get('critical_slots')
    if (source_chunks.get('manifest_sha256')!=key
        or not isinstance(source,list) or not isinstance(crit,list)
        or len(source)!=len(crit)
        or sum(x.get('critical_bytes',0) for x in crit)!=candidate['critical_total_bytes']):
        raise ValueError('critical-chunk-accounting-drift')
    for i,(src,item) in enumerate(zip(source,crit)):
        if (not isinstance(src.get('slot'),int)
            or src['slot']!=item.get('slot')
            or src.get('sha256')!=item.get('source_sha256')
            or src.get('bytes')!=item.get('source_bytes')
            or not re.fullmatch('[a-f0-9]{64}',item.get('critical_sha256',''))
            or not isinstance(item.get('critical_bytes'),int)
            or item['critical_bytes']<0):
            raise ValueError('critical-chunk-source-identity-mismatch')
        if item['critical_bytes']:
            name=item.get('critical_file')
            if name!=item['critical_sha256']+'.css':
                raise ValueError('critical-chunk-name-drift')
            target=directory/name
            if (target.is_symlink() or not target.is_file()
                or target.stat().st_size!=item['critical_bytes']
                or sha(target.read_bytes())!=item['critical_sha256']):
                raise ValueError('critical-css-content-sha-drift')
    metrics=[]
    for width,row in zip(VIEWPORTS,qa['viewport_results']):
        if row.get('width')!=width:raise ValueError('viewport-order-and-proof-drift')
        native=row.get('source',{})
        adaptive=row.get('critical',{})
        if (not isinstance(row.get('fcp_gain_ms'),(int,float))
            or not isinstance(row.get('lcp_reg_ms'),(int,float))
            or not isinstance(adaptive.get('cls'),(int,float))
            or not isinstance(native.get('lcp'),(int,float))):
            raise ValueError('invalid-QA-performance-metrics')
        metrics.append({'width':width,'fcp_gain_ms':row['fcp_gain_ms'],
            'lcp_reg_ms':row['lcp_reg_ms'],'cls':adaptive['cls']})
    performance_passed=(qa['pass'] is True
        and all(r.get('pass') is True and r.get('layout_pass') is True
                and not r.get('failed_css') and not r.get('errors')
                and (r['width']>=768 or (r.get('real_css_links_loaded') is True
                     and r.get('stylesheet_count')==len(manifest['files'])))
                and r['lcp_reg_ms']<=max(400,r['source']['lcp']*.1)
                and (r['width']>=768 or r['fcp_gain_ms']>=150)
                for r in qa['viewport_results']))
    return {'contract':CONTRACT,'host':host,
        'path':observer['path'],'language':observer['language'],
        'market_country':observer['market_country'],'currency':observer['currency'],
        'source_manifest_sha256':key,
        'total_critical_bytes':candidate['critical_total_bytes'],
        'verified_source_cascade_slots':len(crit),
        'viewport_results':metrics,
        'performance_source':'real-original-css-urls',
        'decision':'qualified_for_delivery_review' if performance_passed
                   else 'hold_performance_or_interaction',
        'wordpress_runtime_authorized':False,
        'fastcgi_public_ready':False,
        'public_certificate_published':False}
if __name__=='__main__':
    if len(sys.argv)!=2:raise SystemExit('USAGE: private-job-directory')
    try:print('ORDERED_CRITICAL_ADMISSION',json.dumps(proof(sys.argv[1]),sort_keys=True))
    except (OSError,ValueError,KeyError,TypeError,IndexError,AttributeError,json.JSONDecodeError) as e:
        print('HOLD_ORDERED_CRITICAL_ADMISSION',str(e)[:190],file=sys.stderr);sys.exit(4)
