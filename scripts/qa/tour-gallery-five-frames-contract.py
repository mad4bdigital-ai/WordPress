"""Read-only browser acceptance of MAD4B gallery frame->native popup delegation.

python3 scripts/qa/tour-gallery-five-frames-contract.py --host wayfatours.com
python3 scripts/qa/tour-gallery-five-frames-contract.py --host tours.dev.mad4b.com
"""
from __future__ import annotations
import argparse
import json
from playwright.sync_api import sync_playwright

def main() -> None:
    parser=argparse.ArgumentParser()
    parser.add_argument('--host',choices=['wayfatours.com','tours.dev.mad4b.com'],required=True)
    args=parser.parse_args()
    url=f'https://{args.host}/tours-and-activities/7-day-egypt-nile-cruise-tour/'
    tests=[]
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,args=['--no-sandbox'])
        try:
            for width,method in [(1366,'click'),(1366,'keyboard'),(390,'touch')]:
                page=browser.new_page(viewport={'width':width,'height':780},
                    is_mobile=width==390,has_touch=width==390)
                errors=[]
                page.on('pageerror',lambda e:errors.append(str(e)[:160]))
                response=page.goto(url,wait_until='domcontentloaded',timeout=35000)
                frames=page.locator('.mad4b-gallery-frame-opener')
                ids=frames.evaluate_all('(items)=>items.map(e=>e.dataset.id)')
                if len(ids)!=5 or ids[0]!='ef55af8':
                    raise AssertionError('Standalone portrait must be first of all five native background frames: '+str(ids))
                if page.locator('#single_tour_gellery_button').count()!=1:
                    raise AssertionError('Original Elementor All Photos button unavailable or duplicated')
                if frames.last.get_attribute('role') is not None:
                    raise AssertionError('Frame with original link cannot be a nested ARIA button')
                first=frames.first
                first.scroll_into_view_if_needed()
                if method=='keyboard':
                    first.focus()
                    first.press('Enter',timeout=5500)
                else:
                    first.click(position={'x':35,'y':40},timeout=6500)
                page.wait_for_timeout(250)
                modal=page.locator('#elementor-popup-modal-15284')
                success=(response.status==200 and modal.count()==1 and modal.is_visible() and not errors)
                tests.append({'width':width,'method':method,'http':response.status,
                    'five_frames':True,'portrait_opens_original_popup':success,'js_errors':errors})
                page.close()
                if not success:
                    raise AssertionError('Native Elementor popup did not open: '+str(tests[-1]))
        finally:
            browser.close()
    print(json.dumps({'site':args.host,'result':'PASS','checks':len(tests),
        'tests':tests},ensure_ascii=False))

if __name__=='__main__':
    main()
