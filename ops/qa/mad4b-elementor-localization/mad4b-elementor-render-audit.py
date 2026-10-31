#!/usr/bin/env python3
"""Browser evidence for visible H1 and untranslated Elementor widgets.
Target-bound, read-only public browsing; writes only a validated WP option.
"""
import asyncio, json, re, subprocess, sys
from datetime import datetime, timezone
from playwright.async_api import async_playwright
BASE='https://tours.dev.mad4b.com'
CONTAINER='etg-commerce-staging-php'
WP=['docker','exec','-u','www-data',CONTAINER,'wp']
def wp(*args):
    p=subprocess.run(WP+list(args),capture_output=True,text=True,timeout=36)
    if p.returncode:raise RuntimeError('WP_CLI_FAILED: '+p.stderr[:150])
    return p.stdout.strip()
def page_specs():
    code = """$ids=[35138,16150];$langs=['en','ar','fr','de'];$result=[];
foreach($ids as $id){$p=get_post($id);if(!$p||$p->post_status!=='publish')continue;
$links=[];foreach($langs as $lang){$i=apply_filters('wpml_object_id',$id,$p->post_type,false,$lang);
if(!$i||get_post_status($i)!=='publish')continue;$url=get_permalink($i);
if(wp_parse_url($url,PHP_URL_HOST)!=='tours.dev.mad4b.com')continue;$links[$lang]=$url;}
$result[]=['id'=>$id,'urls'=>$links];}echo wp_json_encode($result);"""
    return json.loads(wp('eval',code))
async def visit(browser,sem,lang,url):
    async with sem:
        page=await browser.new_page(viewport={'width':1366,'height':850})
        try:
            response=await page.goto(url,wait_until='domcontentloaded',timeout=24000)
            await page.wait_for_timeout(120)
            if not response or response.status!=200:return None
            return await page.evaluate('''() => {
                const norm=(v)=>(v||'').replace(/\\s+/g,' ').trim();
                const isShown=(e)=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);
                    return r.width>0&&r.height>0&&s.display!=='none'&&s.visibility!=='hidden';};
                const h1=[...document.querySelectorAll('h1')].filter(isShown).length;
                const entries=[...document.querySelectorAll('.elementor-widget[data-id]')]
                    .filter(isShown).map(e=>{
                        const txt=norm(e.innerText);
                        const owner=e.closest('[data-elementor-id]');
                        return {id:e.dataset.id,text:txt,template_id:Number(owner?.dataset.elementorId||0)};
                    }).filter(e=>e.text.length>=13&&e.text.length<=200
                        &&/([A-Za-z]{3,}.*){2,}/.test(e.text)&&
                        !/^(Currency|USD|EUR|EGP|GBP)/.test(e.text)).slice(0,180);
                return {h1,entries};
            }''')
        except Exception as exc:
            print('BROWSER_READ_UNAVAILABLE',lang,type(exc).__name__,file=sys.stderr)
            return None
        finally:await page.close()
async def inspect():
    specs=page_specs()
    if not specs:raise RuntimeError('NO_PUBLISHED_SOURCE_PAGES')
    result={'contract':'mad4b.elementor.render-observations.v1','site':BASE,
            'observed_at':datetime.now(timezone.utc).isoformat(),'h1':{},'leaks':[]}
    async with async_playwright() as p:
        browser=await p.chromium.launch(headless=True,args=['--no-sandbox'])
        try:
            semaphore=asyncio.Semaphore(3)
            for spec in specs:
                urls=spec['urls']
                tasks=[visit(browser,semaphore,lang,url) for lang,url in urls.items()]
                rendered=await asyncio.gather(*tasks)
                pages=dict(zip(urls.keys(),rendered))
                if not pages.get('en'):continue
                source=pages['en']
                result['h1'][str(spec['id'])]={'count':source['h1'],'at':result['observed_at']}
                textmap={}
                for row in source['entries']:
                    if row['text'] and re.search(r'[A-Za-z]{3,}',row['text']):
                        textmap.setdefault(row['id'],set()).add(row['text'])
                for lang,render in pages.items():
                    if lang=='en' or not render:continue
                    for row in render['entries']:
                        value=row['text']
                        if value in textmap.get(row['id'],set()):
                            result['leaks'].append({
                                'lang':lang,'page_id':spec['id'],
                                'template_id':row['template_id'],
                                'widget_id':row['id'],
                                'text_sample':value[:120]})
        finally:await browser.close()
    # The built-in source WPML language, not a raw URL, establishes source authority.
    data=json.dumps(result,ensure_ascii=False,separators=(',',':'))
    if len(data)>75000:raise RuntimeError('EVIDENCE_OVER_SIZE')
    wp('option','update','mad4b_elementor_render_observations_v1',data,'--format=json','--autoload=no')
    return {'sources':len(result['h1']),'leaks':len(result['leaks']),
            'sample':result['leaks'][:10],'evidence_at':result['observed_at']}
if __name__=='__main__':
    if wp('option','get','home')!=BASE:raise SystemExit('HOST_ORIGIN_MISMATCH')
    print(json.dumps(asyncio.run(inspect()),ensure_ascii=False,indent=2))
