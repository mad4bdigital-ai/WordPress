#!/usr/bin/env python3
"""Read-only live Journey contract: real taxonomy evidence + compact Kit controls.

Examples:
 python scripts/qa/tour-journey-compact-links-browser.py \
   --url https://wayfatours.com/tours-and-activities/giza-pyramids-half-day-tour/ \
   --width 390 --expected 2517,2518 --forbidden 2522
"""
import argparse
import json
import sys
from playwright.sync_api import sync_playwright


def main():
    parser=argparse.ArgumentParser()
    parser.add_argument('--url',required=True)
    parser.add_argument('--width',type=int,default=390)
    parser.add_argument('--expected',default='')
    parser.add_argument('--forbidden',default='')
    parser.add_argument('--disable-quic',action='store_true')
    args=parser.parse_args()
    if not args.url.startswith('https://') or args.width<320:
        raise SystemExit('HTTPS and viewport >=320 required')
    expected={int(v) for v in args.expected.split(',') if v.strip()}
    forbidden={int(v) for v in args.forbidden.split(',') if v.strip()}
    result={'url':args.url,'viewport':args.width,'ok':False,'modes':[]}
    with sync_playwright() as p:
        browser=p.chromium.launch(headless=True,args=['--no-sandbox']+
            (['--disable-quic'] if args.disable_quic else []))
        page=browser.new_page(viewport={'width':args.width,'height':900})
        errors=[]
        page.on('pageerror',lambda e:errors.append(str(e)))
        try:
            response=page.goto(args.url,wait_until='domcontentloaded',timeout=20000)
            assert response and response.status==200,'HTTP failure'
            page.wait_for_function(
                "() => document.querySelector('.tour-journey-discovery')?.dataset.jdSplitReady==='1'",
                timeout=8000)
            for mode in ['explorer','story','split']:
                # Select through the original Elementor UI change listener.
                # Direct DOM selection avoids forced scrolling into sticky headers.
                page.evaluate("""mode=>{
                    const control=document.querySelector('.tour-journey-layout-select');
                    if(!control)throw Error('native Journey selector missing');
                    control.value=mode;
                    control.dispatchEvent(new Event('change',{bubbles:true}));
                }""",mode)
                page.wait_for_timeout(130)
                state=page.evaluate("""()=>{
                    const s=document.querySelector('.tour-journey-discovery');
                    const terms=JSON.parse(s.dataset.tourDestinations||'[]');
                    const first=s.querySelector('.tour-hl-ui__card');
                    const anchors=[...first.querySelectorAll('.jd-place-tile__details[href]')];
                    const ids=anchors.map(link=>{
                        const path=new URL(link.href).pathname.replace(/\\/+$/,'/');
                        return terms.find(t=>{
                            return new URL(t.url,document.baseURI).pathname.replace(/\\/+$/,'/')===path;
                        })?.id||null;
                    });
                    const size=el=>{
                        const s=getComputedStyle(el),r=el.getBoundingClientRect();
                        return {text:el.textContent.trim().slice(0,28),
                          width:Math.round(r.width),height:Math.round(r.height),
                          font:parseFloat(s.fontSize)};
                    };
                    return {mode:s.dataset.tourJourneyMode,
                      termIds:ids,linkedNames:[...first.querySelectorAll('.jd-place-tile__name')].map(n=>n.textContent.trim()),
                      crumbs:[...s.querySelectorAll('.jd-split-v3__crumb')].map(size),
                      levels:[...s.querySelectorAll('.jd-split-v3__level')].map(size),
                      storyPointers:[...s.querySelectorAll('.jd-modes-v3__story-pointer:not([hidden])')].length,
                      highlightCount:s.querySelectorAll('.tour-hl-ui__card').length,
                      overflow:document.documentElement.scrollWidth>innerWidth,
                      nativeGroupCount:s.querySelectorAll('.tour-place-group').length};
                }""")
                assert state['mode']==mode, 'mode state mismatch'
                assert state['nativeGroupCount']>=1,'lost JetEngine groups'
                assert len(state['termIds'])==len(set(state['termIds'])),'duplicate verified term in Highlight'
                assert None not in state['termIds'],'unresolved link outside approved taxonomy'
                assert expected.issubset(set(state['termIds'])), 'missing verified links'
                assert not forbidden.intersection(state['termIds']),'unsupported visit evidence'
                assert not state['overflow'],'horizontal page overflow'
                assert all(e['font']<=12.5 and e['height']<=36
                           for e in state['crumbs']),'oversized taxonomy breadcrumbs'
                assert all(e['font']<=12.5 and e['height']<=40
                           for e in state['levels']),'oversized taxonomy tabs'
                if mode=='story':
                    assert state['storyPointers']==state['highlightCount'],'Story pointer mismatch'
                result['modes'].append(state)
            assert not errors,'JS errors: '+str(errors[:3])
            result.update(ok=True,http=response.status,errors=[])
        except Exception as exc:
            result.update(error=str(exc)[:500],errors=errors[:3])
        finally:
            page.close()
            browser.close()
    print(json.dumps(result,ensure_ascii=False))
    return 0 if result['ok'] else 3


if __name__=='__main__':
    sys.exit(main())
