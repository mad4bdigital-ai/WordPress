#!/usr/bin/env python3
"""Read-only browser contract for the native Journey Discovery Split panel.

Example:
  python scripts/qa/tour-journey-split-browser.py \
    --url https://example.com/tours-and-activities/a-tour/ --width 390
"""
import argparse
import json
import sys
from playwright.sync_api import sync_playwright


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--url", required=True)
    parser.add_argument("--width", type=int, default=390)
    parser.add_argument("--timeout-ms", type=int, default=22000)
    parser.add_argument("--disable-quic", action="store_true")
    args = parser.parse_args()
    if not args.url.startswith("https://") or args.width < 300:
        print(json.dumps({"ok": False, "error": "HTTPS URL and viewport >= 300px required"}))
        return 2

    with sync_playwright() as pw:
        browser = pw.chromium.launch(
            headless=True,
            args=["--no-sandbox"] + (["--disable-quic"] if args.disable_quic else []),
        )
        page = browser.new_page(viewport={"width": args.width, "height": 900})
        errors = []
        page.on("pageerror", lambda err: errors.append(str(err)))
        try:
            response = page.goto(args.url, wait_until="domcontentloaded", timeout=args.timeout_ms)
            assert response is not None and response.status == 200, "HTTP status"
            page.evaluate(
                "()=>document.querySelector('.tour-journey-discovery')"
                "?.scrollIntoView({block:'center',behavior:'instant'})"
            )
            page.wait_for_function(
                "()=>{const s=document.querySelector('.tour-journey-discovery');"
                "return s&&getComputedStyle(s).visibility==='visible'"
                "&&s.dataset.jdSplitReady==='1';}",
                timeout=8000,
            )
            select = page.locator(".tour-journey-layout-select")
            assert select.count() == 1, "Layout selector count"
            assert select.locator("option").evaluate_all(
                "(items)=>items.map(x=>x.value)"
            ) == ["explorer", "split", "story"], "Unexpected modes"
            select.select_option("split")
            page.wait_for_timeout(100)
            initial = page.evaluate("""() => {
              const s=document.querySelector('.tour-journey-discovery');
              const p=s.querySelector('.jd-split-v3__panel');
              const terms=JSON.parse(s.dataset.tourDestinations||'[]');
              return {
                mode:s.dataset.tourJourneyMode,
                ready:s.dataset.jdSplitReady,
                taxonomyCount:terms.length,
                header:p.querySelector('.jd-split-v3__heading')?.textContent.trim(),
                levels:[...p.querySelectorAll('.jd-split-v3__level')].map(
                  x=>({kind:x.dataset.kind,label:x.querySelector('span')?.textContent.trim(),
                       count:Number(x.querySelector('strong')?.textContent)})),
                nativeTotal:s.querySelectorAll('.jd-native-place').length,
                nativeTemplateCount:s.querySelectorAll('.tour-journey-related-editor').length,
                splitHidden:p.hidden,
                pageOverflow:document.documentElement.scrollWidth>innerWidth
              };
            }""")
            assert initial["mode"] == "split" and initial["ready"] == "1"
            assert page.locator(".tour-journey-discovery").get_attribute("data-jd-modes-version") == "3", "Unified modes runtime missing"
            assert initial["taxonomyCount"] >= 1
            assert initial["nativeTemplateCount"] == 1
            assert not initial["splitHidden"] and not initial["pageOverflow"]
            assert initial["levels"], "No available taxonomy level"
            for level in initial["levels"]:
                assert len(level["label"]) < 35, "Unbounded heading leaked into category label"
                page.locator(
                    '.jd-split-v3__level[data-kind="' + level["kind"] + '"]'
                ).click()
                page.wait_for_timeout(55)
                state = page.evaluate("""() => {
                  const s=document.querySelector('.tour-journey-discovery');
                  return {
                    kind:s.dataset.jdSplitLevel,
                    visible:s.querySelectorAll(
                      '.tour-place-group[data-tour-active=true] .jd-native-place:not([hidden])'
                    ).length,
                    path:s.querySelector('.jd-split-v3__path')?.textContent.trim(),
                    pageOverflow:document.documentElement.scrollWidth>innerWidth,
                    wrappers:[...s.querySelectorAll('.tour-place-group[data-tour-active=true] .jd-native-place:not([hidden]) > .elementor > .e-con')]
                      .filter(el=>el.querySelector(':scope > .e-con'))
                      .map(el=>({background:getComputedStyle(el).backgroundColor,
                        shadow:getComputedStyle(el).boxShadow})),
                  };
                }""")
                assert state["kind"] == level["kind"]
                assert state["visible"] == level["count"], "Wrong native card count for category"
                assert all(el["background"]=="rgba(0, 0, 0, 0)" and el["shadow"]=="none"
                           for el in state["wrappers"]), "Redundant white image-card backing remains"
                assert not state["pageOverflow"]

            select.select_option("explorer")
            page.wait_for_timeout(100)
            explorer = page.evaluate("""() => {
              const s=document.querySelector('.tour-journey-discovery');
              return {
                mode:s.dataset.tourJourneyMode,
                taxonomyPanelVisible:!s.querySelector('.jd-split-v3__panel').hidden,
                levels:[...s.querySelectorAll('.jd-split-v3__level')].map(n=>n.dataset.kind),
                miniCards:[...s.querySelectorAll('.jd-place-tile')].map(n=>({
                  background:getComputedStyle(n).backgroundColor,
                  border:getComputedStyle(n).borderTopWidth
                })),
                visible:s.querySelectorAll(
                  '.tour-place-group[data-tour-active=true] .jd-native-place:not([hidden])'
                ).length,
                overflow:document.documentElement.scrollWidth>innerWidth
              };
            }""")
            assert explorer["mode"] == "explorer" and explorer["taxonomyPanelVisible"]
            assert explorer["visible"] >= 1 and not explorer["overflow"]
            assert explorer["levels"] and set(explorer["levels"])==set(x["kind"] for x in initial["levels"])
            assert all(card["background"] == "rgba(0, 0, 0, 0)"
                       and card["border"] == "0px" for card in explorer["miniCards"]), "white card behind Explorer place"
            # All original JetEngine cards and levels are navigable.
            for level in initial["levels"]:
                page.locator('.jd-split-v3__level[data-kind="' + level["kind"] + '"]').click()
                page.wait_for_timeout(55)
                count=page.locator('.tour-place-group[data-tour-active=true] .jd-native-place:not([hidden])').count()
                assert count==level["count"], "Explorer taxonomy count mismatch"
            select.select_option("story")
            page.wait_for_timeout(100)
            story = page.evaluate("""() => {
              const s=document.querySelector('.tour-journey-discovery');
              return {
                mode:s.dataset.tourJourneyMode,
                taxonomyPanelVisible:!s.querySelector('.jd-split-v3__panel').hidden,
                levels:[...s.querySelectorAll('.jd-split-v3__level')].map(n=>n.dataset.kind),
                pointers:[...s.querySelectorAll('.jd-modes-v3__story-pointer')].filter(n=>getComputedStyle(n).display!=='none').length,
                highlights:s.querySelectorAll('.tour-hl-ui__card').length,
                miniCards:[...s.querySelectorAll('.jd-place-tile')].map(n=>({
                  background:getComputedStyle(n).backgroundColor,
                  border:getComputedStyle(n).borderTopWidth
                })),
                overflow:document.documentElement.scrollWidth>innerWidth
              };
            }""")
            assert story["mode"] == "story" and story["taxonomyPanelVisible"] and not story["overflow"]
            assert story["pointers"]==story["highlights"], "Story item pointer count mismatch"
            assert set(story["levels"])==set(x["kind"] for x in initial["levels"])
            assert all(card["background"]=="rgba(0, 0, 0, 0)" and card["border"]=="0px"
                       for card in story["miniCards"]), "white card behind Story place"
            story_link=page.locator(".jd-modes-v3__story-action").first
            if story_link.count():
                story_link.click()
                page.wait_for_timeout(130)
                assert page.evaluate("location.hash.startsWith('#tour-place-panel-')"), "Story pointer lost anchor"
            # Regression for native image-backed Elementor containers:
            # removing a white *wrapper* must never remove its background image.
            media = page.evaluate("""() => {
                const section=document.querySelector('.tour-journey-discovery');
                const group=section.querySelector('.tour-place-group[data-tour-active=true]');
                if(!group)throw Error('No native destination group');
                const fixture=document.createElement('div');
                fixture.className='jd-native-place';
                fixture.innerHTML='<div class="elementor"><div class="e-con" '
                  +'style="background-image:linear-gradient(#204c50,#204c50);'
                  +'background-color:white;box-shadow:0 3px 8px #333">'
                  +'<div class="e-con"><span>Media preserved</span></div></div></div>';
                group.appendChild(fixture);
                const node=fixture.querySelector('.elementor > .e-con');
                const computed=getComputedStyle(node);
                const result={image:computed.backgroundImage,
                    background:computed.backgroundColor,shadow:computed.boxShadow};
                fixture.remove();
                return result;
            }""")
            assert media["image"] != "none" and "gradient" in media["image"], (
                "original Elementor background image was stripped"
            )
            assert media["background"] == "rgba(0, 0, 0, 0)" and media["shadow"] == "none", (
                "redundant media wrapper remained"
            )
            assert not errors, "JavaScript page errors"
            print(json.dumps({
                "ok": True, "http": response.status, "viewport": args.width,
                "language": page.evaluate("document.documentElement.lang"),
                "split": initial, "explorer": explorer, "story": story,
                "mediaPreservation": media, "jsErrors": errors,
            }, ensure_ascii=False))
            return 0
        except Exception as err:
            print(json.dumps({
                "ok": False, "url": args.url,
                "error": str(err)[:450], "jsErrors": errors[:3],
            }, ensure_ascii=False))
            return 3
        finally:
            page.close()
            browser.close()


if __name__ == "__main__":
    sys.exit(main())
