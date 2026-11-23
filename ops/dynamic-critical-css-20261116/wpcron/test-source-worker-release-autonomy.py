#!/usr/bin/env python3
"""One immutable Source/Wayfa worker for ordinary post-release CSS certification."""
from pathlib import Path
import ast,sys,hashlib
D=Path(sys.argv[1]);p=D/'wpcron/site-owned-css-worker-v2.py';s=p.read_text()
ast.parse(s)
checks=0
def ok(q,message):
 global checks
 assert q,message
 checks+=1;print('PASS',message)
ok("if site in ('source','wayfa') and job=='public':" in s,
   'Source and Wayfa independently start release-first under authorized owner')
ok("MAD4B_MULTI_CSS_V2_RELEASE_FIRST':'1'" in s and
   "MAD4B_MULTI_CSS_V2_RELEASE_APPROVED':'1'" in s,
   'routine source-owned release is automatic without repeat Git approval')
ok('result=run(command,timeout=timeout or JOB_TIMEOUT[job],env=release_env)' in s,
   'only selected public task receives source-bound release mode')
ok("'public':1250" in s,
   'Source has a full but bounded multi-width Chromium post-release window')
ok("'source':" in s and "if site=='source' and environment!='staging'" in s
   and "if site=='wayfa' and environment!='production'" in s,
   'first-party environments and company site profile remain isolated')
ok('renderer_digest(SOURCE)!=expected' in s and "site_renderer_source_drift" in s,
   'site worker cannot execute foreign or mutable renderer')
ok('WPCSS_NONFAILURE_RESOURCE_RETRY' in s
   and 'WPCSS_CPU_QUEUE_PRESERVED' in s,
   'CPU deferrals keep original WordPress ticket')
ok('observe_source_only_variant_progress' in s and
   'LIGHT_VARIANTS_FROM_WP_SNAPSHOT ' in s,
   'Source template discovery continues cheaply during reserved WP-CLI slots')
ok('WPCSS_WORDPRESS_ACK' in s and 'verified_task_outcome' in s,
   'tickets are acknowledged only after original validated source result')
ok("'first_fold':lambda s:" in s and
   "'MAD4B_SOURCE_CSS_NETWORK_PROFILE=host'" in s,
   'Source Home first-fold system remains a separate company-owned workflow')
print('PASS',checks,'normalized first-party Source/Wayfa worker autonomy tests')
