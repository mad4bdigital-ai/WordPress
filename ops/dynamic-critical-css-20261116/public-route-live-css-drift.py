#!/usr/bin/env python3
"""Bounded, authenticated-source CSS drift check for published route certificates.

This does not create CSS or invalidate an unrelated certificate. One company
checks up to four *currently source-certified* anonymous routes each run.
Only original host, WPML route, server-resolved market and a locally hashed
LiteSpeed CSS artifact establish a drift. No cookies or user data are sent.
"""
from __future__ import annotations
import copy
import hashlib
import html
import json
import importlib.util
import pathlib
import re
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

CONTRACT='mad4b.public-critical-css.live-asset-drift.v1'
V2_CONTRACT='mad4b.public-critical-css.route-certificate.v2'
SHA_RE=re.compile(r'^[a-f0-9]{64}$')
DOC_RE=re.compile(r'(?<![a-z0-9_-])elementor-(\d+)\b',re.I)

HOSTS={'source':'tours.dev.mad4b.com','wayfa':'wayfatours.com'}
UA='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/157.0.0.0 Mobile Safari/537.36'
ACCEPT='text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,image/apng,*/*;q=0.8'
CSS_RE=re.compile(r'^/wp-content/litespeed/css/([a-f0-9]{32}\.css)$')
LINK_RE=re.compile(r'<link\b[^>]*>',re.I)
REL_RE=re.compile(r'\brel=["\x27]stylesheet["\x27]',re.I)
HREF_RE=re.compile(r'\bhref=["\x27]([^"\x27]+)["\x27]',re.I)
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*_args,**_kwargs):return None

def bundle_filenames(body,host):
    """Ignore non-CSS links, off-origin assets and duplicate noscript tags."""
    if not isinstance(body,str) or '</head>' not in body.lower():return []
    head=body[:body.lower().index('</head>')]
    found=set()
    for tag in LINK_RE.findall(head):
        if not REL_RE.search(tag):continue
        m=HREF_RE.search(tag)
        if not m:continue
        url=urllib.parse.urlsplit(html.unescape(m.group(1)))
        if url.scheme not in ('','https') or url.netloc.lower() not in ('',host):continue
        matched=CSS_RE.fullmatch(url.path)
        if matched:found.add(matched.group(1))
    return sorted(found)

def original_request(host,path):
    if host not in HOSTS.values() or not re.fullmatch(r'/[A-Za-z0-9_/%.-]{0,350}',path):
        raise ValueError('untrusted-public-route')
    req=urllib.request.Request('https://'+host+path,headers={
      'User-Agent':UA,'Accept':ACCEPT},method='GET')
    with urllib.request.build_opener(NoRedirect()).open(req,timeout=7) as response:
        if response.status!=200 or 'text/html' not in response.headers.get('Content-Type','').lower():
            return None
        content=response.read(6000001)
        if len(content)>6000000:return None
        return (content.decode('utf-8','replace'),
            response.headers.get('X-MAD4B-Public-CCSS-Currency',''),
            response.headers.get('X-MAD4B-Public-CCSS-Market',''))

def compare(cert,html_body,currency,market,wp,host):
    """Return a real content-drift proof, never an untrusted filename alone."""
    if not isinstance(cert,dict) or cert.get('currency')!=currency or cert.get('market_country')!=market:
        return None
    names=bundle_filenames(html_body,host)
    if len(names)!=1:return None
    name=names[0]
    source=wp/'litespeed/css'/name
    if source.is_symlink() or not source.is_file():
        source=wp/'litespeed/.mad4b-asset-retention/css'/name
    if source.is_symlink() or not source.is_file() or not 16000<=source.stat().st_size<=6291456:
        return None
    import hashlib
    current_sha=hashlib.sha256(source.read_bytes()).hexdigest()
    if name==cert['css_file'] and current_sha==cert['css_sha']:return None
    return {'css_file':name,'css_sha':current_sha,
            'expected_css_file':cert['css_file'],'expected_css_sha':cert['css_sha']}

def compare_v2_document_graph(cert,html_body,currency,market):
    """Only a stable, exact-market ORIGINAL HTML document can prove V2 drift.

    The original 422 consumer already fails closed on an Elementor document
    fingerprint mismatch. This proof ONLY prioritizes renewed independent QA;
    it never treats the observed HTML as an approved CSS certificate.
    """
    if (not isinstance(cert,dict) or cert.get('contract')!=V2_CONTRACT
        or cert.get('status')!='certified'
        or cert.get('currency')!=currency
        or cert.get('market_country')!=market
        or not isinstance(html_body,str)
        or not 16000<=len(html_body.encode('utf8'))<=6000000):
        return None
    if ('data-mad4b-ccss-v2-critical=' in html_body
        or 'data-mad4b-ccss-v2-rest=' in html_body):return None
    at=html_body.lower().find('<body')
    if at<0:return None
    body_start=html_body[at:html_body.find('>',at)+1]
    obj=cert.get('object_id')
    if (type(obj) is not int or obj<=0
        or cert.get('type') not in ('tours-and-activities','properties','post','page')
        or re.search(r'\b(?:postid|page-id)-'+str(obj)+r'\b',body_start) is None):
        return None
    docs=sorted({int(x) for x in DOC_RE.findall(html_body[at:])})
    if not docs:return None
    expected=cert.get('elementor_doc_fingerprint')
    if not isinstance(expected,str) or not SHA_RE.fullmatch(expected):return None
    observed=hashlib.sha256(','.join(str(x) for x in docs).encode()).hexdigest()
    if observed==expected:return None
    return {'drift_kind':'elementor_doc_fingerprint',
            'expected_doc_sha':expected,'observed_doc_sha':observed}

def trusted_v2_original_request(host,path):
    """Reuse the enrolled Wayfa original WordPress FastCGI BYPASS reader."""
    if host!='wayfatours.com':raise ValueError('v2-dom-watch-unsupported-company')
    location=Path(__file__).resolve().with_name('public-css-manifest-observer.py')
    if location.is_symlink() or not location.is_file():
        raise ValueError('original-css-manifest-observer-not-enrolled')
    spec=importlib.util.spec_from_file_location('mad4b_existing_original_css_reader',location)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    body,headers=module.public_fetch(host,path)
    if (headers.get('X-Wayfa-Origin-Cache')!='BYPASS'
        or 'no-store' not in headers.get('Cache-Control','').lower()
        or headers.get('X-MAD4B-Public-CCSS-Ready')=='1'):
        return None
    return (body,headers.get('X-MAD4B-Public-CCSS-Currency',''),
            headers.get('X-MAD4B-Public-CCSS-Market',''))

def eligible(site,cat,state,ledger,wp,families):
    host=HOSTS.get(site)
    if not host or cat.get('host')!=host or state.get('site_host')!=host:
        raise ValueError('wrong-source-company')
    if str(wp)!=families.ROOTS[site][0]:raise ValueError('untrusted-site-root')
    published=families.load_certs(wp)
    rows=[]
    for route in families.queue.safe_catalog(cat,site):
        rec=ledger['routes'].get(route['route_key'],{})
        for (lang,path,market,currency),cert in published.items():
            if lang!=route['language'] or path!=route['path']:continue
            # Only trust the exact original certificate, live dependencies and
            # original browser evidence. Recheck base-market anonymous HTML;
            # nonbase WCML profiles have no safe anonymous probe.
            if market not in ('static','base'):continue
            if not families.valid_canary(wp,host,route,state,cat,rec,cert):continue
            rows.append((route,cert))
    return sorted(rows,key=lambda pair:(pair[0]['path'],pair[0]['language']))

def scan(site,cat,state,ledger,wp,families,cursor_file,*,count=4,force_path=None,fetch=original_request,v2_fetch=None):
    rows=eligible(site,cat,state,ledger,wp,families)
    if not rows:return None
    cursor=0
    if cursor_file.is_file() and not cursor_file.is_symlink() and cursor_file.stat().st_size<2000:
        try:
            old=json.loads(cursor_file.read_text())
            if old.get('contract')==CONTRACT and old.get('site')==site:
                cursor=max(0,int(old.get('cursor',0)))
        except (ValueError,TypeError,json.JSONDecodeError):pass
    if force_path is not None:
        batch=[item for item in rows if item[0]['path']==force_path]
        if len(batch)!=1:raise ValueError('manual-audit-route-not-source-certified')
    else:
        batch=[rows[(cursor+i)%len(rows)] for i in range(min(count,len(rows)))]
    result=None
    for route,cert in batch:
        try:
            if site=='wayfa' and cert.get('contract')==V2_CONTRACT:
                native=v2_fetch or trusted_v2_original_request
                response=native(HOSTS[site],route['path'])
                if response is None:continue
                drift=compare_v2_document_graph(cert,*response)
                if drift:
                    # A second independent, uncached source read must confirm
                    # the same market and Elementor graph before a recheck.
                    repeat=native(HOSTS[site],route['path'])
                    if repeat is None or compare_v2_document_graph(cert,*repeat)!=drift:
                        drift=None
            else:
                response=fetch(HOSTS[site],route['path'])
                if response is None:continue
                drift=compare(cert,*response,wp,HOSTS[site])
            if drift:
                result={'contract':CONTRACT,'site':site,
                        'route_key':route['route_key'],'path':route['path'],
                        'language':route['language'],'type':route['type'],
                        'object_id':route['id'],**drift}
                break
        except (OSError,TimeoutError,urllib.error.URLError,ValueError,KeyError):
            continue
    if force_path is None:
        families.queue.atom(cursor_file,{'contract':CONTRACT,'site':site,
                                      'cursor':(cursor+len(batch))%len(rows),
                                      'last_count':len(batch),
                                      'found_drift':result is not None})
    return result

def select_verified_repair(ledger,drift):
    """Select an exact certified route again WITHOUT mutating its ledger."""
    route_key=drift['route_key']
    recorded=ledger.get('routes',{}).get(route_key)
    if not isinstance(recorded,dict) or recorded.get('status')!='certified':
        raise ValueError('not-an-originally-certified-route')
    proof_type=drift.get('drift_kind')
    if proof_type=='elementor_doc_fingerprint':
        current=drift.get('observed_doc_sha')
        previous=drift.get('expected_doc_sha')
        if (not isinstance(current,str) or not SHA_RE.fullmatch(current)
            or not isinstance(previous,str) or not SHA_RE.fullmatch(previous)
            or current==previous):
            raise ValueError('invalid-independent-document-graph-proof')
        token='live-document-graph-drift:'+current
    elif proof_type in (None,'css_asset'):
        current=drift.get('css_sha')
        if not isinstance(current,str) or not SHA_RE.fullmatch(current):
            raise ValueError('invalid-independent-asset-drift-proof')
        token='live-css-asset-drift:'+current
    else:raise ValueError('unknown-independent-drift-type')
    result=copy.deepcopy(ledger)
    result['routes'][route_key]['source_key']=token
    return result
