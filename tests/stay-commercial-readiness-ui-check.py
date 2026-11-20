#!/usr/bin/env python3
"""Static safety and UI contract for native stay readiness panel."""
from html.parser import HTMLParser
from pathlib import Path
import re
root=Path(__file__).resolve().parents[1]
plugin=root/"wp-content/mu-plugins/020-mad4b-stay-commercial-readiness.php"
base=root/"wp-content/mu-plugins/mad4b-stay-readiness"
html=(base/"panel.html").read_text()
js=(base/"admin.js").read_text()
css=(base/"admin.css").read_text()
php=plugin.read_text()
class Layout(HTMLParser):
    def __init__(self):
        super().__init__();self.bindings=[];self.buttons=[];self.selects=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs)
        if "data-ready" in a:self.bindings.append(a["data-ready"])
        if tag=="button":self.buttons.append(a)
        if tag in ("input","select"):self.selects.append(a)
p=Layout();p.feed(html)
assert len(p.bindings)==len(set(p.bindings)), "duplicate readiness binding"
assert set(re.findall(r"el\('([a-z-]+)'\)",js)).issubset(set(p.bindings)), "unknown UI binding"
assert all(x.get("type")=="button" for x in p.buttons), "unexpected form submit"
assert 'aria-live="polite"' in html and "<label>" in html, "accessibility roles"
assert "AbortController" in js and "textContent" in js, "cancelability and safe text"
assert "Check native shadow quote" in js and "mode:" in js and "handoff" in js, "native handoff wiring"
assert "window.MAD4BStayReadiness" in php and "check_ajax_referer" in php
assert "'wp_ajax_'.self::ACTION" in php and "wp_ajax_nopriv" not in php
assert "public static function handoff" in php and "abk_accommodation_shadow_quote" in php
assert "checkout_ready'=>false" in php and "mutation_performed'=>false" in php
assert not re.search(r"\b(wp_update_post|wp_insert_post|update_post_meta|delete_post_meta|wp_delete_post|wc_create_order)\s*\(",php), "unexpected mutation"
assert 'value="relaxed_images"' in html
assert "relaxed_visual_exact_room_unverified" in js
assert "Visual-only photos" in js
assert "shared_official_asset_across_room_categories" in js
assert "partner_nearest_room_with_pool_view" not in js or "Partner hotel room photo" in js
assert "'relaxed_images'" in php and "'relaxed_visual_coverage'" in php
assert "'unit_media_quality_status'" in php
assert "'checkout_ready'=>false" in php
assert ".mad4b-stay-readiness" in css and "@media(max-width:580px)" in css
print(f"PASS readiness UI: {len(p.bindings)} unique data bindings, {len(p.buttons)} safe buttons, native API and no-write policy")
