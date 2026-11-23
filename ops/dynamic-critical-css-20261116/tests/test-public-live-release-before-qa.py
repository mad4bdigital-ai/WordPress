#!/usr/bin/env python3
"""Fail-closed original release→QA ordering; no runtime publication from tests."""
import ast,pathlib,sys
p=pathlib.Path(sys.argv[1])
s=(p/'run-public-route.py').read_text()
script=ast.parse(s)
def require(label,assertion):
 assert assertion,label
 print('PASS',label)
release="print(run(release_args+['release'],timeout=35))"
qa="browser(route,wp,host,env,'verify-native-ordered-critical.js'"
verify="print(run(release_args+['verify'],timeout=40))"
rollback="print(run(release_args+['revert'],timeout=30))"
revoke="print(run(release_args+['revoke' if release_promoted else 'revert'],"
require('actual WordPress provisional certificate published before any post-release performance QA',
        s.count(release)==1 and 0<s.index(release)<s.index(qa))
require('post-release QA promotes published exact route via built-in CAS verifier',
        s.count(verify)==1 and s.index(qa)<s.index(verify))
require('failed 5-width browser QA reverts provisional certificate',
        s.count(rollback)==1 and 0<s.index(qa)<s.index(rollback)<s.index(verify))
require('unexpected browser/source failure fails closed with proper rollback',
        s.count(revoke)==1 and s.index(verify)<s.index(revoke))
require('no hidden private-stage QA-first consumer step exists',
        "release_args+['stage']" not in s and "release_args+['promote_staged']" not in s)
require('old per-site CSS/private FastCGI options not broadly purged',
        'PUBLIC_CCSS_SELECTIVE_CACHE_RELEASE' not in s and "'before_capture',site,str(wp),str(catfile)" in s)
require('provisional cache rotated immediately before browser QA',
        s.index("'v2-provisional-route-cache-refresh.json'")<s.index(qa))
require('passed QA independently refreshes qualified native route cache',
        s.index("'v2-qualified-cache-refresh.json'")>s.index(verify))
require('failed QA also rotates exact single-route cache generation',
        "'v2-failed-postqa-route-cache-refresh.json'" in s
        and "'v2-reverted-cache-refresh.json'" in s)
require('all original publication/market/runtime owner approvals remain',
        all(x in s for x in ('MAD4B_MULTI_CSS_V2_RELEASE_APPROVED','MULTICSS_SOURCE_CHANGED_BEFORE_RELEASE',
                            'approved_native_runtime_sha','approved_v2_consumer_sha',
                            'public-source-stability-before-release.py')))
require('original security fallback retained on stale assets and source drift',
        "record('hold','capture_error')" in s and 'POST_RELEASE_WP_SOURCE_REVISION_CHANGED' in s)
require('no global CSS QA acceptance threshold changed',
        'public-ordered-critical-admission.py' in s and 'verify-ordered-css.js' in s)
require('same output state machine keeps protected original readback',
        'public-v2-existing-readback.py' in s and "record('certified'" in s)
print('PASS 13/13 original public release-first state-machine invariants')
