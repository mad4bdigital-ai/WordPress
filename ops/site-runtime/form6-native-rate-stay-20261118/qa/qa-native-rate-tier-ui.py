from playwright.sync_api import sync_playwright
import json
URL='/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/'
with sync_playwright() as p:
 browser=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
 total=0
 for host in ['https://tours.dev.mad4b.com','https://wayfatours.com']:
  for width in [412,1365]:
   page=browser.new_page(viewport={'width':width,'height':900},is_mobile=width<500,has_touch=width<500)
   errors=[]
   page.on('pageerror',lambda err:errors.append(str(err)[:200]))
   result=page.goto(host+URL+'?qa_tier_cards=1',wait_until='domcontentloaded',timeout=65000)
   assert result.status==200,(host,width,result.status)
   assert page.locator('.mad4b-rate-header-select[data-rate-id="60820"]').count()==1,(host,width,'no card')
   page.locator('[data-post-id="60820"] .jet-toggle__control').first.click(timeout=6000)
   page.wait_for_timeout(550)
   tier=page.locator('[data-post-id="60820"] .tour-occupancy-double.mad4b-native-tier-choice:visible').first
   assert tier.count(),(host,width,'tier was not enhanced')
   tier.click(timeout=6000)
   page.wait_for_timeout(550)
   state=page.evaluate("""() => {
    const broker=window.SharedPresentationSelectionV1.getSnapshot();
    const form=document.querySelector('form.fluent_form_6');
    const picker=form.querySelector('.shared-pricing-basis-field');
    const amount=form.querySelector('.shared-pricing-basis-select');
    const card=document.querySelector('[data-post-id="60820"] .tour-occupancy-double.mad4b-native-tier-choice');
    return {rate:broker.selectedRateId,property:broker.rates.find(x=>x.rateId===60820)?.stages?.simple,
      tier:amount?.value,formTierHidden:picker?.hidden,formTierDisplay:getComputedStyle(picker).display,
      formRateHidden:form.querySelector('.shared-form6-rate-selector')?.closest('label')?.hidden,
      cardPressed:card?.getAttribute('aria-pressed'),stayHidden:[...document.querySelectorAll('.shared-stay-picker')].every(x=>x.hidden),
      cardVisual:getComputedStyle(card).boxShadow};
   }""")
   assert state['rate']==60820 and state['property']=='36117',(host,width,state)
   assert state['tier']=='double' and state['formTierHidden'] and state['formTierDisplay']=='none',(host,width,state)
   assert state['formRateHidden'] and state['cardPressed']=='true',(host,width,state)
   assert state['stayHidden'] and 'inset' in state['cardVisual'],(host,width,state)
   page.locator('[data-post-id="60820"] .jet-toggle__control').first.click(timeout=6000)
   page.wait_for_timeout(1150)
   fallback=page.evaluate("""() => ({
     hidden:document.querySelector('.shared-pricing-basis-field').hidden,
     display:getComputedStyle(document.querySelector('.shared-pricing-basis-field')).display,
     value:document.querySelector('.shared-pricing-basis-select').value
   })""")
   assert not fallback['hidden'] and fallback['display']!='none' and fallback['value']=='double',(host,width,fallback)
   assert not errors,(host,width,errors)
   print('PASS',host,width,json.dumps({'open':state,'closedFallback':fallback}),flush=True)
   total+=1
   page.close()
 browser.close()
 print('RATE_TIER_PASS',total,'/',4)
