#!/usr/bin/env python3
"""Read-only acceptance for native multi-type Destination Elementor templates.

Examples:
 python scripts/qa/destination-native-template-browser.py --url https://.../giza/ --expect rich --width 390
 python scripts/qa/destination-native-template-browser.py --url https://.../great-sphinx/ --expect basic
"""
import argparse
import json
from playwright.sync_api import sync_playwright


def main():
    p=argparse.ArgumentParser()
    p.add_argument('--url',required=True)
    p.add_argument('--expect',choices=['rich','basic'],required=True)
    p.add_argument('--width',type=int,default=390)
    p.add_argument('--disable-quic',action='store_true')
    args=p.parse_args()
    if not args.url.startswith('https://') or args.width<320:
        raise SystemExit('HTTPS and width >=320 required')
    result={'url':args.url,'expected':args.expect,'width':args.width,'ok':False}
    with sync_playwright() as playwright:
        browser=playwright.chromium.launch(headless=True,args=['--no-sandbox']+
            (['--disable-quic'] if args.disable_quic else []))
        page=browser.new_page(viewport={'width':args.width,'height':860})
        errors=[]
        page.on('pageerror',lambda error:errors.append(str(error)))
        try:
            response=page.goto(args.url,wait_until='domcontentloaded',timeout=22000)
            assert response and response.status==200,'unavailable Destination page'
            data=page.evaluate("""() => {
               const section=document.querySelector('.destination-native-insights');
               const visible=!!section&&getComputedStyle(section).display!=='none'
                 &&section.getBoundingClientRect().height>3;
               const map=document.querySelector('.elementor-widget-google_maps');
               return {
                 language:document.documentElement.lang,
                 title:document.querySelector('h1')?.textContent?.trim(),
                 nativeSection:visible,
                 accordionCount:section?.querySelectorAll('details.e-n-accordion-item').length||0,
                 accordionClosed:[...(section?.querySelectorAll('details.e-n-accordion-item')||[])]
                   .every(n=>!n.open),
                 nativeRepeaterRows:section?.querySelectorAll('.jet-listing-dynamic-repeater__items li').length||0,
                 map:!!map,
                 mapLazy:!!map?.querySelector('iframe[loading="lazy"]'),
                 overflow:document.documentElement.scrollWidth>innerWidth
               };
            }""")
            assert data['title'],'missing native Destination title'
            assert not data['overflow'],'horizontal page overflow'
            if args.expect=='rich':
                branding=page.evaluate("""() => {
                    const root=document.querySelector('.destination-native-insights');
                    const nodes=[...root.querySelectorAll('.destination-native-native-accordion summary')];
                    const highlights=root.querySelector('.destination-native-highlights');
                    const shape=e=>{const s=getComputedStyle(e);return {
                        border:s.borderTopColor,width:s.borderTopWidth,radius:s.borderTopLeftRadius};};
                    const kit=getComputedStyle(document.body);
                    return {titles:nodes.map(shape),highlight:shape(highlights),
                        borderRole:kit.getPropertyValue('--e-global-color-62e8100').trim(),
                        secondaryRole:kit.getPropertyValue('--e-global-color-secondary').trim()};
                }""")
                assert len(branding['titles'])==3,'native accordion borders absent'
                assert branding['borderRole'] and branding['secondaryRole'],'brand kit not resolving'
                assert all(s['border']==branding['highlight']['border'] and
                           s['width']=='1px' and s['radius']=='10px'
                           for s in branding['titles']),'accordion borders differ from brand cards'
                assert branding['highlight']['width']=='1px' and branding['highlight']['radius']=='10px'
                result['kitBorders']=branding
                assert data['nativeSection'],'Destination rich fields are not rendered'
                assert data['accordionCount']==3,'expected native Elementor nested accordions'
                assert data['accordionClosed'],'accordion is expanded before interaction'
                first=page.locator('.destination-native-insights summary').first
                first.scroll_into_view_if_needed(timeout=6000)
                first.click()
                page.wait_for_timeout(130)
                assert page.locator('.destination-native-insights details.e-n-accordion-item').first.evaluate(
                    '(node)=>node.open'),'accordion activation failed'
                open_style=page.evaluate("""() => {
                    const title=document.querySelector('.destination-native-why-visit summary');
                    const panel=document.querySelector('.destination-native-why-visit .e-n-accordion-item>.e-con');
                    if(!title||!panel)return null;
                    return {border:getComputedStyle(title).borderTopColor,
                            panelRadius:getComputedStyle(panel).borderBottomRightRadius};
                }""")
                assert open_style and open_style['panelRadius']=='10px','accordion open panel radius differs'
                result['openBorder']=open_style
            else:
                assert not data['nativeSection'],'empty enrichment shown on basic Destination'
            assert not errors, 'page JavaScript error: '+str(errors[:2])
            result.update({'ok':True,'http':response.status,'data':data,'errors':[]})
        except Exception as exc:
            result.update({'error':str(exc)[:400],'errors':errors[:2]})
        finally:
            page.close()
            browser.close()
    print(json.dumps(result,ensure_ascii=False))
    return 0 if result['ok'] else 3


if __name__=='__main__':
    raise SystemExit(main())
