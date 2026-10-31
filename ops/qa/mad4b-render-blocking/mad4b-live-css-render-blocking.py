#!/usr/bin/env python3
"""Staging-only live mobile browser probe; no website changes or external APIs."""
import asyncio,datetime,hashlib,json,os,pathlib,re,subprocess
from html.parser import HTMLParser
from urllib.parse import urlsplit
from playwright.async_api import async_playwright
ORIGIN='https://tours.dev.mad4b.com'
SITE_ID='e5e64bca-ed62-48d2-b2b3-dff693cd702a'
PAGES=['/','/ar/','/fr/','/de/','/es/','/it/','/destination/africa-containent/egypt/']
OUT=pathlib.Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/qa/render-blocking-uael-20261031')
OUTPUT=OUT/'live-css-scan.json'
CONTRACT='mad4b.live-render-blocking.v1'
class Links(HTMLParser):
    def __init__(self):
        super().__init__();self.styles=[]
    def handle_starttag(self,tag,attrs):
        if tag!='link':return
        d=dict(attrs)
        if 'stylesheet' not in (d.get('rel') or '').split():return
        self.styles.append({'id':d.get('id') or '', 'href':d.get('href') or '',
            'media':d.get('media') or 'all',
            'onload':bool(d.get('onload'))})
async def inspect(browser,path,sem):
    async with sem:
        page=await browser.new_page(viewport={'width':390,'height':844},is_mobile=True,has_touch=True)
        url=ORIGIN+path
        try:
            response=await page.goto(url,wait_until='domcontentloaded',timeout=25000)
            if not response or response.status!=200:raise RuntimeError('http_status_'+str(response.status if response else None))
            raw=await response.text()
            parser=Links();parser.feed(raw)
            await page.wait_for_timeout(750)
            live=await page.evaluate('''() => ({
                uael:[...document.querySelectorAll('[data-widget_type^="uael-"]')]
                  .map(x=>({type:x.getAttribute('data-widget_type'),
                   height:Math.round(x.getBoundingClientRect().height)})),
                resources:performance.getEntriesByType('resource')
                   .filter(x=>x.initiatorType==='link' || x.name.includes('/css/'))
                   .map(x=>({url:x.name,encoded:x.encodedBodySize||0,
                      duration:Math.round(x.duration)})).slice(0,300),
                title:document.title,
                pageOverflow:Math.max(0,document.documentElement.scrollWidth-innerWidth)})''')
            resources={r['url']:r for r in live['resources']}
            styles=[]
            for a in parser.styles:
                u=a['href']; resource=resources.get(u, {})
                # urlsplit ignores query strings for cache-busting.
                if not (u.startswith(ORIGIN+'/') or not u.startswith('http')):continue
                entry={'handle':a['id'],'media_initial':a['media'],'has_onload':a['onload'],
                       'url_path':urlsplit(u).path,'encoded_bytes':int(resource.get('encoded') or 0),
                       'duration_ms':int(resource.get('duration') or 0)}
                entry['blocking']=a['media'].lower() in ('','all','screen') and not a['onload']
                styles.append(entry)
            return {'url':url,'http_status':response.status,'mobile_viewport':390,
                'title':live['title'],'uael_widgets':live['uael'],'css':styles,
                'horizontal_overflow_px':live['pageOverflow']}
        finally:
            await page.close()
def classify(pages):
    """Produce stable, per-URL CSS alerts only for measured mobile blocking assets."""
    issues=[]
    for page in pages:
        urlpath=urlsplit(page['url']).path
        seen_uael=any(x['height']>12 for x in page['uael_widgets'])
        for style in page['css']:
            kb=style['encoded_bytes']/1024
            if not style['blocking'] or kb<64:continue
            identity=urlpath+'|'+style['handle']
            code='perf_rbcss_'+hashlib.sha256(identity.encode()).hexdigest()[:12]
            label=f'Mobile render-blocking {style["handle"] or "CSS"} ({kb:.0f} KiB transfer) on {urlpath}; '+(
                'UAEL widget present — retain styles.' if seen_uael else 'No visible UAEL widget — defer CSS safely.'
            )
            issues.append({'code':code,'severity':'high','label':label[:225]})
    return issues

async def run():
    if os.geteuid()!=0:raise RuntimeError('must_run_with_authorized_host_identity')
    OUT.mkdir(parents=True,exist_ok=True)
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,args=['--no-sandbox'])
        try:
            semaphore=asyncio.Semaphore(3)
            pages=await asyncio.gather(*[inspect(browser,x,semaphore) for x in PAGES])
        finally:await browser.close()
    issues=classify(pages)
    now=datetime.datetime.now(datetime.timezone.utc).isoformat()
    payload={'contract':CONTRACT,'complete':True,'site_uuid':SITE_ID,'origin':ORIGIN,
        'observed_at':now,'pages':pages,'issues':issues}
    OUTPUT.write_text(json.dumps(payload,ensure_ascii=False,indent=2),encoding='utf-8')
    # Pre-authorized WPCLI exact site identity; no login impersonation.
    bind=[
      '-e','MAD4B_CLI_TARGET_SITE_UUID='+SITE_ID,
      '-e','MAD4B_CLI_TARGET_ORIGIN='+ORIGIN,
      '-e','MAD4B_CLI_TARGET_DATABASE=etg_commerce_staging',
      '-e','MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=staging'
    ]
    cli=['/usr/bin/docker','exec','-u','www-data']+bind+['etg-commerce-staging-php','wp']
    identity=subprocess.run(cli+['option','get','home'],capture_output=True,text=True,timeout=16)
    if identity.returncode or identity.stdout.strip()!=ORIGIN:
        raise RuntimeError('site_identity_readback_failed')
    copy=subprocess.run(['/usr/bin/docker','cp',str(OUTPUT),'etg-commerce-staging-php:/tmp/mad4b-live-css-scan.json'],
       capture_output=True,text=True,timeout=15)
    if copy.returncode:raise RuntimeError('local_evidence_copy_failed')
    ingest=subprocess.run(cli+['mad4b','perf','monitor','import',
        '--file=/tmp/mad4b-live-css-scan.json','--confirm=IMPORT LIVE CSS EVIDENCE'],
        capture_output=True,text=True,timeout=30)
    if ingest.returncode:raise RuntimeError('WP_CLI_evidence_ingest_failed:'+ingest.stderr[:250])
    print(json.dumps({'site':ORIGIN,'pages':len(pages),'uael_mobile':[
        {'url':urlsplit(pg['url']).path,'has_visible_uael':any(x['height']>12 for x in pg['uael_widgets']),
         'uael_css':next(({'blocking':s['blocking'],'media_initial':s['media_initial'],
             'encoded_kib':round(s['encoded_bytes']/1024,1)} for s in pg['css']
             if s['handle']=='uael-frontend-css'),None)} for pg in pages],
        'issues':issues,'wpcli_import':json.loads(ingest.stdout)},ensure_ascii=False,indent=2))
if __name__=='__main__':asyncio.run(run())
