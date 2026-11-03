"""Read-only authorized, locale-scoped Chromium public UX observer.
No cookies, credentials, form submissions, external sites, or mutations.
"""
import asyncio,datetime,json,sys,os
from pathlib import Path
from playwright.async_api import async_playwright
BASE=os.environ['MAD4B_SITE_BASE'].rstrip('/')
SITE=os.environ['MAD4B_SITE_UUID']
LANG=os.environ['MAD4B_ACTIVE_LANGUAGES'].split(',')
DEFAULT=os.environ['MAD4B_DEFAULT_LANGUAGE']
ARCHIVE_PATH=os.environ.get('MAD4B_ARCHIVE_PATH','/tours-and-activities/')
if not BASE.startswith('https://') or len(SITE)<20 or DEFAULT not in LANG or len(LANG)>16:
 raise SystemExit('invalid authorized site scope')
COLLECT_PAGES=['home','archive']
NEEDLES=json.loads(os.environ.get('MAD4B_LABEL_CANDIDATES_JSON','{}'))
if not isinstance(NEEDLES,dict) or any(not isinstance(v,list) for v in NEEDLES.values()):
 raise SystemExit('invalid non-authorizing label review hints')

async def check(browser,sem,lang,device,kind):
 async with sem:
  v={'width':390,'height':844} if device=='mobile' else {'width':1440,'height':900}
  ctx=await browser.new_context(viewport=v,device_scale_factor=1)
  page=await ctx.new_page()
  errors=[]
  page.on('response',lambda r: errors.append({'url':r.url,'status':r.status}) if r.status in (404,410) and r.url.startswith(BASE+'/wp-content/') and len(errors)<20 else None)
  prefix='' if lang==DEFAULT else '/'+lang
  url=BASE+prefix+('/' if kind=='home' else ARCHIVE_PATH)
  result={'language':lang,'device':device,'url':url,'status':0,'sliders':[],
          'cross_locale_urls':[],'asset_errors':[],'suspect_labels':[],'visible_filters':[]}
  try:
   response=await page.goto(url,wait_until='domcontentloaded',timeout=22000)
   await page.wait_for_timeout(950 if kind=='home' else 700)
   result['status']=response.status if response else 0
   snap=await page.evaluate("""(settings) => {
    const [kind,lang,base,langs,needles]=settings;
    const host=new URL(base).hostname;
    const visible=e=>!!e.getClientRects().length&&getComputedStyle(e).visibility!=='hidden'&&getComputedStyle(e).display!=='none';
    const slider=[...document.querySelectorAll('.jet-listing-grid__slider')];
    const slides=slider.map(e=>e.querySelectorAll('.swiper-slide:not(.swiper-slide-duplicate)').length);
    const roots=kind==='home'?slider:[...document.querySelectorAll('.jet-listing-grid')];
    const links=new Set();
    const isCross=u=>{try{
      const v=new URL(u,location.href);if(v.hostname!==host||v.protocol!=='https:')return false;
      if(v.hash && v.pathname===location.pathname)return false;
      const p=v.pathname;
      if(/\\/wp-content\\/|\\/wp-json\\//.test(p))return false;
      const first=p.split('/').filter(Boolean)[0]||'';
      const observed=langs.includes(first)?first:'en';
      return observed!==lang && (kind==='home'||p.includes('/tours-and-activities/'));
    }catch(e){return false}};
    for(const root of roots)for(const a of root.querySelectorAll('a[href]')){
      const u=a.href;
      if(u&&isCross(u))links.add(u.slice(0,1200));
    }
    const text=(kind==='home'?
       document.body.innerText:
       [...document.querySelectorAll('header,.jet-filter,.elementor-widget-jet-listing-grid')].map(x=>x.innerText).join(' '));
    const labels=needles.filter(x=>text.includes(x));
    const filters=new Set();
    if(kind==='archive')
       for(const widget of document.querySelectorAll('.jet-filter')){
          if(!visible(widget))continue;
          for(const e of widget.querySelectorAll('[data-query-var]')){
           const v=e.getAttribute('data-query-var');
           if(v && /^[a-z][a-z0-9_-]{0,49}$/.test(v))filters.add(v);
          }
       }
    return {htmlLang:document.documentElement.lang,dir:document.documentElement.dir,
      sliders:kind==='home'?slides.slice(0,8):[],links:[...links].slice(0,12),
      labels:labels.slice(0,12),filters:[...filters].slice(0,16)};
    }""",[kind,lang,BASE,LANG,NEEDLES.get(lang,[])])
   result['sliders']=snap['sliders']
   result['cross_locale_urls']=snap['links']
   result['suspect_labels']=snap['labels']
   result['visible_filters']=snap['filters']
   # Verify any hero image preload advertised by the live DOM with a same-origin
   # read-only HTTP GET; a browser cache or deferred image may hide the 404.
   if kind=='home':
    hero=await page.locator('link[data-mad4b-hero-poster][href]').first.get_attribute('href') if await page.locator('link[data-mad4b-hero-poster][href]').count() else None
    if hero and hero.startswith(BASE+'/'):
     response=await ctx.request.get(hero,timeout=12000)
     if response.status in (404,410):
      errors.append({'url':hero,'status':response.status})
   result['asset_errors']=list({(r['status'],r['url']):r for r in errors}.values())[:12]
   result['html_lang']=snap['htmlLang']
   result['dir']=snap['dir']
  except Exception as e:
   result['error']=str(e).splitlines()[0][:140]
  await ctx.close()
  return (kind,lang+'|'+device,result)

async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(headless=True,args=['--no-sandbox'])
  sem=asyncio.Semaphore(3)
  records=list(await asyncio.gather(*(check(browser,sem,lang,device,kind) for kind in COLLECT_PAGES for lang in LANG for device in ('desktop','mobile'))))
  # Retry timeouts once, serially, without treating partial browser evidence as complete.
  for i,(kind,key,screen) in enumerate(records):
   if screen['status']==200: continue
   lang,device=key.split('|')
   records[i]=await check(browser,asyncio.Semaphore(1),lang,device,kind)
  await browser.close()
 bad=[(kind,key,screen.get('error')) for kind,key,screen in records
       if screen['status']!=200]
 if bad:
  print('INCOMPLETE_PUBLIC_BROWSER_SCAN',repr(bad),file=sys.stderr)
  raise SystemExit(3) # Preserve previous original evidence; never false-resolve.
 combined={kind+'|'+key:screen for kind,key,screen in records}
 report={'contract':'mad4b.locale-browser-observation.v1','site_uuid':SITE,
         'captured_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
         'surfaces':COLLECT_PAGES,'screens':combined}
 outfile=Path(os.environ.get('MAD4B_OBSERVATION_OUT','/tmp/mad4b-locale-browser-current.json'))
 tmp=outfile.with_suffix('.json.tmp')
 tmp.write_text(json.dumps(report,ensure_ascii=False,separators=(',',':')))
 tmp.replace(outfile)
 print('OBSERVATION_OK',str(outfile),'screens',len(combined),
       'asset_errors',sum(len(x['asset_errors']) for x in combined.values()))
if __name__=='__main__':asyncio.run(main())
