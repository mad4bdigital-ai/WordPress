"""Read-only presentation regression for native Tour gallery and focal points."""
from playwright.sync_api import sync_playwright
import json
sites=[
 ('wayfatours','https://wayfatours.com/tours-and-activities/7-day-egypt-nile-cruise-tour/',True),
 ('wayfatours_es','https://wayfatours.com/es/tours-and-activities/crucero-por-el-cairo-y-el-nilo-7-dias/',True),
 ('tours_dev','https://tours.dev.mad4b.com/tours-and-activities/7-day-egypt-nile-cruise-tour/',False)
]
with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
    try:
        for name,url,has_focal in sites:
            page=browser.new_page(viewport={'width':1366,'height':800})
            exceptions=[]
            page.on('pageerror',lambda e:exceptions.append(str(e)[:150]))
            r=page.goto(url,wait_until='domcontentloaded',timeout=34000)
            page.wait_for_timeout(400)
            frames=page.locator('.mad4b-gallery-frame-opener')
            if r.status!=200 or frames.count()!=5 or frames.first.get_attribute('role')!='button':
                raise AssertionError('Native gallery frame enrollment failed for '+name)
            if has_focal:
                if frames.last.evaluate('(e)=>getComputedStyle(e).backgroundPosition')!='50% 100%':
                    raise AssertionError('Subject focal point absent in '+name)
                middle=frames.nth(2).evaluate('(e)=>getComputedStyle(e).backgroundImage')
                first=frames.first.evaluate('(e)=>getComputedStyle(e).backgroundImage')
                if first==middle or 'photo_6822ebcda23f9' not in middle:
                    raise AssertionError('Original photo duplicate or museum photo missing in '+name)
            frames.first.click(position={'x':25,'y':35},timeout=5000)
            if page.locator('#elementor-popup-modal-15284').count()!=1 or exceptions:
                raise AssertionError('Existing Elementor popup/JS regression in '+name)
            print(json.dumps({'site':name,'result':'PASS','five_clickable_frames':True,
              'focal_subject_visible':has_focal,'errors':exceptions}))
            page.close()
    finally:
        browser.close()
