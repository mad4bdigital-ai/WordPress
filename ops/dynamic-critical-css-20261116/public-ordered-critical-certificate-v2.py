#!/usr/bin/env python3
"""Source-owned v2 critical CSS certificate publisher for the existing 422 runtime.

Never issues a certificate from compiler output alone: it needs original
first-party WPML catalog route, current event generations, fully pinned
manifest and five-viewport *real-CSS-URL* performance/admission evidence.
Dry run does not touch WordPress. Publishing is separately gated by exact
deployed 422 + companion hashes and a release-specific opt-in.
"""
from __future__ import annotations
import hashlib
import importlib.util
import json
import os
import pathlib
import re
import stat
import sys
import tempfile
from pathlib import Path

HERE=Path(__file__).resolve().parent
def module(filename,name):
    spec=importlib.util.spec_from_file_location(name,HERE/filename)
    obj=importlib.util.module_from_spec(spec)
    spec.loader.exec_module(obj)
    return obj
admission=module('public-ordered-critical-admission.py','mad4b_multicss_admission')
ordered=module('public-css-dependency-manifest.py','mad4b_multicss_manifest')
legacy=module('public-route-certificate.py','mad4b_original_queried_object')
CONTRACT='mad4b.public-critical-css.route-certificate.v2'
VIEWS=[320,390,414,767,768]
LIVE_ROOTS={
 '/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy/wp-content',
 '/opt/wayfa-rebuild-20261102/site-copy/wp-content'
}
def sha(data):return hashlib.sha256(data).hexdigest()
def data(file,limit=2_000_000):
    p=Path(file)
    if p.is_symlink() or not p.is_file() or p.stat().st_size>limit:raise ValueError('invalid-immutable-evidence')
    return json.loads(p.read_text())
def guard_int(value,name):
    if not isinstance(value,int) or isinstance(value,bool) or value<0:
        raise ValueError('untrusted-'+name)
    return value
def certificate(catalog,route,state,job,wp_content,runtime,consumer):
    decision=admission.proof(job)
    if decision['decision']!='qualified_for_delivery_review':
        raise ValueError('multi-css-FCP-LCP-or-interaction-qualification-HOLD')
    original=data(Path(job)/'multi-css-source-report.json')
    source_manifest=data(Path(job)/'css-dependencies.json')
    candidate=data(Path(job)/'ordered-critical-candidate.json')
    native=data(Path(job)/'ordered-native-critical-qa.json')
    full_html=(Path(job)/'source.html').read_text()
    if (catalog.get('contract')!='mad4b.public-critical-css.routes.v1'
        or route not in catalog.get('routes',[])
        or state.get('critical_css')!='on'
        or state.get('site_host')!=catalog.get('host')
        or catalog.get('host')!=decision['host']
        or route.get('path')!=decision['path']
        or route.get('language')!=decision['language']
        or route.get('type') not in legacy.ALLOWED_PUBLIC
        or route.get('id') in (None,False)
        or route.get('path') in ('/','/'+str(route.get('language'))+'/')
        or original.get('route_key')!=route.get('route_key')
        or not isinstance(route.get('revision'),int)
        or isinstance(route['revision'],bool)):
        raise ValueError('untrusted-WordPress-catalog-route-binding')
    if (original['market_country']!=decision['market_country']
        or original['currency']!=decision['currency']
        or state.get('market_country')!=decision['market_country']
        or state.get('currency')!=decision['currency']
        or route['language'] not in state.get('wpml_languages',[])):
        raise ValueError('trusted-country-currency-or-WPML-language-drift')
    runtime=Path(runtime);consumer=Path(consumer)
    if (runtime.is_symlink() or consumer.is_symlink()
        or not runtime.is_file() or not consumer.is_file()):
        raise ValueError('original-runtime-or-v2-consumer-missing')
    runtime_sha=sha(runtime.read_bytes());consumer_sha=sha(consumer.read_bytes())
    if state.get('runtime_sha')!=runtime_sha or state.get('consumer_v2_sha')!=consumer_sha:
        raise ValueError('native-422-and-Companion-V2-release-SHAs-not-admitted')
    if guard_int(state.get('css_epoch'),'css-epoch')<1 or state.get('native_breakpoint')!=767:
        raise ValueError('untrusted-breakpoint-or-CSS-epoch')
    global_gen=guard_int(state.get('global_generation'),'global-generation')
    listing_gen=guard_int(state.get('listing_generation'),'listing-generation')
    route_gen=guard_int(route.get('revision'),'source-route-generation')
    if (state.get('route_generation',route_gen)!=route_gen):
        raise ValueError('WordPress-post-route-generation-changed')
    # Independent source/DOM checks from the original v1 publisher,
    # without inheriting its single LiteSpeed stylesheet assumption.
    legacy.verify_queried_object(route,full_html)
    body=full_html[full_html.lower().find('<body'):]
    docs=sorted({int(i) for i in re.findall(
        r'(?<![a-z0-9_-])elementor-(\d+)\b',body,re.I)})
    graph=sha(','.join(map(str,docs)).encode())
    if 'id="mad4b-public-critical-css-v1"' in full_html or 'data-mad4b-ccss-v2-rest' in full_html:
        raise ValueError('already-optimized-original-HTML')
    market_sensitive=(route['type'] not in {'page','post'} or any(
        marker in body for marker in ['jet-listing-grid','woocommerce-Price-amount',
              'mad4b-pricing','mad4b-rate','tour-rate','data-price=']))
    if market_sensitive and not (
       decision['market_country']=='base' or re.fullmatch('[A-Z]{2}',decision['market_country'])):
        raise ValueError('dynamic-market-unverified')
    if not market_sensitive and decision['market_country']!='static':
        raise ValueError('static-page-market-mismatch')
    # Recompile the source in the exact same site root and verify the
    # browser-captured source chunk SHA. No stale CSS can produce v2.
    rebuilt=ordered.compile_manifest(source_manifest,wp_content)
    originals=data(Path(job)/'compiled-chunks.json')
    original_chunks=originals.get('chunks',[])
    if (len(rebuilt['chunks'])!=len(candidate['critical_slots'])
        or len(rebuilt['chunks'])!=len(original_chunks)
        or rebuilt['manifest_sha256']!=decision['source_manifest_sha256']):
        raise ValueError('CSS-source-compiler-source-drift')
    output=[]
    for calculated,observed,critical in zip(rebuilt['chunks'],original_chunks,candidate['critical_slots']):
        if (calculated['sha256']!=observed.get('sha256')
            or calculated['slot']!=critical.get('slot')
            or calculated['sha256']!=critical.get('source_sha256')
            or calculated['bytes']!=critical.get('source_bytes')):
            raise ValueError('critical-chunk-provenance-drift')
        output.append({'slot':critical['slot'],
           'source_sha256':calculated['sha256'],'source_bytes':calculated['bytes'],
           'critical_sha256':critical['critical_sha256'],
           'critical_file':critical['critical_file'],
           'critical_bytes':critical['critical_bytes']})
    values=native['viewport_results']
    if len(values)!=5 or [v['width'] for v in values]!=VIEWS:
        raise ValueError('native-URLs-viewports-not-complete')
    return {'contract':CONTRACT,'status':'certified','host':decision['host'],
       'path':route['path'],'language':route['language'],'type':route['type'],
       'object_id':route['id'],'market_country':decision['market_country'],
       'currency':decision['currency'],'css_epoch':state['css_epoch'],
       'runtime_sha':runtime_sha,'consumer_sha':consumer_sha,
       'critical_cap_px':767,'native_mobile_breakpoint':767,
       'source_manifest':source_manifest,
       'source_manifest_sha256':source_manifest['manifest_sha256'],
       'critical_slots':output,'critical_total_bytes':candidate['critical_total_bytes'],
       'elementor_doc_fingerprint':graph,
       'dependencies':{'global_generation':global_gen,
          'route_generation':route_gen,'listing_generation':listing_gen,
          'contains_dynamic_listing':market_sensitive},
       'evidence':{'decision':'passed_evidence','tested':5,'viewports':VIEWS,
          'performance_source':'real-original-css-urls',
          'qa_sha256':sha((Path(job)/'ordered-native-critical-qa.json').read_bytes()),
          'minimum_mobile_fcp_gain_ms':min(v['fcp_gain_ms'] for v in values[:4]),
          'worst_lcp_reg_ms':max(v['lcp_reg_ms'] for v in values),
          'verified_at':int(os.environ.get('SOURCE_ATTESTED_UNIX_TIME','0'))},
       'certified_at':int(os.environ.get('SOURCE_ATTESTED_UNIX_TIME','0'))}

def publish(cert,job,wp_content):
    wp=Path(wp_content)
    if str(wp) not in LIVE_ROOTS or wp.is_symlink():
        raise ValueError('non-enrolled-live-WordPress-site')
    # Deploying only a Git clone cannot authorize publication into WP.
    runtime=wp/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
    companion=wp/'mu-plugins/422-mad4b-public-critical-css-v2-consumer.php'
    if (not runtime.is_file() or runtime.is_symlink()
        or not companion.is_file() or companion.is_symlink()
        or sha(runtime.read_bytes())!=cert['runtime_sha']
        or sha(companion.read_bytes())!=cert['consumer_sha']
        or os.environ.get('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED')!='1'):
        raise ValueError('live-native-release-and-approval-not-proven')
    base=wp/'mad4b-public-critical-css-v1'
    paths=[base,base/'assets',base/'certs-v2']
    for directory in paths:
        if directory.is_symlink():raise ValueError('public-v2-directory-symlink')
        directory.mkdir(mode=0o755,exist_ok=True)
        if directory.is_symlink() or not directory.is_dir():raise ValueError('invalid-v2-directory')
        permissions=stat.S_IMODE(directory.stat().st_mode)
        if permissions&0o022:raise ValueError('unsafe-world-writable-directory')
        directory.chmod(0o755)
    # Copy all immutable source-proven critical chunks. Certificate goes last.
    for slot in cert['critical_slots']:
        if not slot['critical_bytes']:continue
        name=slot['critical_file']
        src=Path(job)/name
        dest=base/'assets'/name
        if src.is_symlink() or not src.is_file() or src.stat().st_size!=slot['critical_bytes']:
            raise ValueError('critical-source-bounds')
        if sha(src.read_bytes())!=slot['critical_sha256']:
            raise ValueError('critical-byte-SHA-changed-during-publish')
        if dest.is_symlink():raise ValueError('unsafe-existing-critical-asset')
        if dest.exists():
            if sha(dest.read_bytes())!=slot['critical_sha256']:
                raise ValueError('content-addressed-asset-collision')
        else:_atomic(dest,src.read_bytes())
    routekey=sha((cert['host']+'|'+cert['language']+'|'+cert['path']+'|'+
       cert['market_country']+'|'+cert['currency']).encode())
    final=base/'certs-v2'/(routekey+'.json')
    if final.is_symlink():raise ValueError('certificate-path-symlink')
    payload=(json.dumps(cert,indent=2,sort_keys=True,ensure_ascii=True)+'\n').encode()
    _atomic(final,payload)
    if data(final)!=cert:raise ValueError('certificate-publish-readback-mismatch')
    return str(final)
def _atomic(dest,data_bytes):
    fd,temp=tempfile.mkstemp(prefix='.mad4b-multi-v2-',dir=dest.parent)
    try:
        with os.fdopen(fd,'wb') as f:
            os.fchmod(f.fileno(),0o644)
            f.write(data_bytes);f.flush();os.fsync(f.fileno())
        os.replace(temp,dest)
    finally:
        if os.path.exists(temp):os.unlink(temp)

def main():
    if len(sys.argv)!=9:
        raise ValueError('USAGE: catalog route state private-job wp-content 422.php companion.php dryrun|publish')
    cat,route,state,job,wp,runtime,consumer,mode=sys.argv[1:]
    if mode not in ('dryrun','publish'):raise ValueError('invalid-issuance-mode')
    cert=certificate(data(cat),data(route),data(state),Path(job),Path(wp),runtime,consumer)
    target=publish(cert,job,wp) if mode=='publish' else '(dryrun-only)'
    print('MULTI_CSS_V2_CERTIFICATE',json.dumps({'mode':mode,'route':cert['path'],
       'host':cert['host'],'lang':cert['language'],'currency':cert['currency'],
       'market_country':cert['market_country'],'manifest_sha256':cert['source_manifest_sha256'],
       'critical_slots':len(cert['critical_slots']),
       'critical_bytes':cert['critical_total_bytes'],
       'certificate_path':target},sort_keys=True))
if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError,IndexError,AssertionError,
            AttributeError,UnicodeError,json.JSONDecodeError) as e:
        print('HOLD_MULTI_CSS_V2_CERTIFICATE',str(e)[:200],file=sys.stderr)
        sys.exit(4)
