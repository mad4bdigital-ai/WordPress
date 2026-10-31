#!/usr/bin/env python3
"""MAD4B diagnostic channel outcome aggregation for ORIGINAL Dispatcher.
No grant creation, SSH/WP-CLI execution, fallback selection, or authorization.
External ChatGPT/tool policy refusals are NOT observable by VPS.
"""
import argparse, datetime, json, os, re, stat, tempfile
from pathlib import Path
BASE=Path('/opt/mad4b-company-transport')
DEST=BASE/'channel-governance-v1/integration-status.json'
CONTRACT='mad4b.channel-governance-integration.v1'
SOURCES={
 'central_auto_authority':('central-auto-authority-v1/readiness.json','mad4b.central-auto-authority.v1'),
 'unified_router':('unified-execution-router-v1/status.json','mad4b.unified-execution-router.v1'),
 'cross_ssh_wpcli_identity':('dynamic-site-binding-v1/wpcli-identity-status.json','mad4b.wpcli-site-identity-discovery.v1'),
 'wpcli_scheduled_dispatch':('unified-execution-router-v1/scheduled-dispatch-status.json','mad4b.general-wpcli-scheduled-dispatch.v1'),
 'wpcli_grant_lifecycle':('unified-execution-router-v1/lifecycle-status.json','mad4b.wpcli-grant-lifecycle.v1'),
 'unified_lifecycle':('universal-runtime-v1/unified-lifecycle-status.json','mad4b.unified-lifecycle-orchestrator.v1'),
 'broker_native_ssh':('broker-v1/native-ssh-broker-reconcile.json','mad4b.broker-native-ssh-reconcile.v1'),
}
AUTH={'AUTHORITY_DENIED','ORIGINAL_AUTHORITY_DENIED','CENTRAL_GRANT_DENIED',
      'HOST_IDENTITY_MISMATCH','SCOPE_MISMATCH','SIGNED_ORIGINAL_HOST_REQUIRED',
      'CENTRAL_GRANT_SITE_SCOPE_MISMATCH'}
UNCERTAIN={'RECONCILIATION_REQUIRED','EXECUTION_EFFECT_UNCERTAIN',
           'CENTRAL_RECEIPT_RECONCILIATION_REQUIRED'}
TRANSPORT={'PROVIDER_UNAVAILABLE','PROVIDER_NOT_ENROLLED',
           'REMOTE_TRANSPORT_FAILED','TRANSPORT_UNAVAILABLE_PRE_DISPATCH'}
VALID=re.compile(r'^[A-Z][A-Z0-9_]{1,119}$')
def classify(code,attempted=False):
    if not isinstance(code,str) or not VALID.fullmatch(code):
        return {'category':'UNCLASSIFIED','original_authority_unchanged':True}
    if code=='TOOL_INVOCATION_BLOCKED':
        return {'category':'EXTERNAL_TOOL_POLICY','action':'REPORT_NO_CROSS_CHANNEL_RETRY'}
    if code in AUTH or code.startswith(('ORIGINAL_GRANT_','SECURITY_DENIAL_')):
        return {'category':'ORIGINAL_AUTHORITY_DENIAL','action':'STOP_NO_CROSS_CHANNEL_RETRY'}
    if code in UNCERTAIN or (attempted and code in TRANSPORT):
        return {'category':'EFFECT_UNCERTAIN','action':'ORIGINAL_RECEIPT_RECONCILE_NO_REPLAY'}
    if code in TRANSPORT:
        return {'category':'PRE_DISPATCH_TRANSPORT','action':'ORIGINAL_ROUTER_MAY_REPLAN_EXACT_SCOPE'}
    if code.startswith('WAITING_'):
        return {'category':'ORIGINAL_DEPENDENCY','action':'WAIT_FOR_ORIGINAL_SOURCE'}
    if code in ('READY','VERIFIED','COMPLETE','READBACK_VERIFIED'):
        return {'category':'REPORTED_STATE_ONLY','action':'REQUIRE_ORIGINAL_EXECUTION_READBACK'}
    return {'category':'OTHER_ORIGINAL_STATE','action':'PRESERVE_ORIGINAL_DECISION'}
def source(path,contract):
    p=BASE/path
    try:
        st=p.lstat()
        if not stat.S_ISREG(st.st_mode) or st.st_uid!=0 or st.st_mode&0o022 or st.st_size>250000:
            return {'snapshot':'UNTRUSTED_SOURCE'}
        d=json.loads(p.read_text())
        if not isinstance(d,dict) or d.get('contract')!=contract:
            return {'snapshot':'CONTRACT_MISMATCH'}
    except FileNotFoundError:return {'snapshot':'NOT_PRESENT'}
    except (OSError,ValueError,TypeError):return {'snapshot':'UNAVAILABLE'}
    states=[]
    def entry(scope,row):
        if not isinstance(row,dict):return
        value=row.get('reason') or row.get('state')
        if not isinstance(value,str):return
        states.append({'scope':str(scope)[:150],'code':value[:120],
            'execution_attempted':row.get('execution_attempted') is True,
            **classify(value,row.get('execution_attempted') is True)})
    entry('component',d)
    for key in ('site_results','routes','results','jobs'):
        rows=d.get(key) or {}
        if isinstance(rows,dict):
            for name,record in list(rows.items())[:75]:
                entry(name,record)
                if key=='routes' and isinstance(record,dict):
                    for lane in (record.get('routes') or [])[:5]:
                        if isinstance(lane,dict):entry(str(name)+':'+str(lane.get('lane')),{'state':lane.get('status')})
    return {'snapshot':'READABLE','contract':contract,
            'source_time':d.get('checked_at') or d.get('at'),
            'grants_created_reported':d.get('grants_created'),
            'production_mutation_reported':d.get('production_mutation'),
            'original_outcomes':states,
            'execution_proven_by_snapshot':False}
def report():
    return {'contract':CONTRACT,'generated_at':datetime.datetime.now(datetime.timezone.utc).isoformat(),
       'mode':'ORIGINAL_DISPATCHER_DIAGNOSTIC_ONLY',
       'tool_invocation_refusal':'NOT_VISIBLE_TO_VPS',
       'systemd_current_cycle':'NOT_PROVEN_BY_SNAPSHOTS',
       'grants_created_by_this_component':0,'wpcli_jobs_run_by_this_component':0,
       'production_mutations_by_this_component':0,
       'authority_overrides_by_this_component':0,'alternate_execution_channels_started':0,
       'sources':{name:source(*args) for name,args in SOURCES.items()}}
def save(d):
    st=DEST.parent.stat()
    if st.st_uid!=0 or st.st_mode&0o022 or DEST.is_symlink():
        raise RuntimeError('UNTRUSTED_OUTPUT_PATH')
    fd,tmp=tempfile.mkstemp(prefix='.channel-status-',dir=DEST.parent)
    try:
        with os.fdopen(fd,'w') as f:
            json.dump(d,f,sort_keys=True,indent=2);f.write('\n');f.flush();os.fsync(f.fileno())
        os.chmod(tmp,0o600);os.replace(tmp,DEST)
    finally:
        if os.path.exists(tmp):os.unlink(tmp)
if __name__=='__main__':
    a=argparse.ArgumentParser();a.add_argument('mode',choices=['read','reconcile'])
    args=a.parse_args();d=report()
    if args.mode=='reconcile':
        if os.geteuid()!=0:raise SystemExit('ORIGINAL_ROOT_EXECUTOR_REQUIRED')
        save(d)
    print(json.dumps(d,sort_keys=True))
