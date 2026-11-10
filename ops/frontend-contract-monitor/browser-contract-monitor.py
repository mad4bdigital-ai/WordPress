#!/usr/bin/env python3
"""Brand-neutral browser contracts; evidence goes to company Configuration Jobs.
Public pages only, exact owner/tenant validation, no end-user traffic hooks.
"""
import fcntl,hashlib,json,os,sys,time,subprocess
from pathlib import Path
from urllib.parse import urlsplit
from playwright.sync_api import sync_playwright
CONFIG=Path('/opt/mad4b-company-provisioning/releases/configuration-jobs-v1/frontend-contract-sites.json')
ROOT=Path('/opt/mad4b-company-provisioning/reports/frontend-contract-monitor')
CONTRACT='mad4b.frontend-contract-evidence.v1'
CHECKS=['wpml_active_language','wpml_translation_navigation','jetengine_nested_tab_state','jetengine_ajax_response','navigation_menu_language_order']

def examine(page,site,lang,target):
    expected=site['languages'][lang]
    url=site['origin']+expected['path']
    loaded=page.goto(url,wait_until='domcontentloaded',timeout=55000)
    page.wait_for_timeout(250)
    widget=page.locator(site['switcher'])
    lang_attr=page.locator('html').get_attribute('lang') or ''
    selected=widget.locator('a[aria-current="page"][hreflang]').first
    selected_lang=selected.get_attribute('hreflang') if selected.count() else None
    summary=widget.locator('summary,.wayfa-mobile-lang-trigger').first
    label=summary.inner_text() if summary.count() else ''
    target_link=widget.locator('a[hreflang="'+target+'"]').last
    href=target_link.get_attribute('href') if target_link.count() else None
    active_ok=(loaded is not None and loaded.status==200 and lang_attr.lower().startswith(lang) and selected_lang==lang
       and expected['label'].casefold() in label.casefold() and href and urlsplit(href).netloc==urlsplit(site['origin']).netloc)
    return dict(ok=bool(active_ok),url=page.url,lang=lang_attr,selected=selected_lang,
        target_href=href,display_matches=expected['label'].casefold() in label.casefold(),target_present=bool(href))

def run(config):
    statuses={k:'incomplete' for k in CHECKS}
    evidence={'contract':CONTRACT,'site_uuid':config['site_uuid'],'origin':config['origin'],
              'checked_epoch':int(time.time()),'checks':{},'tests':[]}
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True,executable_path=config['chromium'],args=['--no-sandbox','--disable-dev-shm-usage'])
        try:
            observations=[];transitions=[];menu_observations=[]
            for case in config['cases']:
                lang=case['language'];target=case['target_language']
                ctx=browser.new_context(viewport={'width':427,'height':952},device_scale_factor=1,is_mobile=True,has_touch=True)
                page=ctx.new_page();errors=[]
                page.on('pageerror',lambda e:errors.append(str(e)[:75]))
                try:
                    url=config['origin']+case['path']
                    response=page.goto(url,wait_until='domcontentloaded',timeout=50000)
                    page.wait_for_timeout(300)
                    sw=page.locator(config['switcher'])
                    active=sw.locator('a[aria-current="page"][hreflang]').first
                    expected=config['languages'][lang]['label']
                    label_el=sw.locator('summary,.wayfa-mobile-lang-trigger').first
                    label=label_el.inner_text() if label_el.count() else ''
                    current=active.get_attribute('hreflang') if active.count() else ''
                    html_lang=page.locator('html').get_attribute('lang') or ''
                    a=sw.locator('a[hreflang="'+target+'"]').last
                    href=a.get_attribute('href') if a.count() else ''
                    ok=(response is not None and response.status==200 and current==lang
                        and html_lang.lower().startswith(lang) and expected.casefold() in label.casefold()
                        and urlsplit(href).netloc==urlsplit(config['origin']).netloc and not errors)
                    observations.append(ok)
                    # Verify the actual native Elementor header items, not WP
                    # nav-menu database order alone. Elementor may serve a stale
                    # serialized document cache after a WPML menu update.
                    if case['name'] in ('homepage_en','homepage_es'):
                        actual=page.evaluate('''() => [...document.querySelectorAll('header .e-n-menu-heading')].slice(0,1).flatMap(m => [...m.querySelectorAll('.e-n-menu-title')].map(n => n.querySelector('a[href]')?.href || ''))''')
                        normalize=lambda href:(urlsplit(href).path.rstrip('/') or '/')
                        parsed=[normalize(u) for u in actual if u]
                        expected_order=[normalize(config['origin']+u) for u in config['navigation']['expected_paths'][lang]]
                        passed=parsed==expected_order and len(parsed)==len(expected_order)
                        menu_observations.append(passed)
                        evidence['tests'].append({'case':'menu_order_'+lang,'pass':passed,'observed_paths':parsed,'expected_paths':expected_order})
                    item={'case':case['name'],'language':lang,'http':response.status if response else None,
                        'active_matches':bool(ok),'link_same_origin':bool(href and urlsplit(href).netloc==urlsplit(config['origin']).netloc),
                        'javascript_error_count':len(errors)}
                    if case.get('click') and href and label_el.count():
                        label_el.click(timeout=4500)
                        a.click(timeout=6500)
                        page.wait_for_timeout(450)
                        final_lang=page.locator('html').get_attribute('lang') or ''
                        url_after=page.url
                        moved=(final_lang.lower().startswith(target)
                           and url_after.rstrip('/')==href.rstrip('/')
                           and urlsplit(url_after).netloc==urlsplit(config['origin']).netloc)
                        transitions.append(moved)
                        item['navigation_pass']=moved
                    evidence['tests'].append(item)
                except Exception as e:
                    observations.append(False)
                    if case.get('click'):transitions.append(False)
                    evidence['tests'].append({'case':case['name'],'error_type':type(e).__name__,'status':'fail'})
                finally:ctx.close()
            statuses['wpml_active_language']='pass' if observations and all(observations) else 'fail'
            statuses['wpml_translation_navigation']='pass' if transitions and all(transitions) else 'fail'
            statuses['navigation_menu_language_order']='pass' if len(menu_observations)==2 and all(menu_observations) else 'fail'
            ctx=browser.new_context(viewport={'width':1440,'height':900});page=ctx.new_page()
            ajax=[];errors=[]
            page.on('pageerror',lambda e:errors.append(str(e)[:100]))
            page.on('response',lambda r:ajax.append(r.status) if r.request.method=='POST' and 'nocache=1' in r.url else None)
            try:
                r=page.goto(config['origin']+config['navigation']['path'],wait_until='domcontentloaded',timeout=50000)
                page.locator(config['navigation']['menu']).first.hover(timeout=9000)
                tab=page.locator(config['navigation']['tab']).filter(has_text=config['navigation']['tab_text']).first
                tab.click(timeout=6500)
                page.wait_for_timeout(6500)
                state=page.evaluate('''({selector,other})=>{const a=[...document.querySelectorAll(selector)];const pick=s=>a.find(n=>n.textContent.trim()===s);return {selected:pick(other.selected)?.getAttribute('aria-selected'),previous:pick(other.previous)?.getAttribute('aria-selected'),completed:document.querySelectorAll('header .native-destination-deep-tabs .jet-listing-grid--lazy-load-completed').length,diagnostics:window.MAD4BNavigationLazyDiagnostics?.()}}''',{'selector':config['navigation']['dom_tab_selector'],'other':{'selected':config['navigation']['tab_text'],'previous':config['navigation']['previous_tab_text']}})
                statuses['jetengine_nested_tab_state']='pass' if (r.status==200 and state['selected']=='true' and state['previous']=='false' and state['completed']>=1 and not errors) else 'fail'
                statuses['jetengine_ajax_response']='pass' if (ajax and all(x==200 for x in ajax) and state['completed']>=1) else 'fail'
                evidence['tests'].append({'case':'nested_navigation','http':r.status,'selection_pass':statuses['jetengine_nested_tab_state']=='pass',
                     'ajax_count':len(ajax),'non_200_ajax_count':sum(x!=200 for x in ajax),'completed_count':state['completed'],'javascript_error_count':len(errors)})
            except Exception as e:
                statuses['jetengine_nested_tab_state']='fail';statuses['jetengine_ajax_response']='fail'
                evidence['tests'].append({'case':'nested_navigation','error_type':type(e).__name__,'status':'fail'})
            finally:ctx.close()
        finally:browser.close()
    evidence['checks']=statuses
    return evidence

def main():
    config=json.loads(CONFIG.read_text())
    if(len(sys.argv)!=2 or sys.argv[1] not in config['sites']):raise SystemExit('Usage: monitor SITE_ID')
    site=config['sites'][sys.argv[1]]
    assert site['origin'].startswith('https://') and urlsplit(site['origin']).path in ('','/')
    assert len(site['site_uuid'])==36 and site['container'].startswith('php-')
    ROOT.mkdir(mode=0o700,parents=True,exist_ok=True)
    with open(ROOT/(sys.argv[1]+'.lock'),'a+') as lock:
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
        start=int(time.time())
        try:evidence=run(site)
        except Exception as e:
            evidence={'contract':CONTRACT,'site_uuid':site['site_uuid'],'origin':site['origin'],'checked_epoch':int(time.time()),
                'checks':{k:'incomplete' for k in CHECKS},'tests':[{'error_type':type(e).__name__,'phase':'monitor-infrastructure'}]}
        evidence['checked_epoch']=int(time.time())
        evidence_path=ROOT/(sys.argv[1]+'-'+str(evidence['checked_epoch'])+'.json')
        raw=json.dumps(evidence,ensure_ascii=False,sort_keys=True,indent=2).encode()
        fd=os.open(evidence_path,os.O_CREAT|os.O_EXCL|os.O_WRONLY,0o600)
        with os.fdopen(fd,'wb') as f:f.write(raw)
        payload={k:evidence[k] for k in ['contract','site_uuid','checked_epoch','checks']}
        payload['evidence_sha256']=hashlib.sha256(raw).hexdigest()
        result=subprocess.run(['docker','exec','-i','-u','www-data',site['container'],'wp','--path=/var/www/html','mad4b','frontend-contract','ingest'],
           input=json.dumps(payload),text=True,capture_output=True,timeout=100)
        if result.returncode:raise RuntimeError('WORDPRESS_EVIDENCE_IMPORT_FAILED:'+result.stderr[-140:])
        scan=subprocess.run(['docker','exec','-u','www-data',site['container'],'wp','--path=/var/www/html','eval','echo wp_json_encode(MAD4B_Configuration_Jobs::scan());'],
            capture_output=True,text=True,timeout=110)
        if scan.returncode:raise RuntimeError('CONFIGURATION_ALERT_SCAN_FAILED:'+scan.stderr[-140:])
        print(json.dumps({'site':sys.argv[1],'checks':evidence['checks'],'evidence_sha256':payload['evidence_sha256'],
            'import':result.stdout.strip()[-220:],'configuration_scan':scan.stdout.strip()[-500:],'elapsed_seconds':int(time.time())-start}))

if __name__=='__main__':main()
