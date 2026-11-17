import asyncio,json
from playwright.async_api import async_playwright
from pathlib import Path
SRC=str(Path(__file__).resolve().parents[2]/'ops/site-company-overlays/wayfatours/media-runtime/wp-content/mu-plugins/assets/mad4b-city-visible-image.js')
HTML='''<!doctype html><html class="mad4b-city-geometry-lazy"><head><style>
.jet-listing-grid__slider{position:relative;width:900px;height:250px;overflow:hidden;margin:100px 0 0 100px}
.swiper-wrapper{display:flex;gap:0;width:max-content}
.swiper-slide{flex:0 0 300px;height:230px}
.elementor-element-95d1c9c{width:280px;height:200px;background-image:linear-gradient(red,blue)}
html.mad4b-city-geometry-lazy .elementor-element-95d1c9c:not(.mad4b-city-geometry-visible){background-image:none!important}
.next-arrow{position:absolute;top:5px;right:8px;z-index:10}
</style></head><body><div class="elementor-element-b25dbff"><div class="jet-listing-grid__slider swiper">
<div class="swiper-wrapper">''' + ''.join('<div class="jet-listing-grid__item swiper-slide"><div class="elementor-element-95d1c9c">'+str(i)+'</div></div>' for i in range(1,7)) + '''</div><button class="next-arrow">Next</button></div></div></body></html>'''
async def main():
 async with async_playwright() as p:
  browser=await p.chromium.launch(headless=True,args=['--no-sandbox'])
  page=await browser.new_page(viewport={'width':1280,'height':800})
  try:
   await page.set_content(HTML)
   await page.add_script_tag(path=SRC)
   await page.wait_for_timeout(180)
   a=await page.evaluate("""() => ({stat:window.mad4bCityImageStats,
      flags:[...document.querySelectorAll('.elementor-element-95d1c9c')].map(x=>x.classList.contains('mad4b-city-geometry-visible'))})""")
   assert a['stat']['intentWarm']==0 and a['stat']['idleWarm']==0,a
   # Programmatic scroll and synthetic pointerover must not trigger prefetch.
   await page.evaluate('''() => {
      window.dispatchEvent(new Event('scroll'));
      document.querySelector('.next-arrow').dispatchEvent(
        new PointerEvent('pointerover',{bubbles:true}));
   }''')
   await page.wait_for_timeout(100)
   no_user=await page.evaluate('() => window.mad4bCityImageStats.intentWarm')
   assert no_user==0,'synthetic browser/layout events must not prewarm'
   await page.locator('.next-arrow').hover()
   await page.wait_for_timeout(600)
   b=await page.evaluate("""() => ({stat:window.mad4bCityImageStats,
      flags:[...document.querySelectorAll('.elementor-element-95d1c9c')].map(x=>x.classList.contains('mad4b-city-geometry-visible'))})""")
   assert b['stat']['intentWarm']>0,b
   print('PASS_NO_EAGER_WARM_AND_SYNTHETIC_EVENTS',json.dumps(a))
   print('PASS_HOVER_NAV_WARM',json.dumps(b))
  finally:
   await browser.close()
asyncio.run(main())
