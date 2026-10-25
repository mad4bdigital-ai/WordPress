#!/usr/bin/env python3
"""Local-only browser contract for the real plugin JS with native WordPress React libs.
The public QA page remains Draft; this test does NOT simulate a completed payment."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright

SRC = Path(os.environ.get("SWITCHER_JS", "/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/plugins/etg-traveller-nationality-switcher/assets/checkout-blocks.js"))
WP = Path(os.environ.get("WP_SITE", "/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy"))
REACT = WP / "wp-includes/js/dist/vendor/react.min.js"
REACT_DOM = WP / "wp-includes/js/dist/vendor/react-dom.min.js"
CSS = SRC.parent / "nationality.css"
assert all(p.is_file() for p in (SRC, REACT, REACT_DOM, CSS))
with sync_playwright() as p:
    browser = p.chromium.launch(headless=True, args=["--no-sandbox"])
    for width in (1440, 390):
        page = browser.new_page(viewport={"width": width, "height": 900})
        errors = []
        page.on("pageerror", lambda error: errors.append(str(error)))
        page.set_content("<!doctype html><html><body><main id='booking'></main></body></html>")
        page.add_script_tag(path=str(REACT))
        page.add_script_tag(path=str(REACT_DOM))
        page.add_style_tag(path=str(CSS))
        page.evaluate("""() => {
          window.__plugin = null;
          window.__checkoutData = {};
          window.wp = {
            element: {createElement: React.createElement, useState: React.useState, useEffect: React.useEffect, createRoot: ReactDOM.createRoot},
            plugins: {registerPlugin: (slug,plugin) => {window.__plugin = plugin;}},
            data: {dispatch: () => ({setExtensionData: (name, value) => {window.__checkoutData[name]=value;}})}
          };
          window.wc = {blocksCheckout: {ExperimentalOrderMeta: () => null}, wcBlocksData: {CHECKOUT_STORE_KEY:'checkout'}};
          window.etgTravellerBlocks = {
            namespace: 'etg-traveller-nationality', preferred: '', max: 20,
            countries: [{value:'EG', label:'Egypt'}, {value:'SA',label:'Saudi Arabia'}, {value:'US',label:'United States'}]
          };
        }""")
        page.add_script_tag(path=str(SRC))
        assert page.evaluate("!!window.__plugin"), "Plugin JS not registered"
        page.evaluate("""() => {
          let plugin = window.__plugin;
          window.__root = ReactDOM.createRoot(document.querySelector('#booking'));
          window.__root.render(React.cloneElement(plugin.render().props.children, {cart: {items: [
            {key:'rate-3',name:'Three-person tour',extensions:{'etg-traveller-nationality':{bookable:true,travellers:3}}},
            {key:'rate-2',name:'Two-person tour',extensions:{'etg-traveller-nationality':{bookable:true,travellers:2}}},
            {key:'no-certified-quote',name:'Unverified booking',extensions:{'etg-traveller-nationality':{bookable:true,travellers:0}}},
            {key:'shirt',name:'Ordinary product',extensions:{'etg-traveller-nationality':{bookable:false,travellers:0}}}
          ]}}));
        }""")
        selects = page.locator("#booking select")
        selects.first.wait_for()
        assert selects.count() == 5, f"Expected 5 nationality fields, got {selects.count()}"
        assert page.locator("#booking [role=alert]").count() == 1
        for i, nationality in enumerate(["EG", "US", "SA", "US", "EG"]):
            selects.nth(i).select_option(nationality)
        page.wait_for_function("""() => JSON.stringify(window.__checkoutData['etg-traveller-nationality']?.travellers?.['rate-3']) === '["EG","US","SA"]'""")
        data = page.evaluate("window.__checkoutData['etg-traveller-nationality'].travellers")
        assert data["rate-3"] == ["EG", "US", "SA"]
        assert data["rate-2"] == ["US", "EG"]
        assert data["no-certified-quote"] == []
        assert "shirt" not in data
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), "Horizontal overflow"
        assert not errors, errors
        print(f"BROWSER_BLOCKS_UI_PASS width={width} fields={selects.count()} unverified_alert=1 react_errors=0")
        page.close()
    browser.close()
