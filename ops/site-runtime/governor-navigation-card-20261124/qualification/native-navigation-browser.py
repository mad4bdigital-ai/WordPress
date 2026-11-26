"""Read-only browser baseline of the original public navigation."""
import argparse,json
from playwright.sync_api import sync_playwright
p=argparse.ArgumentParser();p.add_argument('url');p.add_argument('--width',type=int,default=390);args=p.parse_args()
mobile=args.width<768
errors=[];assets=[]
with sync_playwright() as pw:
    browser=pw.chromium.launch(headless=True,args=['--no-sandbox','--disable-dev-shm-usage'])
    ctx=browser.new_context(viewport={'width':args.width,'height':844},is_mobile=mobile,has_touch=mobile,
        user_agent='Mozilla/5.0 (Linux; Android 14; Pixel 7) AppleWebKit/537.36 Chrome/157.0.0.0 Mobile Safari/537.36' if mobile else 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 Chrome/157.0.0.0 Safari/537.36',
        extra_http_headers={'X-MAD4B-BYPASS-CACHE':'1','X-MAD4B-NAV-CONTINUITY':'observe-v2'})
    page=ctx.new_page();page.set_default_timeout(3500)
    page.on('pageerror',lambda e:errors.append(str(e)[:160]))
    page.on('response',lambda r:assets.append(r.status) if r.status>=400 and '/wp-content/' in r.url else None)
    out={'contract':'mad4b.navigation.native-browser-baseline.v1','width':args.width,'cached_comparison':False,'market_attested':False,'replay_authorized':False}
    try:
        response=page.goto(args.url,wait_until='domcontentloaded',timeout=22000)
        out['http']=response.status
        menu=page.locator('[data-id="6072b3f"]')
        toggle=menu.locator('.e-n-menu-toggle').first
        if toggle.is_visible() and toggle.get_attribute('aria-expanded')!='true':
            toggle.click(timeout=3500)
        out['mobile_menu_expanded']=toggle.get_attribute('aria-expanded') if mobile else None
        dropdown=menu.locator('.e-n-menu-dropdown-icon').first
        dropdown.click(timeout=3500)
        page.wait_for_timeout(450)
        out['destination_expanded']=dropdown.get_attribute('aria-expanded')=='true'
        out['cards']=menu.locator('[data-elementor-id="67125"]').count()
        out['visible_cards']=menu.locator('[data-elementor-id="67125"]:visible').count()
        out['visible_card_links']=menu.locator('.navigation-editor-card:visible a[href],a.navigation-editor-card:visible').count()
        out['form6_present']=page.locator('#fluentform_6').count()==1
        out['price_present']=page.locator('.etg-tour-price,.etg-price-native').count()>0
        out['native_baseline_pass']=out['http']==200 and out['destination_expanded'] and out['cards']>0 and out['visible_cards']>0 and out['form6_present'] and out['price_present'] and not errors and not assets
    except Exception as e:
        out['error']=str(e)[:220];out['native_baseline_pass']=False
    finally:
        out['page_errors']=errors;out['asset_error_statuses']=assets
        print(json.dumps(out),flush=True)
        ctx.close();browser.close()
