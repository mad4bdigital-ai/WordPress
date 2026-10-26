#!/usr/bin/env python3
"""Local-only browser contract for the real plugin JS with native WordPress React libs.
The public QA page remains Draft; this test does NOT simulate a completed payment."""
from pathlib import Path
import os
from playwright.sync_api import sync_playwright

SRC = Path(os.environ.get("SWITCHER_JS", "/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy/wp-content/plugins/etg-traveller-nationality-switcher/assets/checkout-blocks.js"))
WP = Path(os.environ.get("WP_SITE", "/opt/hostinger-migrations/hosting-3/etg-woocommerce-lab/site-copy"))
REACT = Path(os.environ.get("SWITCHER_REACT", str(WP / "wp-includes/js/dist/vendor/react.min.js")))
REACT_DOM = Path(os.environ.get("SWITCHER_REACT_DOM", str(WP / "wp-includes/js/dist/vendor/react-dom.min.js")))
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
            data: {dispatch: () => ({setExtensionData: (...args) => {
              if (args.length !== 2 || !args[1] || !args[1].travellers || !args[1].confirmed) {
                throw new Error('Woo checkout direct store dispatch requires (namespace, data)');
              }
              window.__checkoutData[args[0]]=args[1];
            }})}
          };
          window.wc = {blocksCheckout: {ExperimentalOrderMeta: () => null}, wcBlocksData: {CHECKOUT_STORE_KEY:'checkout'}};
          window.travelBookingTravellerBlocks = {
            namespace: 'travel-booking-traveller-nationality', preferred: '', max: 999, batch_size: 20,
            countries: [{value:'EG', label:'Egypt'}, {value:'SA',label:'Saudi Arabia'}, {value:'US',label:'United States'}]
          };
        }""")
        page.add_script_tag(path=str(SRC))
        assert page.evaluate("!!window.__plugin"), "Plugin JS not registered"
        page.evaluate("""() => {
          let plugin = window.__plugin;
          window.__root = ReactDOM.createRoot(document.querySelector('#booking'));
          window.__root.render(React.cloneElement(plugin.render().props.children, {cart: {items: [
            {key:'rate-3',name:'Three-person tour',extensions:{'travel-booking-traveller-nationality':{bookable:true,travellers:3}}},
            {key:'rate-2',name:'Two-person tour',extensions:{'travel-booking-traveller-nationality':{bookable:true,travellers:2}}},
            {key:'no-certified-quote',name:'Unverified booking',extensions:{'travel-booking-traveller-nationality':{bookable:true,travellers:0}}},
            {key:'shirt',name:'Ordinary product',extensions:{'travel-booking-traveller-nationality':{bookable:false,travellers:0}}}
          ]}}));
        }""")
        selects = page.locator("#booking select")
        selects.first.wait_for()
        assert selects.count() == 5, f"Expected 5 nationality fields, got {selects.count()}"
        assert page.locator("#booking [role=alert]").count() == 1
        for i, nationality in enumerate(["EG", "US", "SA", "US", "EG"]):
            selects.nth(i).select_option(nationality)
        page.wait_for_function("""() => JSON.stringify(window.__checkoutData['travel-booking-traveller-nationality']?.travellers?.['rate-3']) === '["EG","US","SA"]'""")
        for checkbox in page.locator("#booking .travel-booking-confirmation input").all():
            checkbox.check()
        page.wait_for_function("""() => {
          const ack=window.__checkoutData['travel-booking-traveller-nationality']?.confirmed;
          return ack?.['rate-3']===true && ack?.['rate-2']===true;
        }""")
        data = page.evaluate("window.__checkoutData['travel-booking-traveller-nationality'].travellers")
        assert data["rate-3"] == ["EG", "US", "SA"]
        assert data["rate-2"] == ["US", "EG"]
        assert data["no-certified-quote"] == []
        assert "shirt" not in data
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth"), "Horizontal overflow"
        assert not errors, errors
        print(f"BROWSER_BLOCKS_UI_PASS width={width} fields={selects.count()} unverified_alert=1 react_errors=0")
        page.evaluate("""() => {
          window.__root.render(React.cloneElement(window.__plugin.render().props.children, {cart: {items: [
            {key:'party-55',name:'Group of 55 passengers',extensions:{
              'travel-booking-traveller-nationality':{bookable:true,travellers:55}
            }}
          ]}}));
        }""")
        page.get_by_role('button', name='Next 20').wait_for()
        for current in range(3):
            field = page.locator('#booking select')
            field.first.wait_for()
            expected = 20 if current < 2 else 15
            assert field.count() == expected, f"Page {current}: {field.count()} != {expected}"
            for i in range(expected):
                field.nth(i).select_option('EG')
            if current < 2:
                page.get_by_role('button', name='Next 20').click()
                page.wait_for_function(
                    f"() => document.querySelector('#booking fieldset').textContent.includes('Page {current + 2} of 3')"
                )
        page.wait_for_function("""() => {
          const codes=window.__checkoutData['travel-booking-traveller-nationality']?.travellers?.['party-55'];
          return codes?.length===55 && codes.every(x=>x==='EG');
        }""")
        page.locator("#booking .travel-booking-confirmation input").check()
        page.wait_for_function("""() => window.__checkoutData['travel-booking-traveller-nationality']?.confirmed?.['party-55']===true""")
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
        assert not errors, errors
        print(f"BROWSER_55_PASS width={width} pages=3 submitted=55 react_errors=0")
        # Prefill remains a draft, editing must invalidate previously reviewed status.
        page.evaluate("""() => {
          window.travelBookingTravellerBlocks.preferred='EG';
          window.__root.render(React.cloneElement(window.__plugin.render().props.children, {cart: {items: [
            {key:'smart-3',name:'Three travellers',extensions:{
              'travel-booking-traveller-nationality':{bookable:true,travellers:3,suggestion:'all'}
            }}
          ]}}));
        }""")
        page.wait_for_function("""() => document.querySelectorAll('#booking select').length===3""")
        page.wait_for_function("""() => Array.from(document.querySelectorAll('#booking select')).every(x=>x.value==='EG')""")
        assert not page.locator("#booking .travel-booking-confirmation input").is_checked()
        page.locator("#booking select").nth(1).select_option('US')
        page.locator("#booking .travel-booking-confirmation input").check()
        page.wait_for_function("""() => window.__checkoutData['travel-booking-traveller-nationality']?.confirmed?.['smart-3']===true""")
        page.locator("#booking select").nth(2).select_option('SA')
        page.wait_for_function("""() => window.__checkoutData['travel-booking-traveller-nationality']?.confirmed?.['smart-3']===false""")
        page.get_by_role('button', name='Use EG for unfilled travellers').click()
        page.wait_for_function("""() => {
          const v=window.__checkoutData['travel-booking-traveller-nationality']?.travellers?.['smart-3'];
          return JSON.stringify(v)==='["EG","US","SA"]';
        }""")
        page.locator("#booking .travel-booking-confirmation input").check()
        assert page.evaluate("document.documentElement.scrollWidth <= window.innerWidth")
        assert not errors, errors
        print(f"BROWSER_SMART_SUGGESTIONS_PASS width={width} mode=all editable=true reconfirm=true no_overwrite=true")
        # Policy none does not prefill; first only prepopulates traveller 1.
        page.evaluate("""() => {
          window.__root.render(React.cloneElement(window.__plugin.render().props.children, {cart: {items: [
            {key:'first-3',id:1001,name:'First only',extensions:{
              'travel-booking-traveller-nationality':{bookable:true,travellers:3,suggestion:'first'}
            }}
          ]}}));
        }""")
        page.wait_for_function("""() => {
          const s=Array.from(document.querySelectorAll('#booking select')).map(x=>x.value);
          return JSON.stringify(s)==='["EG","",""]';
        }""")
        assert not page.locator("#booking .travel-booking-confirmation input").is_enabled()
        page.evaluate("""() => {
          window.__root.render(React.cloneElement(window.__plugin.render().props.children, {cart: {items: [
            {key:'none-3',id:1002,name:'Manual only',extensions:{
              'travel-booking-traveller-nationality':{bookable:true,travellers:3,suggestion:'none'}
            }}
          ]}}));
        }""")
        page.wait_for_function("""() => {
          const s=Array.from(document.querySelectorAll('#booking select')).map(x=>x.value);
          return s.length===3 && s.every(x=>x==='');
        }""")
        print(f"BROWSER_POLICY_MODES_PASS width={width} first_only=true none=true")
        page.close()
    browser.close()
