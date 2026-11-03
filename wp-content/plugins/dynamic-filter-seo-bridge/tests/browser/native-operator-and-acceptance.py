#!/usr/bin/env python3
"""Read-only Staging browser gate: an explicit AND URL must stay AND after JSF hydration."""
import argparse
import json
import sys
from urllib.parse import parse_qs
from playwright.sync_api import sync_playwright

parser = argparse.ArgumentParser()
parser.add_argument("--base-url", default="https://tours.dev.mad4b.com")
parser.add_argument("--expected-count", type=int, default=27)
args = parser.parse_args()
url = args.base_url.rstrip("/") + (
    "/tours-and-activities/jsf/jet-engine:tours_query_archive/"
    "tax/tour-styles:city-tours,cultural-tours,operator_AND/"
)
report = {"route": url, "expected_operator": "operator_AND", "expected_count": args.expected_count,
          "native_requests": [], "errors": []}
with sync_playwright() as play:
    browser = play.chromium.launch(headless=True, args=["--no-sandbox", "--disable-dev-shm-usage"])
    page = browser.new_page(viewport={"width": 1360, "height": 900})
    page.on("pageerror", lambda e: report["errors"].append(str(e)[:200]))
    def on_response(response):
        request = response.request
        if request.method != "POST" or "admin-ajax.php" not in request.url:
            return
        params = parse_qs(request.post_data or "")
        if params.get("action") != ["jet_smart_filters"]:
            return
        values = params.get("query[_tax_query_tour-styles][]", [])
        try:
            data = response.json()
            found = data.get("pagination", {}).get("found_posts")
        except Exception:
            found = None
        report["native_requests"].append({"status": response.status, "terms": values, "found_posts": found})
    page.on("response", on_response)
    try:
        response = page.goto(url, wait_until="domcontentloaded", timeout=35000)
        page.wait_for_timeout(7200)
        report["page_status"] = response.status if response else None
        report["result"] = page.evaluate("""() => ({
            path: location.pathname,
            query: window.JetSmartFilters?.filterGroups?.['jet-engine/tours_query_archive']?.currentQuery || {},
            listed: document.querySelectorAll('[data-query-id="5"][data-listing-id] .jet-listing-grid__item').length
        })""")
        values = report["result"]["query"].get("_tax_query_tour-styles", [])
        report["operator_kept_in_path"] = "operator_AND" in report["result"]["path"]
        report["operator_kept_in_query"] = "operator_AND" in values if isinstance(values,list) else False
        counts = [request["found_posts"] for request in report["native_requests"] if request["found_posts"] is not None]
        report["count_matches"] = not counts or counts[-1] == args.expected_count
        report["pass"] = (report["page_status"] == 200 and
                          report["operator_kept_in_path"] and report["operator_kept_in_query"] and
                          report["count_matches"] and not report["errors"])
    except Exception as exc:
        report["error"] = str(exc)[:350]
        report["pass"] = False
    finally:
        browser.close()
print(json.dumps(report, ensure_ascii=False))
sys.exit(0 if report["pass"] else 1)
