#!/usr/bin/env python3
"""Multi-file original CSS graph integrity for advisory template observations."""
from pathlib import Path
import sys,importlib.util,tempfile
D=Path(sys.argv[1])
sp=importlib.util.spec_from_file_location('mad4b_template_full_source_test',D/'public-template-variant-index.py')
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
checks=0
def ok(value,label):
 global checks
 assert value,label
 checks+=1;print('PASS',label)
with tempfile.TemporaryDirectory() as tmp:
 root=Path(tmp)/'wp-content'
 for name in ['litespeed/css','plugins/elementor/assets/css','themes/child']:
  (root/name).mkdir(parents=True)
 a=root/'litespeed/css/a1.css'
 b=root/'plugins/elementor/assets/css/frontend.css'
 c=root/'themes/child/style.css'
 a.write_text('body{color:black}')
 b.write_text('.elementor-101{display:block}')
 c.write_text('h1{margin:0}')
 route={'id':19,'type':'page','language':'en','path':'/example/'}
 link1='<link rel="stylesheet" href="/wp-content/plugins/elementor/assets/css/frontend.css">'
 link2='<link rel="stylesheet" href="/wp-content/themes/child/style.css">'
 html=('<!doctype html><html><head>'
       '<link rel="stylesheet" href="https://wayfatours.com/wp-content/litespeed/css/a1.css">'
       '<style>.before{color:purple}</style>'+link1+link2+
       '</head><body class="page page-id-19"><div class="elementor-101"><h1>Source</h1>'
       +('content words '*1560)+'</div></body></html>')
 current=m.extract('wayfa',route,(html,'USD','base'),root)
 ok(current is not None,'original HTML with three local styles admitted')
 ok(current['css_files_observed']==3,'all CSS sources counted')
 ok(current['css_cascade_slots_observed']==2,'inline cascade boundary included')
 ok(current['css_source_contract']==m.ordered.CONTRACT,'first-party manifest contract retained')
 actual=m.ordered.inspect(html,'wayfatours.com',root)
 ok(actual['manifest_sha256']==current['css_sha'],'hash equals whole ordered source')
 swapped=html.replace(link1,'PLACEHOLDER',1).replace(link2,link1,1).replace('PLACEHOLDER',link2,1)
 alternate=m.extract('wayfa',route,(swapped,'USD','base'),root)
 ok(alternate and alternate['css_sha']!=current['css_sha'],'source order changes fingerprint')
 b.write_text('.elementor-101{display:none}')
 altered=m.extract('wayfa',route,(html,'USD','base'),root)
 ok(altered and altered['css_sha']!=current['css_sha'],'non-LiteSpeed CSS bytes affect fingerprint')
 remote=html.replace('</head>','<link rel="stylesheet" href="https://example.org/x.css"></head>')
 ok(m.extract('wayfa',route,(remote,'USD','base'),root) is None,'external stylesheet fails closed')
 rendered=html.replace('</head>','<style data-mad4b-ccss-v2-critical="1">x</style></head>')
 ok(m.extract('wayfa',route,(rendered,'USD','base'),root) is None,
    'already transformed HTML refused as original')
 c.unlink()
 ok(m.extract('wayfa',route,(html,'USD','base'),root) is None,'missing file fails closed')
print(f'PASS {checks}/{checks} original ordered CSS source graph invariants')
