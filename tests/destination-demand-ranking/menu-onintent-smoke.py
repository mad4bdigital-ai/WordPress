import asyncio,json,hashlib
from pathlib import Path

ROOT=Path(__file__).resolve().parents[2]
CANONICAL=ROOT/'wp-content/plugins/mad4b-platform/legacy-mu-tree/assets/mad4b-navigation-lazy-listings.js'
EXPECTED_ASSET=f'navigation.{hashlib.sha256(CANONICAL.read_bytes()).hexdigest()[:12]}.js'
from playwright.async_api import async_playwright
CASES=[
 ('source-en','https://tours.dev.mad4b.com/', ['Cairo','Luxor','Giza','Aswan'],True),
 ('source-fr','https://tours.dev.mad4b.com/fr/', ['Le Caire','Louxor','Gizeh','Assouan'],True),
 ('source-de','https://tours.dev.mad4b.com/de/', ['Kairo','Luxor','Giza','Assuan'],True),
 ('source-ar','https://tours.dev.mad4b.com/ar/', ['القاهرة','الأقصر','الجيزة','أسوان'],True),
 ('source-es','https://tours.dev.mad4b.com/es/', ['El Cairo','Lúxor','Giza','Asuán'],True),
 ('source-it','https://tours.dev.mad4b.com/it/', ['Il Cairo','Luxor','Giza','Assuan'],True),
 ('wayfa-en','https://wayfatours.com/', ['Cairo','Luxor','Giza','Aswan'],False),
 ('wayfa-es','https://wayfatours.com/es/', ['El Cairo','Lúxor','Giza','Asuán'],False)]
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
  failures=[]
  for name,url,first,source in CASES:
   pg=await browser.new_page(viewport={'width':1440,'height':900},ignore_https_errors=True)
   try:
    response=await pg.goto(url,wait_until='domcontentloaded',timeout=53000)
    before=await pg.locator('#e-n-menu-content-1012 .navigation-editor-grid .jet-listing-grid__item').count()
    asset_url=await pg.locator('script#mad4b-navigation-lazy-listings-js').get_attribute('src')
    immutable_ok=bool(asset_url and asset_url.endswith('/mu-plugins/mad4b-navigation-lazy-listings/'+EXPECTED_ASSET))
    # A stale minified/legacy source must fail acceptance, even if a browser
    # happens to have the correct code cached and menus still appear to work.
    if immutable_ok:
     res=await pg.request.get(asset_url,timeout=12000)
     immutable_ok=res.status==200 and hashlib.sha256(await res.body()).hexdigest()==hashlib.sha256(CANONICAL.read_bytes()).hexdigest()

    await pg.locator('#e-n-menu-dropdown-icon-1012').first.click(timeout=9000)
    try:
     await pg.locator('#e-n-menu-content-1012 .navigation-editor-grid').first.locator('.jet-listing-grid__item').first.wait_for(timeout=9500)
    except Exception: pass
    data=await pg.evaluate('''()=>{const e=document.querySelector('#e-n-menu-content-1012 .navigation-editor-grid');return{labels:[...e?.querySelectorAll('.jet-listing-grid__item')||[]].map(x=>x.innerText.trim().split('\\n')[0]),className:e?.querySelector('.jet-listing-grid')?.className,expanded:document.querySelector('#e-n-menu-dropdown-icon-1012')?.getAttribute('aria-expanded'),version:[...document.scripts].filter(s=>s.src.includes('mad4b-navigation-lazy-listings')).map(s=>s.src.split('?').slice(-1)[0])}}''')
    okay=response.status==200 and len(data['labels'])>=4 and data['labels'][:4]==first and data['expanded']=='true' and (not source or before==0) and immutable_ok
    item={'site':name,'status':'PASS' if okay else 'FAIL','before':before,'after':data['labels'],'className':data['className'],'immutable_asset':asset_url if immutable_ok else 'FAIL'}
    print(json.dumps(item,ensure_ascii=False)[:800],flush=True)
    if not okay:failures.append(name)
   except Exception as e:
    print('ERROR',name,type(e).__name__,str(e)[:200],flush=True);failures.append(name)
   finally:await pg.close()
  await browser.close()
  if failures:raise RuntimeError('UNVERIFIED:'+','.join(failures))
asyncio.run(main())
