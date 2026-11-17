#!/usr/bin/env python3
"""Offline archive overlay ownership/parity. No WordPress, network, or DB dependency."""
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
get=lambda p:(ROOT/p).read_bytes()
view=Path('wp-content/plugins/mad4b-experience/src/presentation/assets/mad4b-mobile-scroll-action-viewport-v2.js')
legacy=Path('wp-content/plugins/mad4b-experience/src/presentation/assets/mad4b-mobile-scroll-action.js')
variant=Path('ops/site-company-overlays/wayfatours/site-plugin-variants/wp-content/plugins/mad4b-experience/src/presentation/assets/mad4b-mobile-scroll-action.js')
mu=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/mad4b-mobile-scroll-action.js')
dockcss=Path('wp-content/plugins/mad4b-experience/src/presentation/assets/mad4b-mobile-scroll-action.css')
mucss=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/mad4b-mobile-scroll-action.css')
phone=Path('wp-content/plugins/mad4b-experience/src/visitor/assets/mad4b-fluent-phone-brand-and-dial.css')
muphone=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/mad4b-fluent-phone-brand-and-dial.css')
lang=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/wayfa-mobile-lang-destinations.css')
checks=0
def ok(cond,why):
 global checks
 assert cond,why
 checks+=1
 print('PASS',why)
source=get(view)
ok(all(get(p)==source for p in [legacy,variant,mu]),'All three fallback and company mobile dock JS assets match active viewport v2')
for name in [b'MAD4BMobileDockReady',b'mad4b-mobile-dock-host',b'insertBefore(marker,node)',b'host.appendChild(node)',b'marker.parentNode.insertBefore(node,marker)']:
 ok(name in source,'Viewport mobile dock owns one portal and reversible original slot: '+name.decode())
ok(b'mad4b-mobile-dock-visual-shift' in source and b'removeProperty' in source,'No accumulating visual viewport shift')
dc=get(dockcss)
ok(dc==get(mucss),'Wayfatours MU mobile dock CSS equals the shared presentation owner')
ok(b'var(--mad4b-mobile-dock-height) + 8px' in dc,'Floating actions keep only a small 8px touch separation')
ok(b'padding-bottom:var(--mad4b-mobile-dock-clearance)' in dc,'No second 14px bottom padding reservation')
ok(b'calc(0px - var(--mad4b-mobile-dock-visual-shift))' not in dc,'No deprecated negative viewport shift')
ok(b'body.post-type-archive-tours-and-activities > .mad4b-mobile-dock-host' in dc,'Fixed archive dock layer is owned by one body host')
ok(b'body.post-type-archive-tours-and-activities:has(#menu-toggle-101' in dc,'Open mobile navigation suppresses floating actions')
pc=get(phone)
ok(pc==get(muphone),'Phone dropdown style is mirrored to Wayfatours MU owner')
ok(b'.ff-el-group:has(.iti__dropdown-content:not(.iti__hide))' in pc,'Fluent Form country list raises only its active form group')
ok(b'.iti__dropdown-content:not(.iti__hide)' in pc,'Phone dropdown stack is scoped to the open list')
ok(b'z-index:30;' in pc and b'z-index:32;' in pc,'Scoped country list z-index remains below site-wide header/nav priority')
lc=get(lang)
ok(b'wpml-ls.wayfa-mobile-lang-open > ul > li.wpml-ls-current-language > a' in lc,'Custom mobile language menu does not duplicate its current language')
ok(b'display:none!important;' in lc,'Current language is hidden only inside the opened custom list, not from its trigger')
module=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/portable-modules/344-mad4b-mobile-scroll-action.php')
asset=Path('ops/site-company-overlays/wayfatours/site-mu-mapped-variants/wp-content/mu-plugins/assets/mad4b-mobile-scroll-action-viewport-v2.js')
ok(get(asset)==get(view),'New browser-cache-busting MU viewport-v2 script is byte-for-byte canonical')
ok(b"? 'mad4b-mobile-scroll-action-viewport-v2.js'" in get(module),'MU frontend loader selects distinct v2 script URL')
ok(b"$list[]='mad4b-mobile-scroll-action-viewport-v2.js';" in get(module),'LiteSpeed optimizer excludes the new viewport-v2 script by filename')
print('PASS: %d source-only parity assertions'%checks)
