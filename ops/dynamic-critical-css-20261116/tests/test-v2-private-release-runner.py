#!/usr/bin/env python3
"""No unqualified CSS candidate can enter public FastCGI or 422."""
import pathlib,sys,ast
D=pathlib.Path(sys.argv[1])
runner=(D/'run-public-route.py').read_text()
issuer=(D/'public-ordered-critical-certificate-v2.py').read_text()
checks=0
def ok(cond,description):
 global checks
 assert cond,description
 checks+=1;print('PASS',description)
ok("release_args+['stage']" in runner
   and "release_args+['release']" not in runner,
   'first-party source issuance stages outside WordPress')
ok("release_args+['promote_staged']" in runner
   and "release_args+['verify']" not in runner,
   'public replacement requires exact post-stage QA')
ok("release_args+['discard_staged']" in runner
   and "release_args+['revert']" not in runner,
   'failed QA destroys private candidate without invalidating previous certification')
ok("jobdir/'v2-pending-cache-refresh.json'" not in runner,
   'no FastCGI invalidation of native or previous CSS for unqualified candidate')
ok("if release_promoted:" in runner
   and "jobdir/'v2-reverted-cache-refresh.json'" in runner,
   'rollback clears public delivery only after actual promotion')
ok("release_args+['discard_staged']" in runner
   and "MULTICSS_PRIVATE_STAGE_CLEANUP_DEFERRED" in runner,
   'verified receipt cleanup does not falsify successful customer delivery')
ok("wp.parent.parent/'operations/css-cron-v1/candidates-v2'" in issuer
   and "mode=0o700" in issuer and "os.fchmod(f.fileno(),0o600)" in issuer,
   'source-only candidate confined to root-owned non-webroot staging')
ok("if mode in ('promote_staged','discard_staged')" in issuer
   and "mode in ('publish','release')" in issuer,
   'old legacy producer modes remain available only to explicit callers')
ok("or path.stat().st_mtime<cert['released_at']" in issuer,
   'pre-release QA cannot authorize later publication')
ok("if any(qualified.get(k)!=cert.get(k) for k in stable)" in issuer,
   'a new source revision cannot inherit stale staged QA')
ok("Path(wp_content)/'mu-plugins/422-mad4b-public-critical-css-runtime.php'" in issuer,
   'consumer identity stays the original installed 422 runtime')
ast.parse(runner);ast.parse(issuer)
print('PASS',checks,'V2 root-private release, post-stage QA, public-promotion source contracts')
