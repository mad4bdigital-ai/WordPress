#!/usr/bin/env python3
"""Hermetic regression: CSSOM-based midband candidates are hash-bound and QA-only."""
from pathlib import Path
import hashlib
import importlib.util
import json
import tempfile

SCRIPT = Path(__file__).resolve().parents[1] / 'tools/derive_verified_midband_css.py'
spec=importlib.util.spec_from_file_location('mad4b_midband_generator',SCRIPT)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

checks=0

def sha(data):return hashlib.sha256(data).hexdigest()

def expected_error(fn):
    global checks
    checks+=1
    try:fn()
    except (ValueError,KeyError,TypeError):pass
    else:raise AssertionError('Failed closed contract: negative scenario accepted')

with tempfile.TemporaryDirectory(prefix='mad4b-midband-') as temp:
    root=Path(temp);src=root/'native.css';old=root/'verified.css';probe=root/'cssom.json'
    src.write_text('.a{display:block}.b{color:#000}')
    old.write_text('body{color:#000}'*1400)
    data={'width':820,'criticalRules':[{'css':'.a { display: block; }','parents':[],'selector':'.a'}],
          'rules':[{'css':'.a { display: block; }','parents':[],'selector':'.a'},
                   {'css':'.b { color: rgb(0, 0, 0); }','parents':[],'selector':'.b'}]}
    probe.write_text(json.dumps(data));source_sha=sha(src.read_bytes());critical_sha=sha(old.read_bytes())
    def build(**kwargs):return module.build(src,source_sha,old,critical_sha,probe,768,991,kwargs.get('reserve',{}))
    payload,receipt=build()
    checks+=1
    assert receipt['status']=='QA_ONLY__REQUIRES_INDEPENDENT_ADMISSION' and not receipt['original313_signed']
    assert receipt['additional_rules']==1 and b'@media (min-width:768px) and (max-width:991px)' in payload
    checks+=1
    assert payload.startswith(old.read_bytes()) and sha(payload)==receipt['candidate_sha256']
    reserve={'selector':'.elementor-element-example','min_width':900,'max_width':991,
             'observed_final_height':47.3125,'reserved_height':48}
    payload2,rc=build(reserve=reserve)
    checks+=1
    assert b'@media (min-width:900px) and (max-width:991px)' in payload2 and rc['reservations_approved_for_qa']
    expected_error(lambda:module.build(src,'0'*64,old,critical_sha,probe,768,991,{}))
    expected_error(lambda:module.build(src,source_sha,old,'0'*64,probe,768,991,{}))
    expected_error(lambda:module.build(src,source_sha,old,critical_sha,probe,991,768,{}))
    expected_error(lambda:module.build(src,source_sha,old,critical_sha,probe,900,991,{}))
    expected_error(lambda:build(reserve={**reserve,'selector':'a{}<script>'}))
    expected_error(lambda:build(reserve={**reserve,'reserved_height':500}))
    data['rules'][1]['css']='.b { background:url(https://example.com/other.webp); }'
    probe.write_text(json.dumps(data))
    expected_error(lambda:build())
    data['rules'][1]['css']='.b { color: rgb(0, 0, 0); }'
    data['rules'][1]['parents']=['@media (min-width:1200px)']
    probe.write_text(json.dumps(data))
    checks+=1
    assert build()[1]['additional_rules']==1
print(f'PASS {checks}/{checks} hermetic midband CSS build and drift tests')
