#!/usr/bin/env python3
import importlib.util
from importlib.machinery import SourceFileLoader
from pathlib import Path
p=Path(__file__).parent/'css-wpcron-worker.py'
spec=importlib.util.spec_from_loader('original_css_wpcron',SourceFileLoader('original_css_wpcron',str(p)))
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
cases=[
 ('wayfa','public',0,'SKIP_PUBLIC_CCSS_BROWSER_BUSY wayfa',False),
 ('wayfa','public',0,'DEFER_PUBLIC_CCSS_RESOURCE_WINDOW wayfa DEFER_PUBLIC_CCSS_HOST_CPU_BUDGET central_wpcli_slots_reserved',False),
 ('source','public',0,'DEFER_PUBLIC_CCSS_RESOURCE_SENSOR_UNAVAILABLE source',False),
 ('source','public',0,'HOLD_PUBLIC_CCSS_HIGH_LOAD 8.4',False),
 ('source','families',0,'DEFER_SOURCE_FAMILY_REFRESH DEFER_PUBLIC_CCSS_HOST_CPU_BUDGET central_wpcli_slots_reserved\nLIGHT_VARIANTS_FROM_WP_SNAPSHOT source attempted 1',False),
 ('wayfa','public',0,'SKIP_PUBLIC_CCSS_ALREADY_RUNNING',False),
 ('wayfa','public',0,'HOLD_PUBLIC_CCSS wayfa en /foo/',False),
 ('wayfa','public',0,'PUBLIC_CCSS_QUEUE_RECORDED en /foo/ hold 1',False),
 ('source','public',0,'CERTIFIED_PUBLIC_CCSS source de /de/foo/ MARKET_CURRENCY_BOUND',True),
 ('source','public',0,'CERTIFIED_PUBLIC_CCSS_REUSED source en /foo/ base USD',True),
 ('wayfa','public',0,'READY_PUBLIC_CCSS_SOURCE_FAMILIES wayfa NO_UNPROVEN_TEMPLATE_CANARY_ELIGIBLE_NATIVE_PAGE_FALLBACKS_REMAIN',True),
 ('source','public',0,'READY_PUBLIC_CCSS_QUEUE_EMPTY source 0',True),
 ('source','public',0,'NATIVE_CSS_NONBENEFIT_MEASURED source en /foo/ FULL_NATIVE_CSS_KEPT_PENDING_INDEPENDENT_CONFIRMATION',True),
 ('wayfa','first_fold',0,'SKIP wayfa shared browser busy; retry during next timer',False),
 ('source','first_fold',0,
  "READY source all current public home CSS native-certified ['en']\n"
  'HOLD_SOURCE_HOME_DELIVERY_ADMISSION unmatched_source_css_or_elementor_graph\n'
  'HOLD source actual mobile CSS source/Elementor graph not yet fully certified\n'
  'CRITICAL_CSS_EVENT_LEDGER_COMMITTED source pending aabbccdd',False),
 ('source','first_fold',0,
  "READY source all current public home CSS native-certified ['en']\n"
  'CRITICAL_CSS_EVENT_LEDGER_COMMITTED source pending aabbccdd',False),
 ('source','first_fold',0,
  "READY source all current public home CSS native-certified ['en']\n"
  'CRITICAL_CSS_EVENT_LEDGER_COMMITTED source ready aabbccdd',True),
 ('source','first_fold',0,'HOLD source CSS epoch not quiet; retry on next recurring run',False),
 ('source','first_fold',0,"READY source all current public home CSS native-certified ['en']",True),
 ('wayfa','first_fold',0,'SKIP wayfa unchanged Critical CSS sources; no Chromium',True),
 ('source','families',0,'SOURCE_FAMILY_REGISTRY_REFRESH source routes 1022 families 61',False),
 ('source','families',0,'SOURCE_FAMILY_REGISTRY_REFRESH source routes 1022\nSOURCE_FAMILY_REGISTRY_REFRESH wayfa routes 239',True),
 ('source','visual',0,'POST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION',False),
 ('source','visual',0,'POST_POLICY_VISUAL_OBSERVED source /a/ alerts 0\nPOST_POLICY_VISUAL_OBSERVED wayfa /b/ alerts 0\nPOST_POLICY_VISUAL_MONITOR_NO_CERTIFICATE_OR_POLICY_MUTATION',True),
 ('source','audit',0,'PUBLIC_CCSS_INTEGRITY {"sites":{"source":{"status":"deferred"},"wayfa":{"status":"healthy"}}}',False),
 ('source','audit',0,'PUBLIC_CCSS_INTEGRITY {"sites":{"source":{"status":"healthy"},"wayfa":{"status":"degraded"}}}',True),
 ('source','retention',0,'MAD4B_CCSS_EVIDENCE_RETENTION {"mode":"dry_run","eligible_count":0}',False),
 ('source','retention',0,'MAD4B_CCSS_EVIDENCE_RETENTION {"mode":"apply","eligible_count":0}',True),
 ('source','public',4,'CERTIFIED_PUBLIC_CCSS source en /foo/',False),
]
for i,(site,task,code,stdout,want) in enumerate(cases,1):
    got,reason=mod.verified_task_outcome(site,task,code,stdout,'')
    assert got is want,(i,task,stdout,got,reason)
    if ('DEFER_PUBLIC_CCSS_RESOURCE_' in stdout or 'SKIP_PUBLIC_CCSS_BROWSER_BUSY' in stdout
        or 'HOLD_PUBLIC_CCSS_HIGH_LOAD' in stdout or 'DEFER_SOURCE_FAMILY_REFRESH' in stdout):
        assert reason=='host_resource_deferred',(site,task,reason)
    if stdout.startswith('HOLD_PUBLIC_CCSS wayfa'):
        assert reason=='public_source_or_browser_deferred'
    print('PASS',i,task,reason)
assert mod.retry_policy(3,'host_resource_deferred',1000)==(0,1300)
assert mod.retry_policy(3,'public_source_or_browser_deferred',1000)==(4,4600)
assert mod.retry_policy(0,'host_resource_deferred',1000)==(0,1300)
print('WPCRON_RESOURCE_BACKOFF_ADMISSION 3')
print('WPCRON_CSS_EXACT_OUTCOME',len(cases))
