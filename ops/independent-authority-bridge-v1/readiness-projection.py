#!/usr/bin/env python3
"""Read-only projection from centralized Independent Authority Staging DB.

This is NOT an operational issuer or certificate. It has no sign, approve,
release, revoke, deploy, or customer-write operation.
"""
import datetime
import json
import os
from pathlib import Path
import subprocess
import tempfile

# Consumer identity is configurable per tenant. No tenant hostname or
# per-tenant Class name is compiled into this operator script.
ROOT=Path(__file__).resolve().parents[2]
CONFIG=Path(__file__).with_name('projection-config.json')
settings=json.loads(CONFIG.read_text(encoding='utf-8'))
if (settings.get('contract')!='mad4b.authority.readback-consumer.v1'
    or settings.get('environment')!='staging'
    or not isinstance(settings.get('site_host'),str)
    or not __import__('re').fullmatch(r'[a-z0-9.-]{4,150}',settings['site_host'])
    or not isinstance(settings.get('consumer_id'),str)
    or not __import__('re').fullmatch(r'[a-z0-9-]{3,64}',settings['consumer_id'])
    or settings['consumer_id']!=Path(__file__).resolve().parent.name):
    raise SystemExit('Invalid trusted consumer config')
EXPECTED_HOST=settings['site_host']
ENV=settings['environment']
OUT=ROOT/'exports'/settings['consumer_id']/'authority-status.json'
AUTH_CONTRACT='mad4b.independent-authority.status-projection.v1' 
SQL = """
SELECT selected_mode,automatic_fallback,live_issuance_enabled,environment
FROM mad4b_authority_policy_state WHERE id=1;
SELECT
 (SELECT COUNT(*) FROM mad4b_authority_permits),
 (SELECT COUNT(*) FROM mad4b_authority_events),
 (SELECT COUNT(*) FROM mad4b_authority_principals WHERE status='active'),
 (SELECT COUNT(*) FROM mad4b_authority_trusted_keys WHERE purpose='operational_ca' AND status='active'),
 (SELECT COUNT(*) FROM mad4b_authority_qa_runners WHERE status='diagnostic_only');
"""
stamp=datetime.datetime.now(datetime.timezone.utc).strftime('%Y-%m-%dT%H:%M:%SZ')
record={
 'contract':AUTH_CONTRACT,
 'source':'central_authority_staging_database',
 'target_site_host':EXPECTED_HOST,
 'target_environment':ENV,
 'observed_at_utc':stamp,
 'selected_mode':'unknown',
 'automatic_fallback':False,
 'database_health':'unknown',
 'authority_status':'BLOCKED',
 'reason':'authority_unreachable',
 'operational_issuance_enabled':False,
 'actual_permit_count':None,
 'actual_event_count':None,
 'mfa_principal_count':None,
 'operational_ca_count':None,
 'diagnostic_qa_runner_count':None,
 'operational_authorization':False,
 'completion_certificate_authorized':False,
 'no_issuer_or_deployment_controller_integration':True,
 'non_authorizing_readback_only':True,
 'approval_or_permit_issued':False,
}
try:
 health=subprocess.run(
  ['docker','inspect','mad4b-authority-staging-db','--format','{{.State.Health.Status}}'],
  capture_output=True,text=True,check=True,timeout=12
 ).stdout.strip()
 record['database_health']=health if health in ('healthy','starting','unhealthy') else 'unknown'
 if health!='healthy':
  record['reason']='authority_database_unhealthy'
 else:
  cmd=['docker','exec','mad4b-authority-staging-db','sh','-c',
       'MYSQL_PWD="$(cat /run/secrets/service_password)"; export MYSQL_PWD; exec mariadb -h 127.0.0.1 -u authority_runtime -N -B mad4b_authority_staging -e "$1"','--',SQL]
  result=subprocess.run(cmd,capture_output=True,text=True,check=True,timeout=25)
  rows=[line.split('\t') for line in result.stdout.splitlines() if line.strip()]
  if len(rows)!=2 or len(rows[0])!=4 or len(rows[1])!=5:
   raise ValueError('unexpected_authority_sql_shape')
  mode,fallback,issuance,environment=rows[0]
  p,e,mfa,ca,qa=(int(x) for x in rows[1])
  if any(x<0 for x in (p,e,mfa,ca,qa)):
   raise ValueError('negative_authority_counter')
  record.update(selected_mode=mode,automatic_fallback=(fallback=='1'),
   operational_issuance_enabled=(issuance=='1'),
   actual_permit_count=p,actual_event_count=e,mfa_principal_count=mfa,
   operational_ca_count=ca,diagnostic_qa_runner_count=qa)
  if environment!='staging' or mode!='independent_authority' or fallback!='0':
   record['reason']='selected_mode_or_environment_mismatch'
  elif issuance!='0':
   record['reason']='issuance_flag_requires_separate_certification'
  elif ca!=1 or mfa<3:
   record['reason']='independent_ca_and_mfa_not_enrolled'
  else:
   record['reason']='operational_issuer_and_certificates_not_certified'
except (OSError,ValueError,subprocess.SubprocessError):
 record['reason']='authority_readback_failed'

# Atomically write only the read-only non-authorizing projection. This bridge
# deliberately can NEVER say PERMITTED even if raw operational flags change.
fd,tmp=tempfile.mkstemp(prefix='.status-',dir=OUT.parent)
try:
 os.fchmod(fd,0o640)
 os.fchown(fd,0,33)
 with os.fdopen(fd,'w') as file:
  json.dump(record,file,indent=2,sort_keys=True)
  file.write('\n')
  file.flush()
  os.fsync(file.fileno())
 os.replace(tmp,OUT)
finally:
 if os.path.exists(tmp):os.unlink(tmp)
print(json.dumps({k:record[k] for k in (
 'observed_at_utc','database_health','selected_mode','authority_status',
 'reason','actual_permit_count','mfa_principal_count','operational_ca_count',
 'operational_authorization','non_authorizing_readback_only')}))
