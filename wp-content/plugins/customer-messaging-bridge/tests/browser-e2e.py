"""
ETG Lab-only browser smoke using a fresh isolated Chromium profile.
No real-customer sends. Set CMB_E2E_SEND_TEST_MESSAGE=1 to submit one clearly
marked synthetic guest message; do NOT enable on a production website.
"""
from __future__ import annotations
import json
import os
from urllib.parse import urlparse, parse_qs
from playwright.sync_api import sync_playwright

LAB_URL = os.getenv("CMB_E2E_LAB_URL", "http://localhost:8091/")
CHROME = os.getenv("CMB_CHROME_BIN", "/root/.cache/ms-playwright/chromium-1194/chrome-linux/chrome")
SEND_MESSAGE = os.getenv("CMB_E2E_SEND_TEST_MESSAGE", "0") == "1"
WORKSPACE = "11755877814459770"
WEBCHAT = "11755877814902375"
results = []
with sync_playwright() as p:
    browser = p.chromium.launch(
        executable_path=CHROME,
        headless=True,
        args=["--no-sandbox", "--disable-dev-shm-usage", "--disable-background-networking"],
    )
    try:
        for name, viewport in [
            ("desktop", {"width": 1365, "height": 900}),
            ("mobile", {"width": 390, "height": 844}),
        ]:
            context = browser.new_context(
                viewport=viewport, locale="en-US", ignore_https_errors=False,
                is_mobile=name == "mobile",
                has_touch=name == "mobile",
            )
            try:
                page = context.new_page()
                js_errors = []
                page.on("pageerror", lambda error: js_errors.append(str(error)[:140]))
                page.goto(LAB_URL, wait_until="domcontentloaded", timeout=45000)
                button = page.locator(".ahc-btn")
                button.wait_for(state="visible", timeout=35000)
                assert button.count() == 1, f"{name}: duplicate widget button"
                button.click(timeout=15000)
                iframe = page.locator("#ahc-iframe")
                iframe.wait_for(state="attached", timeout=22000)
                assert iframe.count() == 1, f"{name}: duplicate iframe"
                src = iframe.get_attribute("src")
                parsed = urlparse(src)
                query = parse_qs(parsed.query)
                assert parsed.hostname == "chatbotx.mad4b.com", f"{name}: incorrect widget host"
                assert query.get("workspaceId") == [WORKSPACE], f"{name}: incorrect workspace"
                assert query.get("webchatId") == [WEBCHAT], f"{name}: incorrect webchat"
                chat = page.frame_locator("#ahc-iframe")
                field = chat.locator('textarea[placeholder="Message..."]')
                field.wait_for(state="visible", timeout=35000)
                sent = False
                if name == "desktop" and SEND_MESSAGE:
                    msg = "ETG Lab automatic Webchat QA synthetic message. No customer action required."
                    field.fill(msg)
                    field.press("Enter")
                    chat.locator("body").get_by_text(msg, exact=True).wait_for(
                        state="visible", timeout=20000
                    )
                    sent = field.input_value() == ""
                assert not js_errors, f"{name}: JavaScript exceptions {js_errors[:2]}"
                results.append({
                    "viewport": name,
                    "bubble": "pass",
                    "iframe": "pass",
                    "guest_message_field": "pass",
                    "synthetic_message_sent": sent,
                    "javascript_errors": 0,
                })
            finally:
                context.close()
    finally:
        browser.close()
print(json.dumps({"overall": "pass", "results": results}))
