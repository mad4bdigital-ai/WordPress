from playwright.sync_api import sync_playwright
import sys,json
lang=sys.argv[1] if len(sys.argv)>1 else 'en'
width=int(sys.argv[2]) if len(sys.argv)>2 else 1440
url='https://tours.dev.mad4b.com/'+('ar/' if lang=='ar' else '')+'tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/'
o={'lang':lang,'width':width,'jsErrors':[]}
with sync_playwright() as p:
 b=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
 page=b.new_page(viewport={'width':width,'height':900})
 page.on('pageerror',lambda e:o['jsErrors'].append(str(e)[:140]))
 try:
  resp=page.goto(url,wait_until='domcontentloaded',timeout=36000)
  o['http']=resp.status
  page.wait_for_timeout(900)
  page.evaluate('window.scrollTo({top:2100,behavior:"instant"})')
  page.wait_for_timeout(520)
  # Translated pages may position the inquiry column lower in the document.
  # Reach its natural sticky region without altering Elementor positioning.
  if width>=1200:
   for _ in range(5):
    yy=page.evaluate("""() => document.querySelector('.mad4b-product-sticky-panel > .e-con')?.getBoundingClientRect().y""")
    if isinstance(yy,(int,float)) and yy<=42:break
    page.evaluate('window.scrollBy(0,800)')
    page.wait_for_timeout(370)
  o['values']=page.evaluate(r"""()=>{
   let el=document.querySelector('.mad4b-product-sticky-panel'),old=document.querySelector('.mad4b-tour-inquiry-column'),main=el?.parentElement?.querySelector(':scope > .e-con:not(.mad4b-product-sticky-panel)'),
    sticky=el?.querySelector(':scope > .e-con'),s=sticky?getComputedStyle(sticky):null,
    b=sticky?.getBoundingClientRect(),inp=el?.querySelector('input:not([type="hidden"])'),r=inp?.getBoundingClientRect(),hit=r?document.elementFromPoint(r.x+r.width/2,r.y+r.height/2):null;
   return {marked:!!el,tourMarker:!!old,mainIsolate:main?getComputedStyle(main).isolation:null,
    stickyPos:s?.position,stickyZ:s?.zIndex,stickyBox:b?[Math.round(b.x),Math.round(b.y),Math.round(b.width),Math.round(b.height)]:null,
    inputOnscreen:!!r&&r.y>0&&r.y<innerHeight,inputHittable:!!inp&&inp.contains(hit),scroll:scrollY};
  }""")
  v=o['values']
  if width>=1200 and v.get('inputHittable'):
   inp=page.locator('.mad4b-product-sticky-panel input:not([type="hidden"])').first
   box=inp.bounding_box()
   if box and 0<box['y']<800:
    page.mouse.click(box['x']+box['width']/2,box['y']+box['height']/2)
    o['focus']=inp.evaluate('(e)=>document.activeElement===e')
  o['pass']=(resp.status==200 and v['marked'] and v['tourMarker'] and v['mainIsolate']=='isolate'
     and not o['jsErrors'] and
      (width<1200 or (v['stickyPos']=='sticky' and v['stickyZ']=='15' and v['inputHittable'] and o.get('focus') is True)))
 except Exception as e:o['error']=str(e)[:240];o['pass']=False
 b.close()
print(json.dumps(o,ensure_ascii=False))
sys.exit(0 if o['pass'] else 1)
