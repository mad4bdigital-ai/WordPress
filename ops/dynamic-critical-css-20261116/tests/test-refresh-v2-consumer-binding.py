#!/usr/bin/env python3
"""Original read-only family refresh must use the same SHA-approved consumer as route QA."""
import importlib.util,pathlib,sys
from pathlib import Path
root=Path(sys.argv[1])
spec=importlib.util.spec_from_file_location('company_refresh_v2',root/'refresh-source-families.py')
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
runtime='a'*64;consumer='b'*64;calls=[]
class Runner:
    def approved_v2_consumer_sha(self,site,wp,received_runtime):
        calls.append((site,str(wp),received_runtime))
        assert site=='wayfa' and received_runtime==runtime
        return consumer
runner=Runner()
data={'runtime_sha':runtime,'qa_policy_sha':'c'*64}
result=m.bind_original_approved_v2_consumer(runner,'wayfa',Path('/tmp/fake-read-only-root'),data)
assert result['consumer_v2_sha']==consumer
print('PASS approved original V2 companion SHA added to exact family state')
assert len(calls)==1 and calls[0][2]==runtime
print('PASS original runner is the sole market- and site-attested consumer identity authority')
class OldRuntime:
    def approved_v2_consumer_sha(self,site,wp,received_runtime):return None
r=m.bind_original_approved_v2_consumer(OldRuntime(),'wayfa',Path('/tmp/nowhere'),{'runtime_sha':runtime,'consumer_v2_sha':consumer})
assert 'consumer_v2_sha' not in r
print('PASS old/disallowed runtime never inherits a stale published consumer')
class Untrusted:
    def approved_v2_consumer_sha(self,site,wp,received_runtime):return 'not-a-hash'
try:m.bind_original_approved_v2_consumer(Untrusted(),'wayfa',Path('/tmp/nowhere'),{'runtime_sha':runtime})
except ValueError:pass
else:raise AssertionError('malformed companion SHA accepted')
print('PASS malformed companion readback fails closed')
for invalid in (None,'no',12,'f'*63,'x'*64):
 try:m.bind_original_approved_v2_consumer(runner,'wayfa',Path('/tmp/nowhere'),{'runtime_sha':invalid})
 except ValueError:pass
 else:raise AssertionError('unattested runtime accepted')
print('PASS missing/untrusted source runtime is rejected before any consumer admission')
source=(root/'refresh-source-families.py').read_text()
assert source.index('state=runner.obs(')<source.index('state=bind_original_approved_v2_consumer(')<source.index('catalog=runner.catalog(')
print('PASS original WordPress source observer state is bound before family catalog creation')
assert source.count('state=bind_original_approved_v2_consumer(runner,site,wp,state)')==1
assert 'queue.record(' not in source and 'public-route-certificate.py' not in source
print('PASS read-only family refresh cannot issue a certificate or alter queue status')
print('PASS 7/7 first-party family SHA-binding invariants')
