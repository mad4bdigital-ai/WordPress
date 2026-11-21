import asyncio, json, os
from pathlib import Path
from playwright.async_api import async_playwright
ROOT=Path(__file__).resolve().parents[3]
FIXTURE=(Path(__file__).parent/'fixtures/pricing-components-v2.html').as_uri()
STYLE=ROOT/'wp-content/mu-plugins/assets/mad4b-pricing-interface-v2.css'
STAY=os.environ.get('MAD4B_STAY_STYLES','/tmp/mad4b_stay_vendor.css')
TRANSPORT=os.environ.get('MAD4B_TRANSPORT_STYLES','/tmp/mad4b_transport_vendor.css')
async def run():
 async with async_playwright() as p:
  browser=await p.chromium.launch(headless=True,executable_path='/root/.cache/ms-playwright/chromium_headless_shell-1194/chrome-linux/headless_shell',args=['--no-sandbox','--disable-dev-shm-usage'])
  results=[]
  for width in [1440,1024,768,390,320]:
   page=await browser.new_page(viewport={'width':width,'height':950},device_scale_factor=1)
   await page.goto(FIXTURE)
   for vendor in [STAY,TRANSPORT]:
    if Path(vendor).exists():await page.add_style_tag(path=vendor)
   await page.add_style_tag(path=str(STYLE))
   summary=await page.evaluate("""() => {
    const root=document.querySelector('.jet-engine-options-page-wrap');
    const stay=root.querySelector('.mad4b-stay-panel__accordion');
    const transport=root.querySelector('.mad4b-ts-accordion');
    const guide=root.querySelector('#mad4b-guide-taxonomy-cost-panel');
    const card=root.querySelector('.mad4b-ts-card');
    const fg=root.querySelector('.mad4b-stay-panel__group');
    const source=root.querySelector('.mad4b-ts-source>span');
    const f=root.querySelector('#mad4b-guide-default-form');
    const box=e=>{const r=e.getBoundingClientRect();return {x:r.x,w:r.width,right:r.right}};
    return {
      overflow:Math.round(document.documentElement.scrollWidth-innerWidth),
      stayBorder:getComputedStyle(stay).borderLeftWidth,
      transportBorder:getComputedStyle(transport).borderLeftWidth,
      guideBorder:getComputedStyle(guide).borderLeftWidth,
      cardBorder:getComputedStyle(card).borderLeftWidth,
      sourceBorder:getComputedStyle(source).borderLeftWidth,
      formDisplay:getComputedStyle(f).display,
      staySummaryHeight:Math.round(stay.querySelector('summary').getBoundingClientRect().height),
      tsSummaryHeight:Math.round(transport.querySelector('summary').getBoundingClientRect().height),
      guide:box(guide),stay:box(stay),transport:box(transport),
      forms:root.querySelectorAll('form').length,
      guideTableOverflow:guide.querySelector('table').parentElement.scrollWidth>=guide.querySelector('table').parentElement.clientWidth
    }
   }""")
   for key in ('stayBorder','transportBorder','guideBorder','cardBorder','sourceBorder'):
    assert summary[key]=='0px',f'{width}: nested component border persists {key} {summary[key]}'
   assert summary['overflow']<=8,f'{width}: body horizontal overflow: {summary}'
   assert abs(summary['staySummaryHeight']-summary['tsSummaryHeight'])<=23,f'{width}: unrelated top-level disclosure styles'
   assert summary['forms']==1 and summary['guideTableOverflow'],f'{width}: guide source form/table altered'
   assert summary['formDisplay'] in ('flex','grid'),f'{width}: supplier cost form layout missing'
   if width in (1440,390):
    await page.screenshot(path='/tmp/mad4b_pricing_components_v2_'+str(width)+'.png',full_page=True)
   results.append({'width':width, 'status':'PASS', **summary})
   await page.close()
  await browser.close()
  print('PRICING_COMPONENTS_V2_VISUAL_ACCEPTANCE '+json.dumps(results))
asyncio.run(run())
