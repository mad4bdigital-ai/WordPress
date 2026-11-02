#!/usr/bin/env python3
"""Bounded same-site HTML visibility evidence for MAD4B WPML Staging."""
import concurrent.futures, datetime, json, re, time, urllib.parse, urllib.request
from collections import Counter
from html import unescape
from pathlib import Path
BASE='https://tours.dev.mad4b.com'
LANGS=('ar','fr','de','es','it')
OUT=Path('/opt/staging/mad4b-wpml-content-jobs-bridge-20261102/priority/frontend-visibility.json')
UA='MAD4B-FrontendVisibility-Acceptance/1.0'
def fetch(url):
    parsed=urllib.parse.urlparse(url)
    if parsed.scheme!='https' or parsed.netloc!='tours.dev.mad4b.com': return None
    try:
        req=urllib.request.Request(url,headers={'User-Agent':UA,'Accept':'text/html'})
        with urllib.request.urlopen(req,timeout=13) as r:
            final=urllib.parse.urlparse(r.url)
            if r.status!=200 or final.netloc!='tours.dev.mad4b.com':return None
            doc=r.read(1700000).decode('utf-8','replace')
            return doc
    except Exception:
        return None
def visible(html):
    return {
      'elementor_ids':sorted(set(re.findall(r'data-elementor-id=["\'](\d+)["\']',html))),
      'listing_ids':sorted(set(re.findall(r'data-listing-id=["\'](\d+)["\']',html))),
      'post_ids':sorted(set(re.findall(r'data-post-id=["\'](\d+)["\']',html))),
    }
result={'contract':'mad4b.wpml.frontend-visibility.v1','site':BASE,
        'observed_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'pages':{},'errors':[]}
for lang in LANGS:
    home=BASE+'/'+lang+'/'
    html=fetch(home)
    if not html:
        result['errors'].append('home_unavailable_'+lang)
        continue
    result['pages'][lang]=[{'path':'/'+lang+'/','kind':'home','http_status':200,**visible(html)}]
    # Follow a small, bounded sample of real same-language links; exact URLs
    # originate from the live homepage, never from arbitrary indexed content.
    links=[]
    for raw in re.findall(r'<a\b[^>]*\bhref=["\']([^"\']+)["\']',html,re.I):
        href=unescape(raw.strip())
        dest=urllib.parse.urlparse(urllib.parse.urljoin(home,href))
        if dest.netloc!='tours.dev.mad4b.com' or not dest.path.startswith('/'+lang+'/'):continue
        path=dest.path
        if path in ['/','/'+lang+'/'] or '/wp-' in path or path.endswith(('.jpg','.avif','.webp','.png','.pdf')):continue
        if path not in links:links.append(path)
    # One navigational category plus two actual public links.
    candidates=sorted(links,key=lambda p:(0 if re.search(r'tour|cruise|package|trip|voyage|reise|رحل',p,re.I) else 1,len(p),p))[:3]
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        futures=[(path,pool.submit(fetch,BASE+path)) for path in candidates]
        for path,f in futures:
            doc=f.result()
            if doc:result['pages'][lang].append({'path':path,'kind':'linked_page','http_status':200,**visible(doc)})
    print(lang,'scanned',len(result['pages'][lang]),'rendered_els',len(set(x for p in result['pages'][lang] for x in p['elementor_ids'])))
temp=OUT.with_suffix('.json.tmp')
temp.write_text(json.dumps(result,ensure_ascii=False,separators=(',',':')))
temp.replace(OUT)
print('PATH',OUT,'bytes',OUT.stat().st_size,'languages',len(result['pages']),'errors',result['errors'])
