#!/usr/bin/env python3
"""Bounded site-owned public CSS discovery; not source certification.

Acquires anonymous public GET for one original catalog route (not an arbitrary
client URL), makes immutable first-party CSS dependency evidence, and keeps
all compiled CSS private until separate Chromium performance and delivery QA.
"""
import importlib.util
import hashlib
import time
import json
import os
import pathlib
import re
import sys
import tempfile
import urllib.request
import urllib.error
from urllib.parse import urlsplit
from pathlib import Path

HERE=Path(__file__).resolve().parent
sp=importlib.util.spec_from_file_location('mad4b_ordered_css_source',HERE/'public-css-dependency-manifest.py')
ordered=importlib.util.module_from_spec(sp);sp.loader.exec_module(ordered)
CONTRACT='mad4b.public-css.manifest-observer.v1'
FAMILY_PREPARED_MAX_AGE_SECONDS=24*3600
FAMILY_PREPARED_ROOT=Path('/opt/mad4b-company-provisioning/reports/public-critical-css-v1')
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'

class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*_args,**_kwargs):return None

def public_fetch(host,path):
    if (host not in ('wayfatours.com','tours.dev.mad4b.com')
        or not re.fullmatch(r'/[a-zA-Z0-9/_%.-]{0,345}',path)
        or '//' in path or '..' in path):
        raise ValueError('untrusted-catalog-public-route')
    # The source site has an independent Preset Cache and the target has
    # FastCGI. BOTH can return a stale Elementor/stylesheet graph.
    # Always request exact first-party, uncached WordPress origin HTML;
    # never issue a certificate from a fast cache response.
    request_headers={'User-Agent':UA,'Accept':ACCEPT,'Accept-Encoding':'identity',
                     'X-MAD4B-Bypass-Cache':'1'}
    req=urllib.request.Request('https://'+host+path,method='GET',
       headers=request_headers)
    with urllib.request.build_opener(NoRedirect()).open(req,timeout=9) as response:
        if response.status!=200 or 'text/html' not in response.headers.get('Content-Type',''):
            raise ValueError('public-html-not-200')
        body=response.read(ordered.MAX_HTML+1)
        if len(body)>ordered.MAX_HTML:raise ValueError('source-html-too-large')
        # Canonicalise only the three public admission headers; never
        # retain cookies, proxy auth or unrelated response metadata.
        safe={name:response.headers.get(name,'') for name in (
          'X-MAD4B-Public-CCSS-Ready',
          'X-MAD4B-Public-CCSS-Currency',
          'X-MAD4B-Public-CCSS-Market',
          'X-Wayfa-Origin-Cache','X-MAD4B-Preset-Cache','Cache-Control')}
        if not original_proven_fresh(host,safe):
            raise ValueError('site-original-wordpress-html-not-origin-bypass')
        return body.decode('utf-8'),safe

def original_proven_fresh(host,headers):
    if not isinstance(headers,dict) or 'no-store' not in (
        headers.get('Cache-Control','').lower()):
        return False
    if host=='wayfatours.com':
        return headers.get('X-Wayfa-Origin-Cache')=='BYPASS'
    if host=='tours.dev.mad4b.com':
        return headers.get('X-MAD4B-Preset-Cache')=='BYPASS'
    return False


def _fresh_family_compiled(catalog,route,manifest,market,currency,css_epoch,runtime_sha,consumer_sha):
    # Private family source preparation can ONLY skip repeated compilation.
    # It never reuses CSS coverage or a certificate from a sibling route.
    host=catalog.get('host')
    site={'tours.dev.mad4b.com':'source','wayfatours.com':'wayfa'}.get(host)
    if (site is None or not re.fullmatch('[a-f0-9]{64}',str(route.get('route_key','')))
        or not isinstance(css_epoch,int) or isinstance(css_epoch,bool)):
        return None
    directory=FAMILY_PREPARED_ROOT/site/'family-prepared-v1'/route['route_key']
    try:
        if (directory.is_symlink() or not directory.is_dir()
            or directory.stat().st_uid!=0 or directory.stat().st_mode&0o077):
            return None
        def protected(name,max_bytes):
            f=directory/name
            if (f.is_symlink() or not f.is_file() or f.stat().st_uid!=0
                or f.stat().st_mode&0o022 or not 0<f.stat().st_size<=max_bytes):
                raise ValueError('untrusted-family-cache-object')
            return f
        metadata=json.loads(protected('prepared.json',8192).read_text())
        if (metadata.get('contract')!='mad4b.css.v2.family-prepared-source.v1'
            or metadata.get('site')!=site or metadata.get('host')!=host
            or metadata.get('route_key')!=route['route_key']
            or metadata.get('path')!=route['path']
            or metadata.get('language')!=route['language']
            or metadata.get('source_profile')!=route.get('source_profile')
            or metadata.get('source_graph')!=route.get('source_graph')
            or metadata.get('route_revision')!=route.get('revision')
            or metadata.get('css_epoch')!=css_epoch
            or metadata.get('runtime_sha')!=runtime_sha
            or metadata.get('consumer_v2_sha')!=consumer_sha
            or metadata.get('market_country')!=market
            or metadata.get('currency')!=currency
            or metadata.get('compiler_sha')!=hashlib.sha256(
                (HERE/'public-css-dependency-manifest.py').read_bytes()).hexdigest()
            or metadata.get('manifest_sha256')!=manifest['manifest_sha256']
            or type(metadata.get('prepared_at'))is not int
            or not 0<=int(time.time())-metadata['prepared_at']<=FAMILY_PREPARED_MAX_AGE_SECONDS):
            return None
        historical=json.loads(protected('css-dependencies.json',3_000_000).read_text())
        if historical!=manifest:return None
        values=json.loads(protected('compiled-chunks.json',200000).read_text())
        rows=values.get('chunks')
        if (values.get('contract')!=ordered.CONTRACT
            or values.get('manifest_sha256')!=manifest['manifest_sha256']
            or not isinstance(rows,list) or not 1<=len(rows)<=256):
            return None
        # Cascade slots come from fresh, source-verified HTML. A cached
        # compiled chunk cannot move a style across an inline CSS boundary.
        expected_slots=list(dict.fromkeys(
            entry['slot'] for entry in manifest['files']))
        if [row.get('slot') for row in rows]!=expected_slots:
            return None
        chunks=[]
        for row in rows:
            name=row.get('sha256','')
            if not isinstance(name,str) or not re.fullmatch('[a-f0-9]{64}',name):
                return None
            raw=protected(name+'.css',ordered.MAX_TOTAL).read_bytes()
            if len(raw)!=row.get('bytes') or ordered.sha(raw)!=name:return None
            chunks.append({**row,'css':raw})
        compiled=b'\n'.join(v['css'] for v in chunks)
        if (len(compiled)!=metadata.get('compiled_bytes')
            or ordered.sha(compiled)!=metadata.get('compiled_sha256')):
            return None
        return {'manifest_sha256':manifest['manifest_sha256'],
                'compiled_sha256':metadata['compiled_sha256'],
                'compiled_bytes':len(compiled),
                'css':compiled,'chunks':chunks,
                'slot_preserving_delivery_required':len(chunks)>1}
    except (OSError,ValueError,KeyError,TypeError,UnicodeError):
        # A cache defect must never block native CSS source compilation.
        return None

def observe(catalog,route,root,*,expected_host,fetch=public_fetch,include_html=False):
    host=catalog.get('host')
    if (catalog.get('contract')!='mad4b.public-critical-css.routes.v1'
        or not isinstance(host,str) or host!=expected_host
        or route not in catalog.get('routes',[])):
        raise ValueError('route-not-enrolled-in-wordpress-source-catalog')
    wp=Path(root)
    if wp.is_symlink() or not wp.is_dir() or wp.name!='wp-content':
        raise ValueError('non-company-wp-content-root')
    path=route.get('path')
    if not isinstance(path,str) or path in ('/','/'+route.get('language','')+'/'):
        raise ValueError('independent-home-controller-not-a-public-route')
    html,headers=fetch(host,path)
    if not original_proven_fresh(host,headers):
        raise ValueError('source-original-wordpress-html-not-proven-uncached')
    if headers.get('X-MAD4B-Public-CCSS-Ready')=='1':
        raise ValueError('original-html-already-ccss-transformed')
    # Preserve real server-resolved WCML country and currency. Missing source
    # headers are never inferred from the URL, cookies or catalog defaults.
    currency=headers.get('X-MAD4B-Public-CCSS-Currency','')
    market=headers.get('X-MAD4B-Public-CCSS-Market','')
    if (not re.fullmatch(r'[A-Z]{3}',currency)
        or not re.fullmatch(r'(?:[A-Z]{2}|base|static)',market)):
        raise ValueError('missing-original-wcml-market-identity')
    manifest=ordered.inspect(html,host,wp)
    epoch=os.environ.get('MAD4B_FAMILY_PREPARED_EPOCH','')
    runtime=os.environ.get('MAD4B_FAMILY_PREPARED_RUNTIME_SHA','')
    consumer=os.environ.get('MAD4B_FAMILY_PREPARED_CONSUMER_SHA','')
    prepared=(_fresh_family_compiled(catalog,route,manifest,market,currency,
                int(epoch),runtime,consumer) if
                os.environ.get('MAD4B_FAMILY_PREPARED_SOURCE')=='1'
                and re.fullmatch('[0-9]{1,9}',epoch) else None)
    compiled=(prepared if prepared is not None
              else ordered.compile_manifest(manifest,wp))
    report={'contract':CONTRACT,'host':host,'path':path,
        'route_key':route.get('route_key'),
        'language':route.get('language'),
        'market_country':market,'currency':currency,
        'css_files':len(manifest['files']),'cascade_slots':len(compiled['chunks']),
        'manifest_sha256':manifest['manifest_sha256'],
        'original_html_sha256':ordered.sha(html.encode()),
        'compiled_sha256':compiled['compiled_sha256'],
        'compiled_bytes':compiled['compiled_bytes'],
        'family_source_prepared_reused':prepared is not None,
        'css_source_proven':True,
        'browser_fcp_lcp_cls_qa_complete':False,
        'public_css_route_certificate_issued':False,
        'fastcgi_ready_for_promotion':False,
        'decision':'awaiting_multi_css_route_qa'}
    return (report,manifest,compiled,html) if include_html else (report,manifest,compiled)

def store(root,report,manifest,compiled,html=None):
    output=Path(root)
    if output.is_symlink() or not output.is_dir():
        raise ValueError('untrusted-private-output-root')
    # This is a PRIVATE report, not the WordPress CSS/public cache tree.
    for path,blob in [
      ('multi-css-source-report.json',ordered.canonical(report)+b'\n'),
      ('css-dependencies.json',ordered.canonical(manifest)+b'\n'),
      ('compiled-chunks.json',ordered.canonical({'contract':ordered.CONTRACT,
          'manifest_sha256':manifest['manifest_sha256'],
          'chunks':[{k:v for k,v in c.items() if k!='css'} for c in compiled['chunks']]})+b'\n')]:
        dest=output/path
        if dest.is_symlink():raise ValueError('untrusted-output-file')
        with tempfile.NamedTemporaryFile(mode='wb',dir=output,delete=False) as f:
            f.write(blob);name=f.name
        Path(name).replace(dest)
        dest.chmod(0o600)
    if html is not None:
        if ordered.sha(html.encode())!=report.get('original_html_sha256'):
            raise ValueError('html-snapshot-source-identity-drift')
        target=output/'source.html'
        if target.is_symlink():raise ValueError('untrusted-private-html-path')
        with tempfile.NamedTemporaryFile(mode='wb',dir=output,delete=False) as f:
            f.write(html.encode());name=f.name
        Path(name).replace(target)
        target.chmod(0o600)
    for chunk in compiled['chunks']:
        target=output/(chunk['sha256']+'.css')
        if target.is_symlink():raise ValueError('untrusted-output-css-path')
        with tempfile.NamedTemporaryFile(mode='wb',dir=output,delete=False) as f:
            f.write(chunk['css']);name=f.name
        Path(name).replace(target)
        target.chmod(0o600)

def main():
    if len(sys.argv)!=5:
        raise ValueError('USAGE: original-catalog.json original-route.json wp-content private-job-directory')
    cat,route,wp,out=sys.argv[1:]
    catalog=json.loads(Path(cat).read_text())
    job=json.loads(Path(route).read_text())
    report,manifest,compiled,html=observe(catalog,job,wp,
        expected_host=os.environ.get('MAD4B_EXPECTED_SITE_HOST'),include_html=True)
    store(out,report,manifest,compiled,html)
    print('MULTICSS_SOURCE_ONLY',json.dumps(report,sort_keys=True))

if __name__=='__main__':
    try:main()
    except (OSError,ValueError,TypeError,KeyError,UnicodeError,urllib.error.URLError) as e:
        print('HOLD_MULTICSS_SOURCE_OBSERVATION',str(e)[:180],file=sys.stderr)
        sys.exit(4)
