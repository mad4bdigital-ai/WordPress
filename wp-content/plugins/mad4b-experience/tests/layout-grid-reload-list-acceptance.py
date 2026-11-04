#!/usr/bin/env python3
"""Upgrade-safe signed JetEngine layout acceptance: click Grid, reload, click List."""
import sys,json
from playwright.sync_api import sync_playwright

lang=sys.argv[1] if len(sys.argv)>1 else 'en'
width=int(sys.argv[2]) if len(sys.argv)>2 else 1368
locales={
 'en':('','25102','33747','30843'),
 'ar':('ar/','40683','40588','40489'),
 'es':('es/','35084','33753','35125'),
 'fr':('fr/','35070','33749','35130'),
 'de':('de/','35076','33751','35127'),
 'it':('it/','39902','39838','40132')
}
prefix,listing,grid,doc=locales[lang]
url='https://tours.dev.mad4b.com/'+prefix+'tours-and-activities/'
result={'locale':lang,'viewport':width,'states':[],'jsErrors':[],'ajax':[]}
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
 ctx=browser.new_context(viewport={'width':width,'height':900},is_mobile=width<600,has_touch=width<600)
 page=ctx.new_page()
 page.on('pageerror',lambda error:result['jsErrors'].append(str(error)[:180]))
 def response(resp):
  if resp.request.method!='POST' or 'nocache=1' not in resp.url:return
  try:
   data=resp.request.post_data_buffer or b''
   if b'switch_layout' not in data:return
   j=resp.json()
   result['ajax'].append({'status':resp.status,'success':j.get('success'),'error':str(j.get('data',''))[:100] if j.get('success') is False else None})
  except Exception:pass
 page.on('response',response)
 state=r"""step=>{
  let w=document.querySelector('.je-layout-switcher'),el=document.querySelector('.elementor-element-'+w?.dataset.widgetId+' .jet-listing-grid__items'),nav={};
  try{nav=JSON.parse(el?.dataset.nav||'{}')}catch(e){}
  let a=el?.querySelector('.jet-listing-grid__item a[href]');
  return {step,active:w?.querySelector('.je-layout-switcher__btn--active')?.dataset.slug,
    template:el?.className.match(/jet-listing-grid--([0-9]+)/)?.[1],widgetId:w?.dataset.widgetId,
    navId:nav.widget_settings?._id,base:nav.widget_settings?.lisitng_id,queryId:nav.widget_settings?._element_id,
    document:el?.closest('.elementor[data-elementor-id]')?.dataset.elementorId,
    querySigned:!!nav.query?.signature,settingsSigned:!!nav.widget_settings?._jet_engine_settings_signature,
    busy:w?.classList.contains('je-layout-switcher--loading'),pointer:a?getComputedStyle(a).pointerEvents:null};
 }"""
 def snap(step):result['states'].append(page.evaluate(state,step))
 try:
  result['http']=page.goto(url,wait_until='domcontentloaded',timeout=33000).status
  page.locator('.je-layout-switcher__btn[data-slug="grid"]').first.wait_for(state='visible',timeout=16000)
  page.wait_for_timeout(400);snap('initial')
  # Wait for the native cookie endpoint, not just DOM commit; otherwise
  # the reload may race cookie persistence and restore List unexpectedly.
  with page.expect_response(lambda r:r.request.method=='POST' and b'jet_engine_switch_layout' in (r.request.post_data_buffer or b''),timeout=26000):
   page.locator('.je-layout-switcher__btn[data-slug="grid"]').first.click(timeout=7500)
  page.wait_for_function("""wanted=>{let w=document.querySelector('.je-layout-switcher'),el=document.querySelector('.elementor-element-'+w.dataset.widgetId+' .jet-listing-grid__items');return !!el?.classList.contains('jet-listing-grid--'+wanted)&&!w.classList.contains('je-layout-switcher--loading')&&!!w.querySelector('.je-layout-switcher__btn[data-slug="grid"].je-layout-switcher__btn--active')}""",arg=grid,timeout=22000)
  snap('grid')
  page.reload(wait_until='domcontentloaded',timeout=33000)
  page.wait_for_function("""wanted=>{let w=document.querySelector('.je-layout-switcher'),el=document.querySelector('.elementor-element-'+w.dataset.widgetId+' .jet-listing-grid__items');return !!el?.classList.contains('jet-listing-grid--'+wanted)&&!!w.querySelector('.je-layout-switcher__btn[data-slug="grid"].je-layout-switcher__btn--active')}""",arg=grid,timeout=15000)
  snap('grid-after-reload')
  page.locator('.je-layout-switcher__btn[data-slug="list"]').first.click(timeout=7500)
  page.wait_for_function("""wanted=>{let w=document.querySelector('.je-layout-switcher'),el=document.querySelector('.elementor-element-'+w.dataset.widgetId+' .jet-listing-grid__items');return !!el?.classList.contains('jet-listing-grid--'+wanted)&&!w.classList.contains('je-layout-switcher--loading')&&!!w.querySelector('.je-layout-switcher__btn[data-slug="list"].je-layout-switcher__btn--active')}""",arg=listing,timeout=25000)
  snap('list-after-reload')
 except Exception as error:
  result['failure']=str(error)[:280]
  try:snap('failure')
  except Exception:pass
 browser.close()
states=result['states']
result['pass']=result.get('http')==200 and len(states)==4 and not result.get('failure') and not result['jsErrors'] and [s['active'] for s in states]==['list','grid','grid','list'] and [s['template'] for s in states]==[listing,grid,grid,listing] and all(s['widgetId']==s['navId']=='7739f090' and s['querySigned'] and s['settingsSigned'] and s['document']==doc and s['pointer']=='auto' for s in states) and not any(r['success'] is False for r in result['ajax'])
print(json.dumps(result,ensure_ascii=False)[:6200])
sys.exit(0 if result['pass'] else 1)
