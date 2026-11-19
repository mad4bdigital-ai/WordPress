#!/usr/bin/env python3
"""Contract for in-page pricing UI. No browser, network, database or writes."""
from html.parser import HTMLParser
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
P=ROOT/"wp-content/mu-plugins"
html=(P/"mad4b-stay-pricing-admin/panel.html").read_text()
js=(P/"mad4b-stay-pricing-admin/ui.js").read_text()
css=(P/"mad4b-stay-pricing-admin/ui.css").read_text()
module=(P/"008-mad4b-stay-pricing-controls-panel.php").read_text()
admin=(P/"007-mad4b-stay-price-layers-admin.php").read_text()
class UIContract(HTMLParser):
    def __init__(self):
        super().__init__();self.keys=[];self.stack=[];self.controls=[];self.buttons=[]
    def handle_starttag(self,tag,attrs):
        props=dict(attrs)
        self.stack.append(tag)
        if "data-stay" in props:self.keys.append(props["data-stay"])
        if tag in ("input","select"):
            self.controls.append((tag,props.copy(),list(self.stack)))
        if tag=="button":self.buttons.append(props)
    def handle_endtag(self,tag):
        if tag in self.stack:
            self.stack=self.stack[:len(self.stack)-1-self.stack[::-1].index(tag)]
p=UIContract();p.feed(html)
assert len(p.keys)==len(set(p.keys)),"Duplicate DOM selectors"
required={"property-results","unit-results","property-search","property-filters","property-status",
    "property-page","unit-page","unit-type","preview","preview-status","preview-result",
    "rate-results","rate-count","board","checkin","nights","adults","children",
    "edit-links","rate-index","selection","price-source","price-value","price-meal"}
assert required.issubset(p.keys),sorted(required-set(p.keys))
references=set(re.findall(r"q\('([a-z][a-z-]+)'\)",js))
assert references.issubset(set(p.keys)),sorted(references-set(p.keys))
assert all("label" in path for _,_,path in p.controls), "Every field needs visible label"
assert all(button.get("type")=="button" for button in p.buttons),"Buttons must avoid native JetEngine form submit"
assert html.count('aria-live="polite"')>=4
assert 'class="mad4b-stay-panel__accordion" open' in html
assert 'window.MAD4BStayPricingUI' in module
assert "mad4b_stay_pricing_controls_v1" in module
assert "check_ajax_referer" in module and "wp_ajax_" in module
assert "dynamic-pricing-controls" in module
assert "mad4b_stay_layered_price_preview_v1" in module
assert not re.search(r"\b(update_post_meta|wp_insert_post|wp_delete_post|wp_update_post)\s*\(",module)
assert "panel.innerHTML=cfg.template" in js
assert "textContent" in js and "AbortController" in js and "credentials:'same-origin'" in js
assert "wp_send_json_success" in module
assert "MAD4B_Stay_Pricing_Controls_Panel_V1" in module
assert ".mad4b-stay-panel" in css and "@media(max-width:600px)" in css
assert "if($page!=='mad4b-pricing-workspace')return;" in admin, "No duplicate legacy notice in native pricing page"
print(f"PASS: {len(p.keys)} unique DOM bindings; {len(p.controls)} labeled fields; "
      f"{len(p.buttons)} non-submit controls; {len(references)} JS selectors; responsive and permission boundaries")
