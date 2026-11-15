from playwright.sync_api import sync_playwright
import json
URLS={
 'source':'https://tours.dev.mad4b.com/tours-and-activities/islamic-cairo-tour-full-day/',
 'wayfa':'https://wayfatours.com/tours-and-activities/islamic-cairo-tour-full-day/'
}
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-quic'])
 for site,url in URLS.items():
  for width in [390,1440]:
   page=browser.new_page(viewport={'width':width,'height':850})
   errors=[]
   page.on('pageerror',lambda err:errors.append(str(err)))
   page.route('**/*',lambda route:route.abort() if route.request.resource_type in ('image','font','media') else route.continue_())
   try:
    resp=page.goto(url,wait_until='domcontentloaded',timeout=19000)
    page.wait_for_function("()=>document.querySelector('.tour-journey-discovery')?.dataset.jdSplitReady==='1'",timeout=9000)
    data=page.evaluate("""()=>{
       const s=document.querySelector('.tour-journey-discovery');
       const cards=[...s.querySelectorAll('.tour-hl-ui__card')];
       const step1=cards[0];
       const step5=cards[4];
       const tiles=[...step1.querySelectorAll('.jd-place-tile')];
       const decoded=JSON.parse(s.dataset.tourDestinations||'[]');
       const trail=tiles.map(el=>({id:el.dataset.placeId||'',label:el.querySelector('.jd-place-tile__eyebrow')?.textContent.trim(),
                                evidence:el.dataset.tourEvidence||'',name:el.querySelector('.jd-place-tile__name')?.textContent.trim()}));
       return {mode:s.dataset.tourJourneyMode,highlights:cards.length,
         step1:trail,step5:step5?.querySelectorAll('.jd-place-tile').length,
         metadata:decoded.reduce((o,t)=>{o[t.evidence||'unspecified']=(o[t.evidence||'unspecified']||0)+1;return o;},{}),
         story:s.querySelectorAll('.jd-modes-v3__story-pointer').length,
         overflow:document.documentElement.scrollWidth>innerWidth};
     }""")
    assert resp.status==200 and not errors,(resp.status,errors)
    assert data['highlights']>=6
    assert sum(1 for t in data['step1'] if t['evidence']=='mentioned')>=2
    assert any(t['evidence']=='geographic-context' and t['label']=='Geographic context' for t in data['step1'])
    assert data['step5']==0 and not data['overflow']
    print(json.dumps({'site':site,'width':width,'ok':True,'data':data,'errors':errors},ensure_ascii=False),flush=True)
   except Exception as exc:
    print(json.dumps({'site':site,'width':width,'ok':False,'error':str(exc)[:410],'jsErrors':errors[:2]},ensure_ascii=False),flush=True)
   finally:page.close()
 browser.close()
