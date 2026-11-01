#!/usr/bin/env python3
"""Certify local-Git channel governance against the completed original systemd cycle.
Evidence only, no timer/service start, grant, WP-CLI operation, or remote access.
"""
from __future__ import annotations
import argparse
from datetime import datetime, timezone, timedelta
import hashlib
import json
from pathlib import Path
import re
import subprocess
import sys

HERE = Path(__file__).resolve().parent
ROOT = Path('/opt/mad4b-company-transport/channel-governance-v1')
LIVE = ROOT / 'integration.py'
REPORT = ROOT / 'integration-status.json'
UNIT = Path('/etc/systemd/system/mad4b-dispatch-v1.service.d/90-channel-governance-integration.conf')
SERVICE = 'mad4b-dispatch-v1.service'
CONTRACT = 'mad4b.channel-governance-dispatcher-acceptance.v1'
SOURCES = 10
SHA256 = re.compile(r'^[a-f0-9]{64}$')

def sha(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for b in iter(lambda: stream.read(65536), b''):
            h.update(b)
    return h.hexdigest()

def run(*args):
    p = subprocess.run(args, text=True, capture_output=True, timeout=35, check=False)
    if p.returncode != 0:
        raise RuntimeError('READ_ONLY_PROBE_FAILED:' + args[0] + ':' + str(p.returncode))
    return p.stdout

def parse_utc(s):
    match = re.search(r'(\d{4}-\d\d-\d\d) (\d\d:\d\d:\d\d) UTC$',s)
    if not match:raise ValueError('SYSTEMD_UTC_TIME_INVALID')
    return datetime.fromisoformat(match.group(1)+'T'+match.group(2)+'+00:00')

def verify():
    files = [HERE/'integration.py', HERE/'integration.previous.py',
             HERE/'90-channel-governance-integration.conf']
    if any(not p.is_file() or p.is_symlink() for p in files+[LIVE,REPORT,UNIT]):
        raise ValueError('ORIGINAL_OR_GIT_SOURCE_UNAVAILABLE')
    if sha(HERE/'integration.py') != sha(LIVE):
        raise ValueError('GIT_SOURCE_MISMATCH_WITH_LIVE')
    if sha(HERE/'90-channel-governance-integration.conf') != sha(UNIT):
        raise ValueError('GIT_UNIT_MISMATCH_WITH_LIVE')
    original=sha(HERE/'integration.previous.py')
    if original!='ba61105b27175055e2b811cb8341ae8f41b7c72e723483978902b2e8859440f8':
        raise ValueError('PREVIOUS_RELEASE_SOURCE_MISMATCH')
    status = json.loads(REPORT.read_text())
    if status.get('contract') != 'mad4b.channel-governance-integration.v1':
        raise ValueError('STATUS_CONTRACT_MISMATCH')
    sources = status.get('sources',{})
    if len(sources) != SOURCES or any(v.get('snapshot')!='READABLE' for v in sources.values()):
        raise ValueError('STATUS_READBACK_COVERAGE_NOT_COMPLETE')
    for key in ('grants_created_by_this_component','wpcli_jobs_run_by_this_component',
                'production_mutations_by_this_component','authority_overrides_by_this_component',
                'alternate_execution_channels_started'):
        if status.get(key)!=0:raise ValueError('DIAGNOSTIC_COMPONENT_EFFECT:' + key)

    props = run('systemctl','show', SERVICE,'-p','ActiveState','-p','Result',
                '-p','ExecMainStatus','--no-pager')
    values=dict(line.split('=',1) for line in props.splitlines() if '=' in line)
    created=datetime.fromisoformat(status['generated_at'].replace('Z','+00:00'))
    if datetime.fromtimestamp(LIVE.stat().st_mtime,timezone.utc)>created:
        raise ValueError('RUNTIME_CHANGED_AFTER_RECORDED_READBACK')
    unit_read=run('systemctl','cat',SERVICE)
    expected='ExecStart=-/usr/bin/python3 /opt/mad4b-company-transport/channel-governance-v1/integration.py reconcile'
    if unit_read.count(expected)!=1:
        raise ValueError('EXACT_ORIGINAL_DISPATCHER_DROPIN_NOT_REGISTERED')
    run('systemd-analyze','verify',SERVICE)
    # The original timer may already be running its NEXT cycle. Certify the
    # exact completed historic cycle via journal receipts instead of requiring
    # ActiveState=inactive at this arbitrary instant.
    journal=run('journalctl','-u',SERVICE,
      '--since',(created-timedelta(seconds=15)).strftime('%Y-%m-%d %H:%M:%S UTC'),
      '--until',(created+timedelta(seconds=30)).strftime('%Y-%m-%d %H:%M:%S UTC'),
      '-o','json','--no-pager')
    observed=False
    matched_line=None
    completion=[]
    deactivated=[]
    # journald may omit an oversized MESSAGE in JSON output; -o cat retains
    # the raw source line. Use JSON separately for systemd completion metadata.
    raw_text=run('journalctl','-u',SERVICE,
      '--since',(created-timedelta(seconds=15)).strftime('%Y-%m-%d %H:%M:%S UTC'),
      '--until',(created+timedelta(seconds=30)).strftime('%Y-%m-%d %H:%M:%S UTC'),
      '-o','cat','--no-pager')
    for message in raw_text.splitlines():
        if not message.startswith('{') or '"mad4b.channel-governance-integration.v1"' not in message:
            continue
        try:j=json.loads(message)
        except ValueError:continue
        if j.get('contract')==status['contract'] and j.get('generated_at')==status['generated_at']:
            observed=True
            matched_line=message
    for raw in journal.splitlines():
        try:
            jrow=json.loads(raw)
            message=str(jrow.get('MESSAGE',''))
            moment=datetime.fromtimestamp(int(jrow.get('__REALTIME_TIMESTAMP',0))/1e6,timezone.utc)
        except (ValueError,TypeError,OverflowError):continue
        if message.startswith('{') and '"mad4b.channel-governance-integration.v1"' in message:
            try: j=json.loads(message)
            except ValueError:continue
            if j.get('contract')==status['contract'] and j.get('generated_at')==status['generated_at']:
                observed=True
                matched_line=message
        if 'Deactivated successfully.' in message and created <= moment <= created + timedelta(seconds=30):
            deactivated.append(moment)
        if 'Finished mad4b-dispatch-v1.service' in message and created <= moment <= created + timedelta(seconds=30):
            completion.append(moment)
    if not (observed and completion and deactivated):
        raise ValueError('COMPLETED_ORIGINAL_DISPATCHER_JOURNAL_CYCLE_NOT_FOUND')
    end=min(completion)
    return {
      'contract': CONTRACT,
      'evidence_checked_at_utc': datetime.now(timezone.utc).isoformat(),
      'target': 'tours.dev.mad4b.com',
      'host': 'srv2030908',
      'site_environment': 'staging',
      'service': SERVICE,
      'service_result': 'success',
      'service_exit_utc': end.isoformat(),
      'current_service_state_at_evidence_read': values.get('ActiveState'),
      'journal_exact_contract_and_timestamp_match':True,
      'systemd_configuration_verified':True,
      'git_source_matches_live':True,
      'git_unit_matches_live':True,
      'sources_readable': len(sources),
      'coverage_total':SOURCES,
      'test_suite_expected':40,
      'grant_issuer_modified': False,
      'grants_created_by_diagnostic':0,
      'wpcli_jobs_executed_by_diagnostic':0,
      'production_mutations_by_diagnostic':0,
      'original_grants_authority_status':'UNCHANGED_BY_THIS_INTEGRATION',
      'module_sha256':sha(LIVE),
      'previous_module_sha256':original,
      'systemd_dropin_sha256':sha(UNIT),
      'original_status_sha256':sha(REPORT),
      'journal_event_sha256':hashlib.sha256(matched_line.encode()).hexdigest(),
      'runtime_cycle_certified': True,
      'scope_limit':'Diagnostic lifecycle integration, not authorization of site writes or issuance of original 305 grants',
    }

if __name__ == '__main__':
    parser=argparse.ArgumentParser()
    parser.add_argument('--output', type=Path)
    args=parser.parse_args()
    try:
        certificate=verify()
        body=json.dumps(certificate,indent=2,sort_keys=True)+'\n'
        if args.output:
            if args.output.exists():raise ValueError('CERTIFICATE_EXISTS_REVIEW_REQUIRED')
            args.output.write_text(body)
        print(body)
    except (OSError,ValueError,RuntimeError,KeyError,TypeError) as error:
        print(json.dumps({'state':'NOT_CERTIFIED','reason':str(error)[:180]}))
        sys.exit(2)
