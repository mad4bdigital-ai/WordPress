import json, sys, datetime
from playwright.sync_api import sync_playwright, TimeoutError as PlaywrightTimeoutError

BASE="https://tours.dev.mad4b.com"
REPORT={'contract':'mad4b.frontend-impact-browser-acceptance.v1',
        'site':BASE, 'at_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
        'no_submit_or_supplier_actions': True, 'checks':[]}
def checked(name, ok, details=None):
    REPORT['checks'].append({'name':name,'pass':bool(ok),'details':details or {}})

def archive(browser, lang, target):
    url=BASE+('/es' if lang=='es' else '')+'/tours-and-activities/'
    page=browser.new_page(viewport={'width':1440,'height':900})
    ajax=[]; attempted=[]
    page.on('response', lambda r: ajax.append(r.status) if 'admin-ajax.php' in r.url else None)
    page.on('request',lambda r: attempted.append(r.url.split('?')[0]) if r.method=='POST' and ('checkout' in r.url or 'wc-ajax' in r.url or 'book' in r.url) else None)
    try:
        response=page.goto(url,wait_until='domcontentloaded',timeout=45000)
        page.wait_for_timeout(650)
        label=page.locator('label:has(input[name="tour-types"][data-label="'+target+'"])').first
        # The page has 20+ JetEngine grids: header mega-menu listings,
        # hidden editorial content and nested card enrichments. Only the
        # real archive's *direct* card children are filter results.
        main=page.locator('#tours_query_archive .jet-listing-grid').first
        cards=main.locator(':scope > .jet-listing-grid__items > .jet-listing-grid__item')
        initial=cards.count()
        checked(lang+'_archive_grid_scoped',main.count()==1 and initial>0,
                {'main_archive_items':initial})
        if lang=='en':
            item=page.locator('details.e-n-accordion-item:has(input[name="tour-types"])').first
            checked('en_accordion_collapsed_initially',item.count()>0 and item.get_attribute('open') is None)
            item.locator('summary').first.click(timeout=9000)
            checked('en_accordion_expands',item.get_attribute('open') is not None)
        checked(lang+'_filter_label_visible',label.is_visible())
        if not label.is_visible():return
        label.click(timeout=9500)
        # Wait for real AJAX completion in the intended grid instead of a
        # fixed delay and an unrelated global .jet-listing-grid__item count.
        # Preserve a failed result if the archive never updates.
        try:
            page.wait_for_function("""before => {
                const g=document.querySelector('#tours_query_archive .jet-listing-grid');
                if (!g || g.classList.contains('jet-filters-loading')) return false;
                const count=g.querySelectorAll(':scope > .jet-listing-grid__items > .jet-listing-grid__item').length;
                return count > 0 && count < before;
            }""",arg=initial,timeout=25000)
        except PlaywrightTimeoutError:
            pass
        after=cards.count()
        slug='paquetes' if lang=='es' else 'packages'
        selected=page.locator('input[name="tour-types"][data-label="'+target+'"]').first.is_checked()
        checked(lang+'_jsf_interactive_filter',selected and '/jsf/' in page.url and 'tour-types:'+slug in page.url and after<initial and after>0 and all(x==200 for x in ajax),
                {'initial_main_archive_items':initial,'filtered_main_archive_items':after,
                 'main_grid_loading':'jet-filters-loading' in (main.get_attribute('class') or ''),
                 'url_path':page.url.split('tours.dev.mad4b.com')[-1],
                 'ajax_statuses':ajax})
        checked(lang+'_filter_no_supplier_write_requests',not attempted,{'attempted':len(attempted)})
    finally:page.close()

def booking(browser,lang,path):
    page=browser.new_page(viewport={'width':1440,'height':900})
    financial=[];page_errors=[]
    page.on('request',lambda r:financial.append(r.url.split('?')[0]) if r.method=='POST' and ('checkout' in r.url or 'order' in r.url or 'wc-ajax' in r.url or 'supplier' in r.url) else None)
    page.on('pageerror',lambda e:page_errors.append(str(e)[:95]))
    try:
        r=page.goto(BASE+path,wait_until='domcontentloaded',timeout=44000)
        page.wait_for_timeout(500)
        form=page.locator('form#fluentform_6').first
        if lang=='es':
            page.evaluate("()=>document.querySelector('form#fluentform_6')?.scrollIntoView({block:'center',behavior:'instant'})")
            page.wait_for_timeout(1150)
        checked(lang+'_form6_visible_after_natural_scroll',r.status==200 and form.is_visible())
        if not form.is_visible():return
        adult=form.locator('input[name="no_of_adults"]')
        start=adult.input_value()
        plus=adult.locator('xpath=..').locator('button.ff-stepper-btn.plus')
        minus=adult.locator('xpath=..').locator('button.ff-stepper-btn.minus')
        if plus.is_visible() and minus.is_visible():
            plus.click(timeout=9000)
            after_plus=adult.input_value()
            minus.click(timeout=9000)
            after_minus=adult.input_value()
            checked(lang+'_booking_traveler_stepper',start=='2' and after_plus=='3' and after_minus=='2',{'before':start,'plus':after_plus,'minus':after_minus})
        else:checked(lang+'_booking_traveler_stepper',False)
        checked(lang+'_booking_without_submit_or_financial_mutation',not financial,{'financial_POST_requests':len(financial),'page_js_errors':page_errors[:3]})
    finally:page.close()

with sync_playwright() as p:
    browser=p.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage','--disable-gpu'])
    try:
        archive(browser,'es','Paquetes')
        archive(browser,'en','Packages')
        booking(browser,'en','/tours-and-activities/giza-pyramids-half-day-tour/')
        booking(browser,'es','/es/tours-and-activities/tour-piramides-de-dahshur/')
    finally:
        browser.close()
REPORT['passed']=sum(1 for v in REPORT['checks'] if v['pass'])
REPORT['total']=len(REPORT['checks'])
REPORT['status']='PASS' if REPORT['passed']==REPORT['total'] else 'FAIL'
open('/tmp/mad4b-pr750-browser-acceptance-20261103.json','w').write(json.dumps(REPORT,ensure_ascii=False,indent=2)+'\n')
print(json.dumps({'status':REPORT['status'],'passed':REPORT['passed'],'total':REPORT['total'],'results':REPORT['checks']},ensure_ascii=False))
sys.exit(0 if REPORT['status']=='PASS' else 1)
