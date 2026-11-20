#!/usr/bin/env python3
"""Pure read-only CSS source/tenant compatibility & exact artifact audit."""
import json,hashlib,sys,argparse,re
from pathlib import Path
p=argparse.ArgumentParser()
p.add_argument('--source',type=Path,required=True)
p.add_argument('--wayfa-before',type=Path,required=True)
p.add_argument('--site-content',type=Path,required=True)
p.add_argument('--qa-dir',type=Path,required=True)
p.add_argument('--expected-before-sha',required=True)
args=p.parse_args()
source=args.source.read_bytes();old=args.wayfa_before.read_bytes()
assert hashlib.sha256(old).hexdigest()==args.expected_before_sha
assert source.startswith(b'<?php') and b' * Version: 0.3.26' in source
assert b' * Version: 0.3.22' in old
x=source.decode()
y=old.decode()
assert x.count('final class MAD4B_Verified_Critical_CSS_V1')==1
assert y.count('final class MAD4B_Verified_Critical_CSS_V1')==1
assert 'private static function verified_variant(' in x and 'private static function verified_variant(' not in y
assert "in_array($decoded['version']??null,[1,2],true)" in x
assert "($manifest['contract']??'')!=='mad4b.mobile-critical-css.v2'" in x
assert 'elementor_doc_fingerprint' in x and 'css_epoch' in x
assert "($meta['css_full_preload']??false)===true" in x
assert "in_array($priority,['low','auto','high'],true)" in x
assert "($variant['page_id']??0)" in x and "get_queried_object_id()" in x
# Guard structural tenant behavior: unrelated Predictive CSS engine remains
# at exactly one owner in both. The newer source is an additive compatibility
# successor, not a second duplicate CSS engine.
assert x.count('final class MAD4B_Predictive_CSS_Plan_V2')==1
assert y.count('final class MAD4B_Predictive_CSS_Plan_V2')==1
data=json.loads((args.qa_dir/'verification.json').read_text())
cands=json.loads((args.qa_dir/'candidates.json').read_text())
assert cands['host']=='wayfatours.com' and data['tested']==8
assert all(r['pass'] for r in data['results'])
assert {v['decision'] for v in data['gates'].values()}=={'passed_evidence'}
for item in cands['candidates']:
    path=args.site_content/'litespeed/css'/item['css_file']
    asset=args.qa_dir/item['critical_file']
    assert hashlib.sha256(path.read_bytes()).hexdigest()==item['source_sha']
    assert hashlib.sha256(asset.read_bytes()).hexdigest()==item['critical_sha']
print(json.dumps({
 'state':'candidate_test_passed',
 'source_sha256':hashlib.sha256(source).hexdigest(),
 'before_sha256':hashlib.sha256(old).hexdigest(),
 'critical_qa_samples':data['tested'],
 'critical_qa_locales':sorted(data['gates'].keys()),
 'signed_release_granted':False,
 'runtime_changed':False
},sort_keys=True))
