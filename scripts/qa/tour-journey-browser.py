#!/usr/bin/env python3
"""Read-only Journey Discovery browser acceptance.

Requires: pip install playwright && playwright install chromium
Run: python scripts/qa/tour-journey-browser.py --url https://example.com/tour/
Use --disable-quic only if an intermediary/browser environment triggers
ERR_NETWORK_CHANGED; do not treat the flag as a server-side fix.
"""
from __future__ import annotations
import argparse
import json
import sys
from playwright.sync_api import sync_playwright

MODES = ("explorer", "split", "story", "compact")

def main() -> int:
    p = argparse.ArgumentParser()
    p.add_argument("--url", required=True)
    p.add_argument("--width", type=int, default=390)
    p.add_argument("--height", type=int, default=844)
    p.add_argument("--timeout-ms", type=int, default=18000)
    p.add_argument("--disable-quic", action="store_true")
    args = p.parse_args()
    if not args.url.startswith("https://"):
        print(json.dumps({"ok": False, "error": "URL must use HTTPS"}))
        return 2
    with sync_playwright() as pw:
        browser = pw.chromium.launch(
            headless=True,
            args=["--no-sandbox"] + (["--disable-quic"] if args.disable_quic else []),
        )
        page = browser.new_page(viewport={"width": args.width, "height": args.height})
        errors = []
        page.on("pageerror", lambda err: errors.append(str(err)))
        try:
            response = page.goto(args.url, wait_until="domcontentloaded", timeout=args.timeout_ms)
            page.wait_for_timeout(350)
            section = page.locator(".tour-journey-discovery").first
            assert response is not None and response.status == 200, "HTTP status failed"
            assert section.count() == 1, "Journey Discovery section missing"
            section.scroll_into_view_if_needed(timeout=7000)
            assert "tour-journey-enhanced" in (section.get_attribute("class") or ""), "Native destination tabs not initialized"
            assert section.locator(".tour-journey-related-editor").count() == 1, "Duplicated or missing Elementor template"
            tabs = section.locator(".tour-journey-place-tab")
            assert tabs.count() >= 1, "No destination tabs"
            selector = section.locator(".tour-journey-layout-select")
            assert selector.count() == 1, "Layout selector missing"
            options = selector.locator("option").evaluate_all("(nodes)=>nodes.map(n=>n.value)")
            assert tuple(options) == MODES, f"Unexpected modes: {options}"
            measurements = {}
            for mode in MODES:
                selector.select_option(mode)
                page.wait_for_timeout(70)
                view = page.evaluate("""() => {
                  const a = document.querySelector('.tour-journey-discovery');
                  return {
                    classList: a.className,
                    overflow: document.documentElement.scrollWidth > innerWidth,
                    activeTabs: a.querySelectorAll('[role=tab][aria-selected=true]').length,
                    height: Math.round(a.getBoundingClientRect().height),
                    selectedMode: a.dataset.tourJourneyMode
                  };
                }""")
                assert "tour-journey--" + mode in view["classList"], "Mode class mismatch"
                assert view["selectedMode"] == mode, "Selected mode not reflected in component"
                assert view["activeTabs"] == 1, "Exactly one active tab required"
                assert not view["overflow"], "Horizontal page overflow"
                measurements[mode] = view["height"]
            if tabs.count() > 1:
                tabs.nth(1).click()
                assert tabs.nth(1).get_attribute("aria-selected") == "true", "Tab click failed"
            result = {
                "ok": not errors,
                "http": response.status,
                "document_lang": page.evaluate("document.documentElement.lang"),
                "viewport": [args.width, args.height],
                "tab_count": tabs.count(),
                "modes": measurements,
                "js_errors": errors,
                "url": args.url,
            }
            print(json.dumps(result, ensure_ascii=False))
            return 0 if result["ok"] else 3
        except Exception as exc:
            print(json.dumps({"ok": False, "url": args.url, "error": str(exc)[:500], "js_errors": errors}, ensure_ascii=False))
            return 4
        finally:
            page.close()
            browser.close()

if __name__ == "__main__":
    sys.exit(main())
