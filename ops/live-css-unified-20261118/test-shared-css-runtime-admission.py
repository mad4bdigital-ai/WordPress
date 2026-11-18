#!/usr/bin/env python3
"""Hermetic integration check for original registry/runner company release gate."""
import importlib.util,pathlib,hashlib
d=pathlib.Path(__file__).resolve().parents[1]/'dynamic-critical-css-20261116'
spec=importlib.util.spec_from_file_location('ccss_native',d/'run-public-route.py')
r=importlib.util.module_from_spec(spec);spec.loader.exec_module(r)
def check(x,label):
 if not x:raise AssertionError(label)
 print('PASS',label)
for site in ('source','wayfa'):
 root=r.CONFIG[site][2]
 runtime=root/'mu-plugins/422-mad4b-public-critical-css-runtime.php'
 sha=r.approved_native_runtime_sha(site,root)
 check(sha==hashlib.sha256(runtime.read_bytes()).hexdigest(),f'{site} exact native runtime admitted')
check(r.approved_native_runtime_sha('source',r.CONFIG['source'][2])!=r.approved_native_runtime_sha('wayfa',r.CONFIG['wayfa'][2]),'Source staged and Wayfa live releases remain independently pinned')
for site,wrongroot in [('source',r.CONFIG['wayfa'][2]),('wayfa',r.CONFIG['source'][2]),('untrusted',r.CONFIG['source'][2])]:
 try:r.approved_native_runtime_sha(site,wrongroot)
 except ValueError:pass
 else:raise AssertionError('foreign company/root silently admitted')
 print('PASS original native runtime rejects wrong company/root',site)
source=(d/'refresh-source-families.py').read_text()
check("runner.approved_native_runtime_sha(site,wp)" in source,'read-only source registry reuses identical approved verifier')
check("state['runtime_sha']=verified_runtime_sha" in (d/'run-public-route.py').read_text(),'live worker uses immutable exact verifier result')
print('TOTAL 8/8')
