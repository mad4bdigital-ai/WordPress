#!/usr/bin/env python3
from pathlib import Path
import importlib.util
path=Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116/run-public-route.py'
sp=importlib.util.spec_from_file_location('native_css_runner',path)
m=importlib.util.module_from_spec(sp);sp.loader.exec_module(m)
def proof(condition,name):
    assert condition,name
    print('PASS',name)
for name in ['capture.js','verify.js','probe-public.js']:
    opts=m.approved_browser_network('tours.dev.mad4b.com','staging',name)
    expected=['--network=host','--cap-drop=ALL']
    if name=='capture.js':expected+=['--group-add=33']
    proof(opts==expected,f'source stage {name} measures public host network with no container caps')
    if name=='capture.js':
        proof(opts.count('--group-add=33')==1,'only CSS capture is allowed www-data read-only group')
proof(m.approved_browser_network('tours.dev.mad4b.com','staging','assemble.js')==[],'CSS assembly remains offline on original network')
for n in ['capture.js','verify.js','probe-public.js','assemble.js']:
    proof(m.approved_browser_network('wayfatours.com','production',n)==[],f'Wayfa native {n} transport unchanged')
for site,environment in [('tours.dev.mad4b.com','production'),('wayfatours.com','staging'),('other.site','staging')]:
    try: m.approved_browser_network(site,environment,'capture.js')
    except ValueError:pass
    else:raise AssertionError('invalid site/environment must fail closed')
    print('PASS unknown or incorrect site/environment rejected',site,environment)
