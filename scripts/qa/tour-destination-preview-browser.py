#!/usr/bin/env python3
"""Read-only browser regression for the native Elementor destination preview.

Examples:
  python scripts/qa/tour-destination-preview-browser.py \
    --url https://wayfatours.com/tours-and-activities/a-tour/ --width 390 --modes all
  python scripts/qa/tour-destination-preview-browser.py \
    --url https://tours.dev.mad4b.com/fr/tours-and-activities/un-voyage/ --width 390
"""
import argparse
import json
import sys
from playwright.sync_api import sync_playwright


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--url',required=True)
    parser.add_argument('--width',type=int,default=390)
    parser.add_argument('--modes',choices=['all','explorer','split','story'],default='split')
    parser.add_argument('--disable-quic',action='store_true')
    parser.add_argument('--split-level',default=None,help='Optional native destination_type level for Split testing')
    args=parser.parse_args()
    if not args.url.startswith('https://') or args.width<300:
        print(json.dumps({'ok':False,'error':'HTTPS and a viewport >=300px required'}))
        return 2
    output={'ok':False,'url':args.url,'width':args.width,'cases':[]}
    with sync_playwright() as pw:
        browser=pw.chromium.launch(headless=True,args=['--no-sandbox']+
            (['--disable-quic'] if args.disable_quic else []))
        page=browser.new_page(viewport={'width':args.width,'height':850})
        errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        try:
            response=page.goto(args.url,wait_until='domcontentloaded',timeout=22500)
            assert response is not None and response.status==200, 'bad HTTP status'
            page.evaluate("""() => document.querySelector('.tour-journey-discovery')
                ?.scrollIntoView({block:'center',behavior:'instant'})""")
            page.wait_for_function("""() => {
                const section=document.querySelector('.tour-journey-discovery');
                return section && section.dataset.tourJourneyInitialized==='2'
                    && getComputedStyle(section).visibility==='visible';
            }""",timeout=10000)
            select=page.locator('.tour-journey-layout-select')
            assert select.count()==1, 'missing native Elementor display selector'
            modes=['explorer','split','story'] if args.modes=='all' else [args.modes]
            for mode in modes:
                select.select_option(mode)
                page.wait_for_timeout(110)
                if mode=='split' and args.split_level:
                    level=page.locator('.jd-split-v3__level[data-kind="'+args.split_level+'"]')
                    assert level.count()==1,'requested Split taxonomy level not available'
                    level.click()
                    page.wait_for_timeout(85)
                cards=page.locator('.tour-journey-discovery .tour-place-group'
                    '[data-tour-active=true] .jd-native-place:not([hidden]) a[href]')
                if cards.count()==0:
                    cards=page.locator('.tour-journey-discovery .jd-place-tile__details')
                assert cards.count()>0, 'no approved JetEngine destination in '+mode
                original=page.url
                native_href=cards.first.get_attribute('href')
                cards.first.click(timeout=8000)
                page.wait_for_function("""() => {
                    const dialog=document.querySelector('dialog.mad4b-destination-quick-preview-dialog');
                    return dialog && dialog.open && dialog.dataset.status==='ready';
                }""",timeout=22000)
                state=page.evaluate("""() => {
                    const d=document.querySelector('dialog.mad4b-destination-quick-preview-dialog');
                    const f=d.querySelector('iframe'), doc=f.contentDocument;
                    // The ABOUT widget, Dynamic Field and native Insights must
                    // remain in their original Elementor document tree.
                    const about=[...doc.querySelectorAll('[data-widget_type="jet-listing-dynamic-field.default"]')]
                      .find(el=>!el.closest('.destination-native-insights,.jet-listing-grid__item')
                        && el.getBoundingClientRect().height>0 && el.textContent.trim().length>65);
                    const originalModule=about?.closest('.elementor[data-elementor-id]');
                    const title=about?.parentElement?.querySelector('[data-widget_type="heading.default"]');
                    const insights=originalModule?.querySelector('.destination-native-insights');
                    const section=document.querySelector('.tour-journey-discovery');
                    const full=d.querySelector('.mad4b-destination-quick-preview-full');
                    return {
                        locale:doc.documentElement.lang,
                        loaded:doc.readyState,
                        previewModules:d.dataset.previewModules,
                        hero:doc.querySelector('h1')?.textContent?.trim(),
                        aboutTitle:title?.textContent?.trim(),
                        aboutTextLength:about?.textContent?.trim()?.length||0,
                        headerHidden:!doc.querySelector('.elementor-location-header')
                          ||getComputedStyle(doc.querySelector('.elementor-location-header')).display==='none',
                        cookieHidden:!doc.querySelector('#mad4b-consent-root')
                          ||getComputedStyle(doc.querySelector('#mad4b-consent-root')).display==='none',
                        visibleForms:[...doc.querySelectorAll('form')].filter(x=>
                          getComputedStyle(x).display!=='none').length,
                        inheritedChildModal:!!f.contentWindow.MAD4BDestinationPreviewV1,
                        href:full?.href,
                        framePath:new URL(f.src).pathname,
                        originalMode:section.dataset.tourJourneyMode,
                        nativeInsights:!!insights,
                        originalModulePreserved:!!originalModule,
                        nativeInsightSameModule:!!insights && insights.closest('.elementor[data-elementor-id]')===originalModule,
                        noClonedAbout:!doc.querySelector('.mad4b-destination-preview-about'),
                        nativeAccordions:insights?.querySelectorAll('.e-n-accordion-item').length||0,
                        nativeHighlightRows:insights?.querySelectorAll('.jet-listing-dynamic-repeater__items li').length||0,
                        kitBorder:(()=>{const t=insights?.querySelector('summary');if(!t)return null;
                          const css=doc.defaultView.getComputedStyle(t);
                          return {width:css.borderTopWidth,radius:css.borderTopLeftRadius,color:css.borderTopColor};
                        })(),
                        frameOverflow:doc.documentElement.scrollWidth>f.clientWidth+1,
                        overflow:document.documentElement.scrollWidth>innerWidth
                    };
                }""")
                assert page.url==original,'Tour navigated on preview click'
                assert state['originalMode']==mode,'Tour mode changed'
                assert state['previewModules'] in ['hero-about','hero-about-insights','hero-only'],str(state)
                assert state['hero'],'Native Elementor destination hero is missing'
                assert state['headerHidden'] and state['cookieHidden'],'duplicate site chrome'
                assert state['visibleForms']==0,'unsafe embedded form'
                assert not state['inheritedChildModal'],'nested modal inside destination iframe'
                assert state['noClonedAbout'],'old cloned About markup still active'
                assert not state['frameOverflow'],'iframe horizontal overflow'
                assert not state['overflow'],'page width overflow'
                if state['previewModules'] in ['hero-about','hero-about-insights']:
                    assert state['aboutTitle'] and state['aboutTextLength']>65,'lost JetEngine description'
                    assert state['originalModulePreserved'],'native Elementor document wrapper missing'
                if state['previewModules']=='hero-about-insights':
                    assert state['nativeInsights'],'original Elementor enrichment was not reused'
                    assert state['nativeAccordions']>=1,'native nested accordions missing'
                    assert state['nativeInsightSameModule'],'Insights removed from original Elementor CSS scope'
                    assert state['kitBorder']['width']=='1px' and state['kitBorder']['radius']=='10px', (
                        'native Kit border style was lost: '+str(state['kitBorder']))
                page.locator('.mad4b-destination-quick-preview-close').click()
                page.wait_for_timeout(75)
                assert page.locator('.mad4b-destination-quick-preview-dialog').count()==0, 'Close failed'
                output['cases'].append({'mode':mode,'nativeHref':native_href,'preview':state,'closed':True})
            assert not errors, 'JavaScript errors: '+str(errors[:3])
            output['ok']=True
            output['http']=response.status
            output['errors']=[]
            print(json.dumps(output,ensure_ascii=False))
            return 0
        except Exception as exc:
            output['error']=str(exc)[:450]
            output['errors']=errors[:3]
            print(json.dumps(output,ensure_ascii=False))
            return 3
        finally:
            page.close()
            browser.close()


if __name__=='__main__':
    sys.exit(main())
