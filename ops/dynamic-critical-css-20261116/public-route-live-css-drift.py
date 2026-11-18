#!/usr/bin/env python3
"""Bounded, authenticated-source CSS drift check for published route certificates.

This does not create CSS or invalidate an unrelated certificate. One company
checks up to four *currently source-certified* anonymous routes each run.
Only original host, WPML route, server-resolved market and a locally hashed
LiteSpeed CSS artifact establish a drift. No cookies or user data are sent.
"""
from __future__ import annotations
import copy
import html
import json
import pathlib
import re
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

CONTRACT='mad4b.public-critical-css.live-asset-drift.v1'
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

def scan(site,cat,state,ledger,wp,families,cursor_file,*,count=4,force_path=None,fetch=original_request):
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
    result=copy.deepcopy(ledger)
    result['routes'][route_key]['source_key']='live-css-asset-drift:'+drift['css_sha']
    return result
