#!/usr/bin/env python3
"""Public UI acceptance: Elementor hover cards never obscure sticky filter sidebar.

Run: python3 tests/archive-filter-sidebar-hover-acceptance.py en list filtered
Also supports ar/en, list/grid, base/filtered. Does not write WordPress content.
"""
from playwright.sync_api import sync_playwright
import json, sys

language = sys.argv[1] if len(sys.argv) > 1 else 'en'
view = sys.argv[2] if len(sys.argv) > 2 else 'list'
scope = sys.argv[3] if len(sys.argv) > 3 else 'base'
assert language in ('en','ar') and view in ('list','grid') and scope in ('base','filtered')
lang_path = {'en':'','ar':'ar/'}[language]
route = ('jsf/jet-engine:tours_query_archive/tax/tour-styles:' +
         {'en':'city-tours,cultural-tours','ar':'city-tours-ar,cultural-tours-ar'}[language] +
         ',operator_AND/') if scope == 'filtered' else ''
url = f'https://tours.dev.mad4b.com/{lang_path}tours-and-activities/{route}'
result={'language':language,'view':view,'scope':scope,'url':url,'jsErrors':[]}

with sync_playwright() as pw:
    browser = pw.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
    page = browser.new_page(viewport={'width':1440,'height':900})
    page.on('pageerror',lambda error:result['jsErrors'].append(str(error)[:220]))
    try:
        response = page.goto(url, wait_until='domcontentloaded', timeout=38000)
        result['http']=response.status
        page.locator('.je-layout-switcher__btn[data-slug="grid"]').first.wait_for(state='visible',timeout=18000)
        if scope=='filtered':
            page.wait_for_function(
                """()=>window.JetSmartFilters?.filterGroups?.['jet-engine/tours_query_archive']?.currentQuery?.['_tax_query_tour-styles']?.includes('operator_AND')""",
                timeout=20000,
            )
        if view == 'grid':
            page.locator('.je-layout-switcher__btn[data-slug="grid"]').first.click(timeout=9000)
            page.wait_for_function(
                """()=>{const w=document.querySelector('.je-layout-switcher');return w?.querySelector('.je-layout-switcher__btn--active')?.dataset.slug==='grid'&&!w.classList.contains('je-layout-switcher--loading')}""",
                timeout=28000,
            )
        page.evaluate('window.scrollTo({top:2200,behavior:"instant"})')
        page.wait_for_timeout(750)
        state = r"""() => {
          const side=document.querySelector('.elementor-element-702cfb6c.elementor-sticky--active'),
            switcher=document.querySelector('.je-layout-switcher'),
            listing=document.querySelector('.elementor-element-'+switcher.dataset.widgetId+' .jet-listing-grid__items'),
            input=side?.querySelector('.jet-filter-items-search__input');
          let bounds=input?.getBoundingClientRect(),
            hit=bounds?document.elementFromPoint(bounds.x+bounds.width/2,bounds.y+bounds.height/2):null,
            s=side?getComputedStyle(side):null;
          return {
            sidePresent:!!side,sidePosition:s?.position,sideZ:s?.zIndex,
            sideRect:side?[side.getBoundingClientRect().x,side.getBoundingClientRect().y,side.getBoundingClientRect().width]:null,
            contentIsolation:listing?getComputedStyle(listing).isolation:null,
            contentOverflow:listing?getComputedStyle(listing).overflow:null,
            inputHittable:!!input&&input.contains(hit),
            inputCoords:bounds?[bounds.x+bounds.width/2,bounds.y+bounds.height/2]:null,
            activeView:switcher.querySelector('.je-layout-switcher__btn--active')?.dataset.slug,
            rtl:document.documentElement.dir==='rtl',
            query:window.JetSmartFilters?.filterGroups?.['jet-engine/tours_query_archive']?.currentQuery?.['_tax_query_tour-styles']||null
          };
        }"""
        result['before']=page.evaluate(state)
        # Pointer hover at a real visible listing card, not synthetic click dispatch.
        page.mouse.move(700,250,steps=10)
        page.wait_for_timeout(480)
        result['hover']=page.evaluate(r"""() => {
          const card=document.elementFromPoint(700,250)?.closest('.jet-listing-grid__item'),
            root=card?.querySelector(':scope > .elementor .e-con'),
            s=root?getComputedStyle(root):null,
            side=document.querySelector('.elementor-element-702cfb6c.elementor-sticky--active'),
            inp=side?.querySelector('.jet-filter-items-search__input'),
            bounds=inp?.getBoundingClientRect(),
            top=bounds?document.elementFromPoint(bounds.x+bounds.width/2,bounds.y+bounds.height/2):null;
          return {
            cardHit:!!card, cardHovered:!!card?.matches(':hover'),
            cardTransform:s?.transform,
            sidebarStillSticky:side?getComputedStyle(side).position==='fixed':false,
            sidebarInputStillHittable:!!inp&&inp.contains(top)
          };
        }""")
        if result['before']['inputHittable']:
            coords=result['before']['inputCoords']
            page.mouse.click(*coords)
            result['inputFocused']=page.evaluate("""()=>document.activeElement?.matches('.elementor-element-702cfb6c .jet-filter-items-search__input')===true""")
        transform=result.get('hover',{}).get('cardTransform') or ''
        result['pass']=(
            response.status==200 and not result['jsErrors']
            and result['before']['sidePresent'] and result['before']['sidePosition']=='fixed'
            and result['before']['sideZ']=='99'
            and result['before']['contentIsolation']=='isolate'
            and result['before']['contentOverflow']=='visible'
            and result['before']['activeView']==view
            and result['before']['rtl']==(language=='ar')
            and result['hover']['cardHit'] and result['hover']['cardHovered']
            and 'matrix(1.0' in transform
            and result['hover']['sidebarStillSticky']
            and result['hover']['sidebarInputStillHittable']
            and result.get('inputFocused') is True
            and (scope!='filtered' or 'operator_AND' in (result['before']['query'] or []))
        )
    except Exception as e:
        result['failure']=str(e)[:350]
        result['pass']=False
    browser.close()
print(json.dumps(result,ensure_ascii=False))
sys.exit(0 if result['pass'] else 1)
