#!/usr/bin/env python3
"""Real pointer acceptance for the MAD4B Experience Layout Switcher adapter.

Usage:
  python3 layout-real-pointer-acceptance.py https://site.example/tours-and-activities/ 1368 3
No credentials, AJAX mutation outside the live listing UI, or DB writes.
"""
import asyncio
import json
import sys
import time
from playwright.async_api import async_playwright

URL = sys.argv[1] if len(sys.argv) > 1 else "https://tours.dev.mad4b.com/tours-and-activities/"
WIDTH = int(sys.argv[2]) if len(sys.argv) > 2 else 1368
DELAY = float(sys.argv[3]) if len(sys.argv) > 3 else 3.0

report = {"url": URL, "width": WIDTH, "delaySeconds": DELAY, "states": [], "requests": [], "errors": []}


async def main():
    async with async_playwright() as p:
        browser = await p.chromium.launch(headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"])
        page = await browser.new_page(
            viewport={"width": WIDTH, "height": 880},
            has_touch=WIDTH < 600,
            is_mobile=WIDTH < 600,
        )
        start = time.monotonic()
        now = lambda: round((time.monotonic() - start) * 1000)
        page.on("pageerror", lambda err: report["errors"].append(str(err)[:200]))

        async def delayed_layout(route):
            req = route.request
            try:
                data = req.post_data or ""
            except UnicodeDecodeError:
                data = ""
            if req.method == "POST" and "switch_layout" in data:
                report["requests"].append({"timeMs": now(), "kind": "layout", "url": req.url[:170]})
                await asyncio.sleep(DELAY)
            await route.continue_()

        await page.route("**/*", delayed_layout)

        snapshot_js = r"""stage => {
          const wrapper = document.querySelector(".je-layout-switcher");
          const widgetId = String(wrapper?.dataset.widgetId || "");
          const listing = widgetId
            ? document.querySelector(".elementor-element-" + widgetId + " .jet-listing-grid__items")
            : null;
          return {
            stage,
            widgetId,
            listing: listing?.className.match(/jet-listing-grid--(\d+)/)?.[1] || null,
            loading: !!wrapper?.classList.contains("je-layout-switcher--loading"),
            pending: wrapper?.dataset.layoutPending || null,
            busy: wrapper?.getAttribute("aria-busy") || null,
            buttons: [...(wrapper?.querySelectorAll(".je-layout-switcher__btn") || [])].map(button => {
              const rect = button.getBoundingClientRect();
              const centerX = rect.x + rect.width / 2, centerY = rect.y + rect.height / 2;
              return {
                slug: button.dataset.slug,
                active: button.classList.contains("je-layout-switcher__btn--active"),
                pointer: getComputedStyle(button).pointerEvents,
                hittable: button.contains(document.elementFromPoint(centerX, centerY)),
                center: [centerX, centerY]
              };
            })
          };
        }"""

        async def snapshot(stage):
            state = await page.evaluate(snapshot_js, stage)
            state["atMs"] = now()
            report["states"].append(state)
            return state

        try:
            response = await page.goto(URL, wait_until="domcontentloaded", timeout=40000)
            report["http"] = response.status
            await page.locator(".je-layout-switcher__btn[data-slug='grid']").first.wait_for(state="visible", timeout=17000)
            await page.wait_for_timeout(1100)
            original = await snapshot("before")
            assert original["listing"], "No published listing grid"
            assert any(b["slug"] == "list" and b["active"] for b in original["buttons"]), "Test starts in List view"
            centers = {button["slug"]: button["center"] for button in original["buttons"]}
            await page.mouse.click(*centers["grid"])
            await page.wait_for_timeout(150)
            await snapshot("during-grid-request")
            await page.mouse.click(*centers["list"])
            await page.wait_for_timeout(150)
            await snapshot("last-intent-is-list")
            await page.wait_for_function(
                r"""original => {
                  const wrapper = document.querySelector(".je-layout-switcher");
                  const widgetId = String(wrapper?.dataset.widgetId || "");
                  const listing = document.querySelector(".elementor-element-" + widgetId + " .jet-listing-grid__items");
                  const listButton = wrapper?.querySelector(".je-layout-switcher__btn[data-slug='list']");
                  return (
                    !wrapper?.classList.contains("je-layout-switcher--loading") &&
                    !wrapper?.dataset.layoutPending &&
                    listButton?.classList.contains("je-layout-switcher__btn--active") &&
                    listing?.classList.contains("jet-listing-grid--" + original)
                  );
                }""",
                arg=original["listing"],
                timeout=55000,
            )
            await snapshot("settled")
        except Exception as exc:
            report["error"] = str(exc)[:360]
            try:
                await snapshot("failure")
            except Exception:
                pass
        await browser.close()

asyncio.run(main())
states = report["states"]
during = states[1] if len(states) > 1 else {}
pending = states[2] if len(states) > 2 else {}
settled = states[-1] if states else {}
report["pass"] = bool(
    report.get("http") == 200
    and not report.get("error")
    and not report["errors"]
    and during.get("loading")
    and all(b["pointer"] == "auto" and b["hittable"] for b in during.get("buttons", []))
    and pending.get("pending") == "list"
    and settled.get("listing") == states[0].get("listing")
    and settled.get("loading") is False
    and settled.get("busy") is None
)
print(json.dumps(report, ensure_ascii=False))
sys.exit(0 if report["pass"] else 1)
