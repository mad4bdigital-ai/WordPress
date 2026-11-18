from playwright.sync_api import sync_playwright
import json
SLUG='/tours-and-activities/best-of-egypt-cairo-luxor-nile-cruise-red-sea-12-days/'
CASES=[('source','https://tours.dev.mad4b.com'),('wayfatours','https://wayfatours.com')]
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
    passed=0
    for site,origin in CASES:
        for width in [412,1365]:
            page=browser.new_page(viewport={'width':width,'height':900},
                is_mobile=width<500,has_touch=width<500)
            errors=[]
            page.on('pageerror',lambda err: errors.append(str(err)[:200]))
            response=page.goto(origin+SLUG,wait_until='domcontentloaded',timeout=60000)
            assert response.status==200,(site,width,'HTTP',response.status)
            selector=page.locator('form.fluent_form_6 .shared-form6-rate-selector').first
            assert selector.count()==1,(site,width,'missing original form rate controller')
            for rate,property_id in [(60820,'36117'),(60830,'36239')]:
                selector.select_option(str(rate))
                page.wait_for_timeout(1000)
                data=page.evaluate("""() => {
                    let snap=window.SharedPresentationSelectionV1?.getSnapshot?.();
                    let stage=snap?.rates?.find(r=>r.rateId===snap.selectedRateId)?.stages||{};
                    let allHidden=(selector)=>[...document.querySelectorAll(selector)].every(e=>e.hidden&&getComputedStyle(e).display==='none');
                    return {rate:snap?.selectedRateId,property:String(stage.simple||''),
                      hotelHidden:allHidden('.shared-stay-source-selection'),
                      stayHidden:allHidden('.shared-stay-picker'),
                      fieldsHidden:allHidden('.shared-stay-field'),
                      occupancy:document.querySelector('form.fluent_form_6 .shared-pricing-basis-select')?.value,
                      hasRateBasis:!!document.querySelector('form.fluent_form_6 .shared-pricing-basis-select')}
                }""")
                assert data['rate']==rate,(site,width,rate,data)
                assert data['property']==property_id,(site,width,rate,data)
                assert data['hotelHidden'] and data['stayHidden'] and data['fieldsHidden'],(site,width,rate,data)
                assert data['hasRateBasis'],(site,width,'pricing basis must remain explicit')
                print('PASS',site,width,rate,json.dumps(data))
                passed+=1
            assert not errors,(site,width,errors)
            page.close()
    browser.close()
    assert passed==8,passed
    print('PASS_TOTAL',passed)
