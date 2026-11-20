#!/usr/bin/env python3
"""Refresh passive Company Reconcile file inventories from enrolled bindings.
Does not request execution, create Jobs/Grants, or change tenant files.
"""
import argparse,contextlib,datetime,fcntl,importlib.util,json,os,pathlib,subprocess,sys
BASE=pathlib.Path('/opt/mad4b-company-provisioning')
BINDING_POLICY=BASE/'policies/company-fx-distribution-v1.json'
REGISTRY_ADAPTER=BASE/'bin/company-mode-price-policy-reconcile.py'
PUBLISHER=BASE/'bin/company-reconcile-publish-inventory-v1.py'
REPORT=BASE/'reports/company-reconcile-inventory-refresh-current.json'
TARGET_READBACK=BASE/'bin/company-plugin-target-readback-publish.py'
PACKAGE_PREFLIGHT=BASE/'bin/company-plugin-package-preflight.py'
PACKAGE_BUILDER=BASE/'bin/company-experience-portable-source-bundle.py'
LIVE_SEMANTIC=BASE/'bin/company-live-semantic-discovery-v1.py'
SEMANTIC_REPORT=BASE/'reports/company-live-semantic-reconciliation-current.json'
READINESS_REPORT=BASE/'reports/company-native-universal-readiness-current.json'
LIFECYCLE_REPORT=BASE/'reports/company-lifecycle-integrity-current.json'
SIGNED_ORIGINAL312_313_ADAPTER=BASE/'bin/company-reconcile-original312-313-overlay.py'
REPORT_LOCK=BASE/'reports/.company-reconcile-projection.lock'

def inventory_read_status(data, *, now=None):
    # Observation only. The Host cannot observe/refuse an external tool call
    # that was blocked before it reached the host.
    if now is None:
        now=datetime.datetime.now(datetime.timezone.utc)
    if (not isinstance(data,dict)
        or data.get('contract')!='mad4b.company-reconcile-inventory-refresh.v1'
        or data.get('writes_to_tenant') is not False
        or data.get('creates_jobs') is not False
        or data.get('grants_permissions') is not False):
        raise RuntimeError('UNTRUSTED_READONLY_COMPANY_INVENTORY_REPORT')
    try:
        stamped=datetime.datetime.fromisoformat(
            data['refreshed_utc'].replace('Z','+00:00'))
    except (KeyError,TypeError,ValueError,AttributeError) as exc:
        raise RuntimeError('COMPANY_INVENTORY_TIMESTAMP_INVALID') from exc
    if stamped.tzinfo is None:
        raise RuntimeError('COMPANY_INVENTORY_TIMESTAMP_INVALID')
    age=(now-stamped).total_seconds()
    if age < -60:
        raise RuntimeError('COMPANY_INVENTORY_TIMESTAMP_IN_FUTURE')
    results=data.get('results')
    readbacks=data.get('plugin_target_readbacks')
    if not isinstance(results,list) or not isinstance(readbacks,list):
        raise RuntimeError('COMPANY_INVENTORY_OBSERVATIONS_INVALID')
    rows=[]
    indexed={}
    for row in readbacks:
        if not isinstance(row,dict):
            raise RuntimeError('COMPANY_INVENTORY_TARGET_OBSERVATION_INVALID')
        scope=(row.get('company'),row.get('environment'))
        if scope in indexed:
            raise RuntimeError('DUPLICATE_COMPANY_TARGET_READBACK')
        indexed[scope]=row
    seen=set()
    for item in results:
        if not isinstance(item,dict):
            raise RuntimeError('COMPANY_INVENTORY_PROVIDER_OBSERVATION_INVALID')
        company=item.get('company')
        env=item.get('environment')
        scope=(company,env)
        if (not isinstance(company,str) or not company
            or env not in ('production','staging','development','local')
            or scope in seen):
            raise RuntimeError('COMPANY_INVENTORY_SITE_SCOPE_INVALID')
        seen.add(scope)
        readback=indexed.get(scope,{})
        provider=item.get('state')
        local=readback.get('state')
        if age > 3600:
            state='STALE_LAST_ADVISORY_READBACK'
        elif provider=='ADVISORY_INVENTORY_PUBLISHED' and (
            local=='LIVE_WPCLI_INVENTORY_PUBLISHED'):
            state='READ_ONLY_ADVISORY_AVAILABLE'
        elif provider in ('DEFERRED_STAGING_WPCLI_BUSY',
                          'DEFERRED_WPCLI_SLOT_BUSY'):
            state='DEFERRED_BY_EXISTING_SCHEDULER'
        else:
            state='ADVISORY_READBACK_INCOMPLETE'
        rows.append({
            'company':company,'environment':env,'state':state,
            'provider_observation_state':provider,
            'company_wpcli_readback_state':local or 'NOT_OBSERVED',
            'last_inventory_refresh_utc':data['refreshed_utc'],
            'observation_age_seconds':max(0,int(age)),
            'advisory_file_diff_count':(item.get('different_files')
                if provider=='ADVISORY_INVENTORY_PUBLISHED' else None),
            'full_signed_code_inventory_verified':False,
            'original_313_receipt_verified':False,
            'tool_transport_refusal':'NOT_OBSERVABLE_FROM_HOST',
            'retry':'NEXT_EXISTING_TIMER' if state in (
                'STALE_LAST_ADVISORY_READBACK',
                'DEFERRED_BY_EXISTING_SCHEDULER') else 'NONE',
            'site_mutated':False,
        })
    return {
        'contract':'mad4b.company-reconcile.read-status.v1',
        'observed_utc':now.isoformat(),
        'targets':rows,'target_count':len(rows),
        'any_full_signed_inventory_verified':False,
        'external_transport_safety_gate':'NOT_OBSERVABLE_FROM_HOST',
        'new_executor_created':False,'new_timer_created':False,
        'grants_created':False,'site_mutated':False,
        'read_only':True,
    }


def scoped_native_execution_lifecycle(*,now=None):
    """Source-revalidated exact Job scope without altering inventory truth."""
    adapter=SIGNED_ORIGINAL312_313_ADAPTER
    import stat
    if (adapter.is_symlink() or not adapter.is_file()
        or adapter.stat().st_uid!=0 or adapter.stat().st_mode&0o022):
        raise RuntimeError('ORIGINAL_SCOPED_LIFECYCLE_ADAPTER_UNTRUSTED')
    spec=importlib.util.spec_from_file_location(
       'mad4b_reconcile_scoped313_projection',adapter)
    module=importlib.util.module_from_spec(spec)
    if spec.loader is None:
        raise RuntimeError('ORIGINAL_SCOPED_LIFECYCLE_ADAPTER_UNAVAILABLE')
    spec.loader.exec_module(module)
    projected=module.report_projection(now=now)
    # Read live Original 305/306/313 and root-only 313 host receipts; this
    # is a historical read-only child, never current 312/313 write authority.
    projected['original_remote_signed_wpcli_history']=        module.original_remote_signed_wpcli_projection(now=now)
    return projected


@contextlib.contextmanager
def report_lock():
    # Serialize full inventory and low-cost projections, including the
    # read-modify-write of the shared parent report.
    fd=os.open(str(REPORT_LOCK),os.O_CREAT|os.O_RDWR|getattr(os,'O_NOFOLLOW',0),0o600)
    try:
        fcntl.flock(fd,fcntl.LOCK_EX)
        yield
    finally:
        fcntl.flock(fd,fcntl.LOCK_UN)
        os.close(fd)


def enrolled_targets():
    p=json.loads(BINDING_POLICY.read_text())
    if p.get('contract')!='mad4b.company-fx-distribution.v1':raise RuntimeError('TRUSTED_SITE_BINDING_CONTRACT_MISMATCH')
    spec=importlib.util.spec_from_file_location('mad4b_enrolled_binding_reader',REGISTRY_ADAPTER)
    module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
    targets=module.gather_bindings(p)
    if len(targets)>40:raise RuntimeError('UNEXPECTED_ENROLLED_TARGET_COUNT')
    return sorted({(x['company_code'],x['environment']) for x in targets})

def _refresh_projections(*, now=None):
    """Lightweight original Company Reconcile projection refresh, no WP-CLI.

    Preserve the existing full inventory's timestamp and receipts. Only fold
    independently verified, recent child reports into clearly marked partial
    projections; neither old inventory fields nor full readiness are forged.
    """
    if now is None:now=datetime.datetime.now(datetime.timezone.utc)
    def read_fresh(path,contract,clock_field):
        if path.is_symlink() or not path.is_file() or path.stat().st_size>500000:
            raise RuntimeError('UNTRUSTED_RECONCILE_CHILD_REPORT')
        row=json.loads(path.read_text())
        if row.get('contract')!=contract:
            raise RuntimeError('RECONCILE_CHILD_CONTRACT_DRIFT')
        stamped=datetime.datetime.fromisoformat(row[clock_field].replace('Z','+00:00'))
        if stamped.tzinfo is None or not (-60 <= (now-stamped).total_seconds() <= 1200):
            raise RuntimeError('RECONCILE_CHILD_EVIDENCE_STALE_OR_FUTURE')
        return row
    semantic=read_fresh(SEMANTIC_REPORT,'mad4b.company-live-semantic-reconciliation.v1','observed_utc')
    readiness=read_fresh(READINESS_REPORT,'mad4b.company-native-universal-readiness.v1','observed_utc')
    if (semantic.get('evidence_mode')!='LIVE_RUNTIME'
        or semantic.get('read_only') is not True
        or semantic.get('writes_to_tenant') is not False
        or semantic.get('company')!='wayfatours'
        or semantic.get('environment')!='production'
        or semantic.get('source_origin')!='https://tours.dev.mad4b.com'
        or semantic.get('target_origin')!='https://wayfatours.com'
        or semantic.get('comparison',{}).get('auto_apply') is not False
        or readiness.get('production_mutated') is not False
        or readiness.get('grants_issued') is not False
        or readiness.get('native_jobs_dispatched') is not False):
        raise RuntimeError('CHILD_RECONCILIATION_NOT_NONMUTATING_OR_WRONG_SCOPE')
    c=semantic['comparison']
    counts=c['priority_counts']
    targets=[r for r in readiness.get('targets',[]) if
        r.get('company_code')=='wayfatours' and r.get('environment')=='production']
    if len(targets)!=1 or readiness.get('target_count')!=len(readiness.get('targets',[])):
        raise RuntimeError('ORIGINAL_305_SITE_TARGET_PROJECTION_MISMATCH')
    native=targets[0]
    proof=native.get('signed_host_proof_verified')
    cert=native.get('native_312_313_certified')
    if type(proof) is not bool or type(cert) is not bool:
        raise RuntimeError('ORIGINAL_HOST_OR_WRITE_CERTIFICATE_BOOLEAN_INVALID')
    expected_access='ORIGINAL_305_327_ACCESS_VERIFIED'
    expected_cert=('ORIGINAL_312_313_INDEPENDENT_WRITE_VERIFIED' if cert
                   else 'NO_INDEPENDENT_SIGNED_313_WRITE_CERTIFICATE')
    if ((proof and native.get('standing_transport_access_state')!=expected_access)
        or (not proof and native.get('standing_transport_access_state')==expected_access)
        or (cert and not proof)
        or native.get('independent_write_certificate_state')!=expected_cert):
        raise RuntimeError('ORIGINAL_327_313_ACCESS_PROJECTION_INCONSISTENT')
    if not REPORT.is_file() or REPORT.is_symlink():
        raise RuntimeError('ORIGINAL_FULL_COMPANY_INVENTORY_REPORT_UNAVAILABLE')
    data=json.loads(REPORT.read_text())
    if (data.get('contract')!='mad4b.company-reconcile-inventory-refresh.v1'
        or data.get('writes_to_tenant') is not False
        or data.get('creates_jobs') is not False
        or data.get('grants_permissions') is not False):
        raise RuntimeError('ORIGINAL_FULL_INVENTORY_CONTRACT_INVALID')
    full_inventory_utc=data['refreshed_utc']
    data['live_semantic_discovery']={
        'state':'LIVE_SEMANTIC_DISCOVERY_PUBLISHED',
        'report':str(SEMANTIC_REPORT),
        'company':semantic['company'],'environment':semantic['environment'],
        'source_origin':semantic['source_origin'],'target_origin':semantic['target_origin'],
        'evidence_observed_utc':semantic['observed_utc'],
        'target_p1':counts['target_p1_distinct_issues'],
        'target_p2':counts['target_p2_finding_rows'],
        'source_effective_filter_faults':counts['source_p1_effective_filter_definitions'],
        'target_effective_filter_faults':c['effective_filter_fault_counts']['target'],
        'shared_code_merge_required':c['shared_code_merge_required'],
        'semantic_filter_drift_count':len(c['filter_semantic_drift']),
        'code_drift_count':len(c['code_drift']),
        'elementor_price_tag_state':c.get('price_tag_contract',{}).get('state','NOT_OBSERVED'),
        'elementor_price_tag_verified':c.get('price_tag_contract',{}).get('state')=='PASS_VERIFIED',
        'elementor_price_tag_localization_drift':c.get('price_tag_untranslated_languages',[]),
        'company_country_scope_state':c.get('country_scope_contract',{}).get('state','NOT_OBSERVED'),
        'company_country_scope_verified':c.get('country_scope_contract',{}).get('state')=='PASS_VERIFIED',
        'intentional_scope_held_published_rate_count':c.get('country_scope_contract',{}).get('scope_held_published_rate_draft_parent_count',0),
        'writes_to_tenant':False,'ready_for_auto_apply':False,'read_only':True}
    data['original_native_access_projection']={
        'company':'wayfatours','environment':'production',
        'evidence_observed_utc':readiness['observed_utc'],
        'original_327_host_verified':native['signed_host_proof_verified'],
        'original_327_state':native['original_327_proof_state'],
        'standing_transport_access_state':native['standing_transport_access_state'],
        'native_312_313_write_certified':native['native_312_313_certified'],
        'independent_write_certificate_state':native['independent_write_certificate_state'],
        'native_write_eligible':native['native_command_eligible'],
        'next_original_transition':native['next_original_transition'],
        'issuer_grants_created':False,'target_mutated':False}
    data['read_status']=inventory_read_status(data,now=now)
    data['original_native_scoped_lifecycle']=scoped_native_execution_lifecycle(
        now=now)
    data['partial_projection_refresh_utc']=now.isoformat()
    data['full_inventory_refreshed_utc_unchanged']=full_inventory_utc
    data['projection_refresh_mode']='CURRENT_CHILDREN_ONLY_FULL_INVENTORY_NOT_RESCANNED'
    data['projection_verdict']='FRESH_VERIFIED_CHILDREN_ONLY'
    data['child_evidence_freshness']={
       'semantic':{'fresh':True,'observed_utc':semantic['observed_utc'],
                   'current_evidence_authority':True},
       'native_readiness':{'fresh':True,'observed_utc':readiness['observed_utc'],
                   'current_evidence_authority':True}}
    data['lifecycle_evidence_freshness']=_lifecycle_freshness(now)
    tmp=REPORT.with_suffix('.json.tmp')
    tmp.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    tmp.chmod(0o600)
    os.replace(tmp,REPORT)
    return {'state':'COMPANY_RECONCILE_CURRENT_PROJECTIONS_REFRESHED',
        'report':str(REPORT),'full_inventory_refreshed_utc':full_inventory_utc,
        'live_semantic_observed_utc':semantic['observed_utc'],
        'native_readiness_observed_utc':readiness['observed_utc'],
        'target_p1':counts['target_p1_distinct_issues'],
        'original_327_verified':proof,'original_313_write_certified':cert,
        'read_only':True,'writes_to_tenant':False,'grants_issued':False}


def _child_freshness(now):
    """Observe source timestamps without ever extending original evidence life."""
    out={}
    for name,path,contract in (
        ('semantic',SEMANTIC_REPORT,'mad4b.company-live-semantic-reconciliation.v1'),
        ('native_readiness',READINESS_REPORT,'mad4b.company-native-universal-readiness.v1')):
        if not path.is_file() or path.is_symlink() or path.stat().st_size>500000:
            raise RuntimeError('UNTRUSTED_RECONCILE_CHILD_REPORT')
        row=json.loads(path.read_text())
        if row.get('contract')!=contract:
            raise RuntimeError('RECONCILE_CHILD_CONTRACT_DRIFT')
        stamp=datetime.datetime.fromisoformat(row['observed_utc'].replace('Z','+00:00'))
        if stamp.tzinfo is None:
            raise RuntimeError('RECONCILE_CHILD_TIMESTAMP_INVALID')
        age=(now-stamp).total_seconds()
        if age< -60:
            raise RuntimeError('RECONCILE_CHILD_TIMESTAMP_FUTURE')
        if name=='semantic' and (row.get('evidence_mode')!='LIVE_RUNTIME'
            or row.get('read_only') is not True or row.get('writes_to_tenant') is not False):
            raise RuntimeError('RECONCILE_SEMANTIC_SOURCE_NOT_READONLY')
        if name=='native_readiness' and (row.get('production_mutated') is not False
            or row.get('grants_issued') is not False or row.get('native_jobs_dispatched') is not False):
            raise RuntimeError('RECONCILE_NATIVE_SOURCE_NOT_READONLY')
        out[name]={'fresh':age<=1200,'age_seconds':max(0,int(age)),
                   'observed_utc':row['observed_utc'],
                   'current_evidence_authority':age<=1200,
                   'source_report':str(path)}
    return out


def _lifecycle_freshness(now):
    if (not LIFECYCLE_REPORT.is_file() or LIFECYCLE_REPORT.is_symlink()
        or LIFECYCLE_REPORT.stat().st_size>250000):
        raise RuntimeError('UNTRUSTED_LIFECYCLE_SOURCE_REPORT')
    row=json.loads(LIFECYCLE_REPORT.read_text())
    if (row.get('contract')!='mad4b.company-lifecycle-integrity.v1'
        or row.get('origin')!='https://tours.dev.mad4b.com/'
        or row.get('environment')!='staging'
        or row.get('read_only') is not True
        or row.get('site_mutated') is not False
        or row.get('grants_created') is not False
        or row.get('provider_execution_performed') is not False
        or row.get('end_to_end_accepted_count')!=0):
        raise RuntimeError('UNTRUSTED_LIFECYCLE_ORIGINAL_REPORT')
    stamp=datetime.datetime.fromisoformat(row['observed_utc'].replace('Z','+00:00'))
    if stamp.tzinfo is None:raise RuntimeError('LIFECYCLE_TIMESTAMP_INVALID')
    age=(now-stamp).total_seconds()
    if age< -60:raise RuntimeError('LIFECYCLE_TIMESTAMP_FUTURE')
    return {'fresh':age<=1800,'age_seconds':max(0,int(age)),
        'observed_utc':row['observed_utc'],'current_evidence_authority':age<=1800,
        'original_end_to_end_accepted_count':0,
        'source_report':str(LIFECYCLE_REPORT)}

def _stale_projection_observation(now):
    """Publish truthful stale status; preserve original full-inventory timestamp.

    Never copy stale proof values into a current-readiness verdict.
    """
    freshness=_child_freshness(now)
    if all(x['fresh'] for x in freshness.values()):
        return _refresh_projections(now=now)
    if not REPORT.is_file() or REPORT.is_symlink() or REPORT.stat().st_size>1000000:
        raise RuntimeError('ORIGINAL_FULL_COMPANY_INVENTORY_REPORT_UNAVAILABLE')
    data=json.loads(REPORT.read_text())
    if (data.get('contract')!='mad4b.company-reconcile-inventory-refresh.v1'
        or data.get('writes_to_tenant') is not False
        or data.get('creates_jobs') is not False
        or data.get('grants_permissions') is not False):
        raise RuntimeError('ORIGINAL_FULL_INVENTORY_CONTRACT_INVALID')
    original=data['refreshed_utc']
    data['child_evidence_freshness']=freshness
    data['lifecycle_evidence_freshness']=_lifecycle_freshness(now)
    data['projection_verdict']='DEFERRED_STALE_CHILD_PROOF'
    data['projection_status_observed_utc']=now.isoformat()
    data['full_inventory_refreshed_utc_unchanged']=original
    # Explicitly mark the prior projections non-authoritative; do not erase
    # their old evidence, issue new receipts or reset the original timestamp.
    for field,child in [('live_semantic_discovery','semantic'),
                         ('original_native_access_projection','native_readiness')]:
        old=data.get(field)
        if isinstance(old,dict):
            old['fresh']=freshness[child]['fresh']
            old['current_evidence_authority']=freshness[child]['fresh']
            if not freshness[child]['fresh']:
                old['state']='STALE_LAST_VERIFIED_READBACK'
    data['read_status']=inventory_read_status(data,now=now)
    # Do not refresh stale child timestamps or rewrite unrelated readiness.
    # The original 312/313 overlay performs an independent live read.
    data['original_native_scoped_lifecycle']=scoped_native_execution_lifecycle(now=now)
    temp=REPORT.with_suffix('.json.stale-tmp')
    temp.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
    temp.chmod(0o600);os.replace(temp,REPORT)
    return {'state':'COMPANY_RECONCILE_STALE_CHILD_EXPLICITLY_RECORDED',
        'report':str(REPORT),'child_evidence_freshness':freshness,
        'full_inventory_refreshed_utc':original,'new_child_proof_created':False,
        'read_only':True,'writes_to_tenant':False,'grants_issued':False}


def refresh_projections(*, now=None):
    with report_lock():
        if now is None:now=datetime.datetime.now(datetime.timezone.utc)
        freshness=_child_freshness(now)
        if any(not x['fresh'] for x in freshness.values()):
            return _stale_projection_observation(now)
        return _refresh_projections(now=now)


def refresh_lifecycle_evidence(*, now=None):
    """Optional proof-scoped read-only overlay; never impersonate other fresh probes."""
    if now is None:now=datetime.datetime.now(datetime.timezone.utc)
    with report_lock():
        if (not LIFECYCLE_REPORT.is_file() or LIFECYCLE_REPORT.is_symlink()
            or LIFECYCLE_REPORT.stat().st_size>250000):
            raise RuntimeError('LIFECYCLE_EVIDENCE_MISSING_OR_UNTRUSTED')
        row=json.loads(LIFECYCLE_REPORT.read_text())
        stamp=datetime.datetime.fromisoformat(row['observed_utc'].replace('Z','+00:00'))
        if stamp.tzinfo is None or not (-60<=(now-stamp).total_seconds()<=1800):
            raise RuntimeError('LIFECYCLE_EVIDENCE_STALE')
        if (row.get('contract')!='mad4b.company-lifecycle-integrity.v1'
            or row.get('origin')!='https://tours.dev.mad4b.com/'
            or row.get('environment')!='staging'
            or row.get('read_only') is not True
            or row.get('site_mutated') is not False
            or row.get('grants_created') is not False
            or row.get('provider_execution_performed') is not False
            or row.get('end_to_end_accepted_count')!=0):
            raise RuntimeError('LIFECYCLE_EVIDENCE_SCOPE_OR_CLOSURE_INVALID')
        if not REPORT.is_file() or REPORT.is_symlink():
            raise RuntimeError('COMPANY_INVENTORY_PARENT_MISSING')
        data=json.loads(REPORT.read_text())
        if (data.get('contract')!='mad4b.company-reconcile-inventory-refresh.v1'
            or data.get('writes_to_tenant') is not False
            or data.get('grants_permissions') is not False
            or data.get('creates_jobs') is not False):
            raise RuntimeError('COMPANY_INVENTORY_PARENT_INTEGRITY_INVALID')
        data['lifecycle_integrity']={
            'contract':row['contract'],
            'scope':'tours-central:staging',
            'site_readback_observed_utc':row['site_readback_observed_utc'],
            'evidence_observed_utc':row['observed_utc'],
            'read_only':True,
            'queue_waiting_stage_work':int(row['queue_waiting_stage_work']),
            'queue_queued_runtime_resolve':int(row['queue_queued_runtime_resolve']),
            'content_job_terminal_only_verified':int(row['verified_terminal_only']),
            'end_to_end_accepted_count':0,
            'cache_pending':int(row['cache']['pending']),
            'cache_failed':int(row['cache']['failed']),
            'cache_observed_not_cacheable':int(row['cache'].get('observed_not_cacheable',0)),
            'cache_revision_verified':int(row['cache']['verified']),
            'native_readiness_evidence_fresh':row['native_readiness_evidence_fresh'],
            'central_generic_313_certified':row['central_generic_313_certified'],
            'unresolved_alerts':row['alerts'],
            'source_of_truth_report':str(LIFECYCLE_REPORT),
            'queue_runtime_revision_proof':row.get('queue_runtime_revision_proof'),
            'original_313_release_provenance':row.get('original_313_release_provenance'),
            'local_verified_never_implies_publication':True,
            'grants_created':False,'site_mutated':False}
        optimization=row.get('optimization')
        if optimization is not None:
            if (not isinstance(optimization,dict)
                or optimization.get('contract')!='mad4b.lifecycle-continuous-optimization.v1'
                or optimization.get('automated_provider_mutation') is not False
                or optimization.get('execution_throughput_certified') is not False
                or optimization.get('forecast_eta_hours') is not None
                or not isinstance(optimization.get('trend'),dict)
                or not isinstance(optimization.get('samples'),list)
                or len(optimization['samples'])>48):
                raise RuntimeError('LIFECYCLE_OPTIMIZATION_EVIDENCE_INVALID')
            data['lifecycle_integrity']['optimization']={
                'contract':optimization['contract'],
                'sample_count':len(optimization['samples']),
                'net_backlog_trend':optimization['trend'],
                'routing_recommendations':optimization.get('routing_recommendations',[]),
                'throughput_certified':False,
                'estimated_clearance_eta_hours':None,
                'automatic_write_executed':False,
                'independent_confirmation_pending':True}
        data['read_status']=inventory_read_status(data,now=now)
        data['lifecycle_evidence_freshness']={
            'fresh':True,'observed_utc':row['observed_utc'],
            'current_evidence_authority':True,
            'original_end_to_end_accepted_count':0,
            'source_report':str(LIFECYCLE_REPORT)}
        data['original_native_scoped_lifecycle']=scoped_native_execution_lifecycle(
            now=now)
        data['lifecycle_integrity']['scoped_native_312_313_projection']={
            'contract':data['original_native_scoped_lifecycle']['contract'],
            'state':data['original_native_scoped_lifecycle']['state'],
            'current_ready_intents':
                data['original_native_scoped_lifecycle']['current_ready_intents'],
            'historic_313_evidence_count':
                data['original_native_scoped_lifecycle']['historical_original_313_jobs_observed'],
            'external_enrollment':data['original_native_scoped_lifecycle'].get(
                'remote_enrollment_diagnostics',{
                    'state':'REMOTE_ENROLLMENT_NOT_OBSERVED',
                    'fresh':False,'site_count':0,
                    'no_governed_write_inferred':True}),
            'separate_from_generic_lifecycle_terminal':True,
            'jobs_created':False,'grants_created':False,
        }
        data['partial_lifecycle_projection_refresh_utc']=now.isoformat()
        # The original full inventory timestamp remains deliberately unchanged.
        tmp=REPORT.with_suffix('.json.lifecycle-tmp')
        tmp.write_text(json.dumps(data,ensure_ascii=False,indent=2)+'\n')
        tmp.chmod(0o600)
        os.replace(tmp,REPORT)
        return {'state':'COMPANY_RECONCILE_LIFECYCLE_PROOF_OVERLAY_UPDATED',
                'report':str(REPORT),'evidence_observed_utc':row['observed_utc'],
                'full_inventory_observed_utc':data['refreshed_utc'],
                'end_to_end_accepted_count':0,'site_mutated':False}


def main():
    a=argparse.ArgumentParser()
    a.add_argument('--status',action='store_true')
    a.add_argument('--list',action='store_true')
    a.add_argument('--refresh-projections',action='store_true')
    a.add_argument('--refresh-lifecycle-evidence',action='store_true')
    a.add_argument('--company',default='')
    a.add_argument('--environment',default='')
    args=a.parse_args()
    if args.status:
        if (args.list or args.refresh_projections or
            args.refresh_lifecycle_evidence or args.company or args.environment):
            raise RuntimeError('NO_MIXED_READ_STATUS_AND_ACTIVE_WORK')
        if (REPORT.is_symlink() or not REPORT.is_file()
            or REPORT.stat().st_uid!=0
            or REPORT.stat().st_mode & 0o022
            or REPORT.stat().st_size>1000000):
            raise RuntimeError('UNTRUSTED_COMPANY_INVENTORY_STATUS_REPORT')
        data=json.loads(REPORT.read_text())
        print(json.dumps(inventory_read_status(data),ensure_ascii=False))
        return
    if args.refresh_lifecycle_evidence:
        if args.list or args.refresh_projections or args.company or args.environment:
            raise RuntimeError('NO_MIXED_LIFECYCLE_EVIDENCE_SCOPE')
        try:
            print(json.dumps(refresh_lifecycle_evidence(),ensure_ascii=False))
        except RuntimeError as exc:
            if str(exc)=='LIFECYCLE_EVIDENCE_STALE':
                print(json.dumps({'state':'LIFECYCLE_EVIDENCE_STALE_DEFERRED',
                    'report_unchanged':True,'read_only':True}))
            else:raise
        return
    if args.refresh_projections:
        if args.list or args.company or args.environment:
            raise RuntimeError('NO_MIXED_REFRESH_PROJECTION_SCOPE')
        try:
            print(json.dumps(refresh_projections(),ensure_ascii=False))
        except RuntimeError as exc:
            if str(exc)!='RECONCILE_CHILD_EVIDENCE_STALE_OR_FUTURE':
                raise
            # A freshly published child does not make an unrelated older
            # 327/313 status authoritative. Defer without changing the report.
            print(json.dumps({'state':'PROJECTION_REFRESH_DEFERRED_STALE_CHILD',
                'report_unchanged':True,'read_only':True,'grants_issued':False}))
        return
    pairs=enrolled_targets()
    if args.company or args.environment:
        if (args.company,args.environment) not in pairs:raise RuntimeError('TARGET_NOT_ENROLLED')
        pairs=[(args.company,args.environment)]
    if args.list:
        print(json.dumps({'state':'ENROLLED_SOURCE_INVENTORY_TARGETS','targets':[{'company':c,'environment':e} for c,e in pairs],'source_only':True}))
        return
    results=[]
    for company,env in pairs:
        result=subprocess.run([sys.executable,str(PUBLISHER),'--company',company,'--environment',env],text=True,capture_output=True,timeout=115,check=False)
        if result.returncode==0:
            try:
                value=json.loads(result.stdout.strip().splitlines()[-1]);state=value['snapshot']['state']
                count=value['snapshot']['different']
            except (ValueError,KeyError,IndexError,TypeError):state='INVALID_PUBLISHER_REPLY';count=None
        else:
            state=('DEFERRED_STAGING_WPCLI_BUSY'
                if 'STAGING_WPCLI_BUSY' in (result.stdout+result.stderr)
                else 'READONLY_INVENTORY_REFRESH_FAILED')
            count=None
        results.append({'company':company,'environment':env,'state':state,'different_files':count,
                        'publisher_exit':result.returncode})
    # Exact target adapters are observations, not native execution providers.
    # Reuse the existing reconcile timer, avoiding a second host scheduling loop.
    # This adapter currently covers Wayfatours Production only.
    plugin_results=[]
    if ('wayfatours','production') in pairs and TARGET_READBACK.is_file():
        try:
            observed=subprocess.run([sys.executable,str(TARGET_READBACK)],
                capture_output=True,text=True,timeout=85,check=False)
            data=json.loads(observed.stdout.strip().splitlines()[-1]) if observed.returncode==0 else {}
            correct=(data.get('state')=='SCOPED_TARGET_READBACK_PUBLISHED'
                and data.get('tenant_mutated') is False
                and data.get('original_313_certified') is False)
            plugin_results.append({
                'company':'wayfatours','environment':'production',
                'state':'LIVE_WPCLI_INVENTORY_PUBLISHED' if correct else 'READBACK_DEFERRED',
                'install_missing_count':data.get('missing') if correct else None,
                'activation_needed_count':data.get('inactive') if correct else None,
                'plan_hash':data.get('plan_hash') if correct else None,
                'signed_313_produced':False,'target_mutated':False})
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError):
            plugin_results.append({'company':'wayfatours','environment':'production',
                'state':'READBACK_DEFERRED','signed_313_produced':False,
                'target_mutated':False})
    package_results=[]
    if ('wayfatours','production') in pairs and PACKAGE_PREFLIGHT.is_file():
        try:
            if not PACKAGE_BUILDER.is_file():
                raise RuntimeError('SOURCE_PACKAGE_BUILDER_NOT_FOUND')
            built=subprocess.run([sys.executable,str(PACKAGE_BUILDER)],
                text=True,capture_output=True,timeout=85,check=False)
            if built.returncode!=0:
                raise RuntimeError('SOURCE_PACKAGE_BUILD_DEFERRED')
            package=subprocess.run([sys.executable,str(PACKAGE_PREFLIGHT)],
                text=True,capture_output=True,timeout=85,check=False)
            value=json.loads(package.stdout.strip().splitlines()[-1])
            ok=(package.returncode==0
                and value.get('state')=='SOURCE_ARCHIVE_AND_LIVE_TREE_VERIFIED'
                and value.get('target_install_authorized') is False
                and value.get('original_313_issued') is False
                and value.get('production_mutated') is False)
            package_results.append({'company':'wayfatours','environment':'production',
                'state':'SOURCE_ARCHIVE_VERIFIED' if ok else 'PACKAGE_PREFLIGHT_HOLD',
                'plan_hash':value.get('plan_hash') if ok else None,
                'archive_sha256':value.get('archive_sha256') if ok else None,
                'verified_files':value.get('archive_files') if ok else None,
                'native_worker_alignment':value.get('native_worker_alignment_state') if ok else None,
                'tenant_modified':False,'native_job_issued':False,'signed_313_issued':False})
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError,RuntimeError):
            package_results.append({'company':'wayfatours','environment':'production',
                'state':'PACKAGE_PREFLIGHT_DEFERRED','tenant_modified':False,
                'native_job_issued':False,'signed_313_issued':False})
    # Observations run inside the already scheduled read-only Company Reconcile
    # service. This is not a second timer and never triggers target apply.
    semantic={'state':'NOT_ENROLLED_OR_NOT_CONFIGURED','read_only':True,
              'writes_to_tenant':False,'ready_for_auto_apply':False}
    if ('wayfatours','production') in pairs and LIVE_SEMANTIC.is_file():
        try:
            result=subprocess.run([sys.executable,str(LIVE_SEMANTIC)],
                                  text=True,capture_output=True,timeout=90,check=False)
            data=json.loads(result.stdout.strip().splitlines()[-1]) if result.returncode==0 else {}
            if (result.returncode==0 and
                data.get('state')=='LIVE_SEMANTIC_DISCOVERY_PUBLISHED' and
                data.get('writes_to_tenant') is False):
                # A successful scan process is not a certified price-tag contract.
                # Preserve its independent fail-closed functional status.
                price_state=data.get('price_tag_state','NOT_OBSERVED')
                semantic={**data,'elementor_price_tag_state':price_state,
                          'elementor_price_tag_verified':price_state=='PASS_VERIFIED',
                          'company_country_scope_state':data.get('country_scope_state','NOT_OBSERVED'),
                          'company_country_scope_verified':data.get('country_scope_state')=='PASS_VERIFIED',
                          'read_only':True,'ready_for_auto_apply':False}
            else:
                semantic={'state':'LIVE_SEMANTIC_DISCOVERY_DEFERRED',
                          'reason':'WPCLI_OR_READBACK_NOT_READY',
                          'read_only':True,'writes_to_tenant':False,
                          'ready_for_auto_apply':False}
        except (subprocess.TimeoutExpired,ValueError,IndexError,OSError):
            semantic={'state':'LIVE_SEMANTIC_DISCOVERY_DEFERRED',
                      'reason':'WPCLI_BUSY_OR_INVALID_JSON','read_only':True,
                      'writes_to_tenant':False,'ready_for_auto_apply':False}
    report={'contract':'mad4b.company-reconcile-inventory-refresh.v1',
       'refreshed_utc':datetime.datetime.now(datetime.timezone.utc).isoformat(),
       'source':'company-fx-distribution enrolled bindings','results':results,
       'plugin_target_readbacks':plugin_results,
       'plugin_package_preflight':package_results,
       'live_semantic_discovery':semantic,
       'writes_to_tenant':False,'creates_jobs':False,'grants_permissions':False}
    report['read_status']=inventory_read_status(report)
    with report_lock():
        tmp=REPORT.with_suffix('.json.tmp')
        tmp.write_text(json.dumps(report,ensure_ascii=False,indent=2)+'\n')
        tmp.chmod(0o600)
        os.replace(tmp,REPORT)
    try:
        # The heavy full scan can publish a fresh parent after the lightweight
        # timer already merged its child views. Merge AGAIN immediately using
        # current independently verified children to prevent dropping 327/313.
        refresh_projections()
        report=json.loads(REPORT.read_text())
    except RuntimeError as exc:
        # Stale child evidence must never turn into a fresh proof. Full scan
        # evidence itself remains valid and carries its own refreshed_utc.
        print('COMPANY_PROJECTION_POST_FULL_REFRESH_DEFER:'+str(exc)[:120],file=sys.stderr)
    # Full inventory replaces the parent file: reattach an independently fresh
    # Lifecycle child proof without asserting that this scan rechecked it.
    try:
        refresh_lifecycle_evidence()
        report=json.loads(REPORT.read_text())
    except RuntimeError as exc:
        print('LIFECYCLE_OVERLAY_POST_FULL_REFRESH_DEFER:'+str(exc)[:120],file=sys.stderr)
    print(json.dumps(report,ensure_ascii=False))
    if any(row['state'] not in ('ADVISORY_INVENTORY_PUBLISHED','DEFERRED_STAGING_WPCLI_BUSY')
           for row in results):sys.exit(2)
    # The report preserves explicit DEFERRED states; service success is NOT a
    # signed receipt or evidence that the entire inventory has converged.

if __name__=='__main__':
    try:main()
    except Exception as exc:
        print(json.dumps({'state':'READONLY_REFRESH_FAILED','reason':str(exc)[:200]}),file=sys.stderr)
        sys.exit(3)
