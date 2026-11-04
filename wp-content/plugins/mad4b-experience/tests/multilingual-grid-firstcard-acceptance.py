#!/usr/bin/env python3
"""Read-only Central Staging Grid/List regression test (Playwright required).

Run: python3 tests/multilingual-grid-firstcard-acceptance.py
Requires browser-accessible staging and the exact source fixture; no writes.
"""
import json
from pathlib import Path
from playwright.sync_api import sync_playwright

BASE = "https://tours.dev.mad4b.com"
CASES = {"en": (192, "60481"), "fr": (199, "60491"),
         "de": (202, "60535"), "es": (205, "60579"),
         "it": (1358, "64871"), "ar": (2102, "64874")}
JS = r"""() => {
const grid = document.querySelector('.elementor-element-7739f090 .jet-listing-grid__items');
const first = grid?.querySelector(':scope > .jet-listing-grid__item');
const heads = [...(first?.querySelectorAll('.elementor-widget-heading h3') || [])];
const q = window.JetSmartFilters?.filterGroups?.['jet-engine/tours_query_archive'];
return {post_id:first?.dataset.postId, title:heads[0]?.textContent?.trim(),
       href:heads[0]?.querySelector('a')?.href,
       other_titles:heads.map(x=>x.textContent?.trim()),
       other_links:heads.map(x=>x.querySelector('a')?.href),
       query_term:q?.currentQuery?.['_tax_query_tour-types'],
       leaked:'17 City' === first?.innerText?.trim().slice(0,7)};
}"""
def main():
    rows = []
    with sync_playwright() as pw:
        browser = pw.chromium.launch(headless=True, args=["--no-sandbox"])
        for lang, (term, expected_id) in CASES.items():
            page = browser.new_page(viewport={"width": 1365, "height": 850})
            errors = []
            page.on("pageerror", lambda exc: errors.append(str(exc)))
            url = BASE + ("/" if lang == "en" else f"/{lang}/") + "tours-and-activities/"
            result = {"lang": lang, "url": url, "expected": expected_id}
            try:
                response = page.goto(url, wait_until="commit", timeout=30000)
                page.wait_for_timeout(1300)
                page.locator('.je-layout-switcher__btn[data-slug="grid"]').first.click()
                page.wait_for_function(
                    "() => document.querySelector('.je-layout-switcher__btn--active')?.dataset.slug==='grid'")
                checkbox = page.locator(
                    f'[data-query-var="tour-types"] input[type="checkbox"][value="{term}"]').first
                details = checkbox.locator("xpath=ancestor::details[1]")
                if details.count() and details.get_attribute("open") is None:
                    details.locator("summary").click()
                checkbox.locator("xpath=..").click()
                page.wait_for_timeout(6500)
                result.update(page.evaluate(JS))
                result["pass"] = bool(response and response.status == 200 and
                    result.get("post_id") == expected_id and
                    result.get("query_term") == str(term) and
                    result.get("title") and result.get("href") and
                    all(h == result["title"] for h in result.get("other_titles",[])) and
                    all(link == result["href"] for link in result.get("other_links",[])) and
                    not result.get("leaked") and not errors)
            except Exception as exc:
                result.update(pass_=False, error=str(exc)[:400])
                result["pass"] = False
            finally:
                result["errors"] = errors
                rows.append(result)
                page.close()
        browser.close()
    print(json.dumps(rows, ensure_ascii=False, indent=2))
    assert len(rows) == 6 and all(row["pass"] for row in rows), "Grid staging regression"
if __name__ == "__main__":
    main()
