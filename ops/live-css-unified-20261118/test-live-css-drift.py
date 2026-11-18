#!/usr/bin/env python3
"""Pure source-backed QA for live CSS asset drift; no remote requests."""
from __future__ import annotations
import importlib.util
import hashlib
import pathlib
import tempfile
d=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
spec=importlib.util.spec_from_file_location('auditor',d/'public-route-live-css-drift.py')
a=importlib.util.module_from_spec(spec);spec.loader.exec_module(a)
def check(pred,name):
 if not pred:raise AssertionError(name)
 print('PASS',name)
host='wayfatours.com'
name='a'*32+'.css'
markup='<html><head><link rel="stylesheet" href="https://wayfatours.com/wp-content/litespeed/css/'+name+'?ver=1"><noscript><link rel="stylesheet" href="/wp-content/litespeed/css/'+name+'"></noscript><link rel="stylesheet" href="https://evil.example/wp-content/litespeed/css/'+'f'*32+'.css"></head><body>tour</body></html>'
check(a.bundle_filenames(markup,host)==[name],'native CSS parser deduplicates noscript and rejects foreign origin')
check(a.bundle_filenames('<html><body>No head</body></html>',host)==[],'no mixed markup source')
with tempfile.TemporaryDirectory() as temp:
 root=pathlib.Path(temp); css=root/'litespeed/css';css.mkdir(parents=True)
 payload=b'body{color:navy;}'*1100
 (css/name).write_bytes(payload)
 sha=hashlib.sha256(payload).hexdigest()
 cert={'css_file':name,'css_sha':sha,'currency':'USD','market_country':'base'}
 check(a.compare(cert,markup,'USD','base',root,host) is None,'same original CSS content does not requalify')
 check(a.compare({**cert,'css_sha':'0'*64},markup,'USD','base',root,host)['css_file']==name,'same file name with different CSS bytes is real drift')
 check(a.compare(cert,markup,'EUR','base',root,host) is None,'currency mismatch cannot authorize drift')
 check(a.compare(cert,markup,'USD','DE',root,host) is None,'market mismatch cannot authorize drift')
 check(a.compare({**cert,'css_file':'f'*32+'.css'},markup,'USD','base',root,host)['css_sha']==sha,'different original bundle is drift even when bytes same')
 check(a.compare(cert,markup.replace(name,'../../../traversal.css'),'USD','base',root,host) is None,'path traversal not accepted')
ledger={'routes':{'route-1':{'status':'certified','source_key':'original','attempted_at':1}}}
proof={'route_key':'route-1','css_sha':'1'*64}
copied=a.select_verified_repair(ledger,proof)
check(copied['routes']['route-1']['source_key']!=ledger['routes']['route-1']['source_key'],'only temporary selection ledger changes')
check(ledger['routes']['route-1']['status']=='certified','original certified ledger preserved pending real QA')
try:a.select_verified_repair({'routes':{'route-1':{'status':'hold'}}},proof)
except ValueError:pass
else:raise AssertionError('non-certified repair must fail')
print('PASS noncertified/foreign queue repair rejected')
