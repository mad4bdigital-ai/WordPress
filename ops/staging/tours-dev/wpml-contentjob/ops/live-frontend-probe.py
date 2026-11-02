#!/usr/bin/env python3
"""Public-HTML verified WPML priority evidence. Bounded, self-discovering, rotation + TTL."""
import concurrent.futures
import datetime
import html as html_lib
import json
import os
import re
import sys
import time
import urllib.parse
import urllib.request
from pathlib import Path

BASE='https://tours.dev.mad4b.com'
HOST='tours.dev.mad4b.com'
OUT=Path(os.environ.get('MAD4B_PRIORITY_OUTPUT','/opt/staging/mad4b-wpml-content-jobs-bridge-20261102/priority/frontend-visibility.json'))
CURSOR=OUT.with_name('frontend-visibility-cursor.json')
AGE_LIMIT=36*3600
PER_LOCALE=28
UA='MAD4B-PublicFrontend-Priority/2.0'

class SafeRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        u=urllib.parse.urlparse(newurl)
        if u.scheme!='https' or u.hostname!=HOST or u.port not in (None,443):
            raise ValueError('off-host redirect denied')
        return super().redirect_request(req, fp, code, msg, headers, newurl)

opener=urllib.request.build_opener(SafeRedirect)
def fetch(path):
    u=urllib.parse.urlparse(path)
    if u.scheme!='https' or u.hostname!=HOST or u.port not in (None,443):return None
    for attempt in range(2):
        try:
            req=urllib.request.Request(path,headers={'User-Agent':UA,'Accept':'text/html'})
            with opener.open(req, timeout=13) as resp:
                parsed=urllib.parse.urlparse(resp.url)
                if resp.status==200 and parsed.hostname==HOST:
                    return resp.read(1700000).decode('utf-8','replace')
        except Exception:
            pass
        if attempt==0: time.sleep(0.3)
    return None


def visible(document):
    return {
        'elementor_ids':sorted(set(re.findall(r'data-elementor-id=["\'](\d+)["\']',document))),
        'listing_ids':sorted(set(re.findall(r'data-listing-id=["\'](\d+)["\']',document))),
        'post_ids':sorted(set(re.findall(r'data-post-id=["\'](\d+)["\']',document))),
    }

def links_in(document,lang,home):
    links=[]
    for raw in re.findall(r'<a\b[^>]*\bhref=["\']([^"\']+)["\']',document,re.I):
        href=html_lib.unescape(raw.strip())
        u=urllib.parse.urlparse(urllib.parse.urljoin(home,href))
        if u.scheme!='https' or u.hostname!=HOST or u.port not in (None,443):continue
        path=u.path
        if (not path.startswith('/'+lang+'/') or path in ('/'+lang+'/',)
           or len(path)>220 or '/wp-' in path or path.endswith(('.jpg','.jpeg','.webp','.png','.pdf','.css','.js'))):continue
        if path not in links:links.append(path)
    return links

def discover_locales(home_html):
    found=[]
    for lang in re.findall(r'<link\b[^>]*\bhreflang=["\']([a-z]{2}(?:-[a-z]{2})?)["\']',home_html,re.I):
        lang=lang.lower().split('-')[0]
        if lang!='en' and lang not in found:found.append(lang)
    return found[:14]

def read_json(p):
    try:return json.loads(p.read_text())
    except (OSError,ValueError,TypeError):return {}

def observe():
    start=time.time()
    document=fetch(BASE+'/')
    if not document:raise RuntimeError('default_home_unavailable')
    locales=discover_locales(document)
    if len(locales)<2:raise RuntimeError('insufficient_discovered_wpml_locales')
    prior=read_json(OUT)
    cursor=read_json(CURSOR)
    old_pages=prior.get('pages',{}) if prior.get('site')==BASE else {}
    old_at=prior.get('observed_at')
    now=time.time()
    stamp=datetime.datetime.now(datetime.timezone.utc).isoformat()
    result={'contract':'mad4b.wpml.frontend-visibility.v1','site':BASE,
      'observed_at':stamp,'pages':{},'errors':[],'discovered_languages':locales,'refresh_mode':'rolling_visible_html'}
    next_cursor={}
    def scan_one(lang):
        home='/'+lang+'/'
        main=fetch(BASE+home)
        if main is None:
            # Preserve fresh historical evidence without declaring it freshly
            # observed. A partial outage must not erase verified visibility.
            old=[]
            for row in old_pages.get(lang,[]):
                try:
                    d=datetime.datetime.fromisoformat(row.get('observed_at',old_at).replace('Z','+00:00'))
                    age=now-d.timestamp()
                except (ValueError,TypeError,AttributeError):
                    continue
                if 0<=age<=AGE_LIMIT and row.get('http_status')==200:
                    old.append(row)
            return lang,old[:PER_LOCALE],int(cursor.get(lang,0)),False
        this={home:{'path':home,'kind':'home','http_status':200,**visible(main),'observed_at':stamp}}
        candidates=links_in(main,lang,BASE+home)
        # Archive candidate is discovered from actual homepage links.
        archives=sorted([x for x in candidates if re.search(r'/tours-and-activities/?$|/tours/?$|/packages/?$',x,re.I)],key=len)
        main_archive=archives[0] if archives else None
        if main_archive:
            rendered=fetch(BASE+main_archive)
            if rendered:
                this[main_archive]={'path':main_archive,'kind':'archive','http_status':200,
                    **visible(rendered),'observed_at':stamp}
                for path in links_in(rendered,lang,BASE+main_archive):
                    if path not in candidates:candidates.append(path)
        # Stable rotation over live discovered links, no fixed tour IDs.
        candidates=sorted(set(x for x in candidates if x!=main_archive))
        last=int(cursor.get(lang,0))
        count=min(3,len(candidates))
        chosen=[candidates[(last+i)%len(candidates)] for i in range(count)] if count else []
        next_cursor[lang]=(last+count)%len(candidates) if candidates else 0
        with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
            futures=[(path,pool.submit(fetch,BASE+path)) for path in chosen]
            for path,future in futures:
                doc=future.result()
                if doc:
                    this[path]={'path':path,'kind':'linked_page','http_status':200,
                        **visible(doc),'observed_at':stamp}
        # Keep previously verified pages as bounded evidence, with per-page
        # timestamps. Failed current requests cannot delete fresh past proof.
        for previous in old_pages.get(lang,[]):
            p=previous.get('path')
            if p in this:continue
            observed=previous.get('observed_at',old_at)
            try:age=now-datetime.datetime.fromisoformat(observed.replace('Z','+00:00')).timestamp()
            except (ValueError,TypeError,AttributeError):continue
            if age<0 or age>AGE_LIMIT:continue
            if previous.get('http_status')!=200:continue
            if any(len(previous.get(key,[]))>400 for key in ('elementor_ids','listing_ids','post_ids')):continue
            this[p]={**previous,'observed_at':observed}
        # Keep the freshest evidence when total pages exceed the bounded cap.
        def ordering(row):
            dt=row.get('observed_at','')
            try:time_key=datetime.datetime.fromisoformat(dt.replace('Z','+00:00')).timestamp()
            except (ValueError,AttributeError):time_key=0
            return (row['kind']!='home',row['kind']!='archive',-time_key)
        merged=sorted(this.values(),key=ordering)
        return lang,merged[:PER_LOCALE],next_cursor[lang],True
    fresh=0
    with concurrent.futures.ThreadPoolExecutor(max_workers=min(5,len(locales))) as executor:
        rows=list(executor.map(scan_one,locales))
    for lang,pages,next_step,successful in rows:
        if not pages:
            result['errors'].append('home_unavailable_'+lang)
            continue
        result['pages'][lang]=pages
        next_cursor[lang]=next_step
        if successful:fresh+=1
        else:result['errors'].append('retained_previous_locale_'+lang)
        print(lang,'retained',len(pages),'fresh',successful,'cursor',next_step,flush=True)
    # Most locales must be freshly available; old evidence is not an
    # authorization to accept an outage as a successful visibility refresh.
    if fresh<max(2,(len(locales)*4+4)//5):
        raise RuntimeError('current_public_html_coverage_incomplete')
    if len(result['pages'])<min(4,len(locales)) or sum(map(len,result['pages'].values()))<12:
        raise RuntimeError('coverage_incomplete_no_snapshot_replacement')
    OUT.parent.mkdir(parents=True,exist_ok=True)
    temp=OUT.with_suffix('.json.tmp')
    temp.write_text(json.dumps(result,ensure_ascii=False,separators=(',',':')))
    if temp.stat().st_size>240000:raise RuntimeError('snapshot_exceeds_240kb')
    temp.replace(OUT)
    state_temp=CURSOR.with_suffix('.json.tmp')
    state_temp.write_text(json.dumps(next_cursor,separators=(',',':')))
    state_temp.replace(CURSOR)
    print('COMMITTED',str(OUT),'languages',len(result['pages']),
        'pages',sum(map(len,result['pages'].values())),'errors',result['errors'],
        'elapsed_sec',round(time.time()-start,1),flush=True)
    return result

if __name__=='__main__':
    import fcntl
    OUT.parent.mkdir(parents=True,exist_ok=True)
    with OUT.with_name('frontend-visibility-scanner.lock').open('a+') as lock:
        try:
            fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        except BlockingIOError:
            print('SKIP: local frontend visibility scan already running',flush=True)
            raise SystemExit(0)
        try:observe()
        except Exception as exc:
            print('ERROR',str(exc),file=sys.stderr,flush=True)
            raise SystemExit(2)
