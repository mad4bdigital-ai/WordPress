#!/usr/bin/env python3
"""Prove immutable source/live boundaries without inventing a signed release."""
from __future__ import annotations

import hashlib
import json
from pathlib import Path
import re

ROOT=Path(__file__).resolve().parents[1]
REPORT=ROOT/"docs/release-coverage/company-original306313-cas-v2-provenance-20261112.json"
CENTRAL=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
TRANSPORT=Path('/opt/mad4b-company-transport/native-313-independent')
ORIGINAL_FINALIZER=Path(
 '/opt/mad4b-company-provisioning/spec-kit/company-reconcile-orchestrator/'
 'scripts/original_dynamic_313_finalizer.py'
)
CONFIG=CENTRAL/'wp-content/plugins/mad4b-platform/modules/authority/dynamic313/config.php'
ORIGINAL_WORKER=TRANSPORT/'claimed-job-worker.py'
ORIGINAL_INDEX=TRANSPORT/'dynamic-operation-registry-v1/enrolled/index.json'
PREFIX='ops/dynamic-operation-registry-v1/'
FILES=[
 PREFIX+'registry.py',
 PREFIX+'worker_adapter.py',
 PREFIX+'executor-time-cas-v1/preclaim.py',
 PREFIX+'executor-time-cas-v1/finalizer.py',
 PREFIX+'executor-time-cas-v1/host_handler.py',
 PREFIX+'executor-time-cas-v1/independent_readback.py',
 PREFIX+'providers/company.resource.cas.apply/manifest.json',
 PREFIX+'providers/company.resource.cas.apply/evidence-schema.json',
 PREFIX+'executor-time-cas-v1/integrations/plan_original_registry_upgrade.py',
 PREFIX+'executor-time-cas-v1/integrations/patch_original306_provider_preclaim.py',
 'wp-content/plugins/mad4b-platform/modules/authority/dynamic313/class-mad4b-dynamic-313-wordpress.php',
 'wp-content/plugins/mad4b-platform/modules/services/313b-mad4b-native-independent-verifier.php',
]
DENIED_TRUE=(
 'live_central_cas_finalizer_ready',
 'cas_provider_original_registry_enrolled',
 'original306_live_worker_modified',
 'original313_host_finalizer_modified',
 'original318_signed_release_certified',
 'original313_cas_certificate_issued',
 'original305_cas_job_issued',
 'scoped_cas_grant_created',
 'other_companies_modified',
 'wayfatours_data_mutated',
)


class Refused(ValueError):
 pass


def require(cond,reason):
 if not cond:raise Refused(reason)


def sha(path:Path):
 return hashlib.sha256(path.read_bytes()).hexdigest()


def verify(doc:dict,*,live:bool=False)->dict:
 require(doc.get('contract')==
   'mad4b.company.original306313-cas-source-integration.v2',
   'SOURCE_PROVENANCE_CONTRACT_INVALID')
 require(doc.get('date')=='2026-11-12'
         and doc.get('central')=='tours.dev.mad4b.com'
         and doc.get('tenant')=='wayfatours.com',
   'WRONG_COMPANY_OR_DATE')
 for field in DENIED_TRUE:
  require(doc.get(field) is False,'FALSE_PRODUCTION_CERTIFICATION_'+field)
 for field in (
   'legacy_code_release_central_operational_before',
   'legacy_code_release_central_operational_after',
   'live_central_dynamic313_routing_deployed'):
  require(doc.get(field) is True,'LIVE_LEGACY_PARITY_NOT_ESTABLISHED_'+field)
 require(doc.get('legacy_313_schema')=='code_release.v2',
   'LEGACY_SIGNED_EVIDENCE_SCHEMA_CHANGED')
 require(doc.get('current_enrolled_operations')==3
         and doc.get('source_only_registry_upgrade_proposed_operations')==4,
   'UNAUTHORIZED_PROVIDER_COUNT_CHANGE')
 rows=doc.get('source_files')
 require(isinstance(rows,list) and len(rows)==len(FILES),
         'SOURCE_FILE_LIST_INCOMPLETE')
 for index,expected in enumerate(FILES):
  row=rows[index]
  require(isinstance(row,dict)
          and set(row)=={'path','sha256','bytes','central_live'}
          and row.get('path')==expected
          and row.get('central_live')==(index>=len(FILES)-2),
          'SOURCE_FILE_IDENTITY_MISMATCH')
  source=ROOT/expected
  require(source.is_file() and not source.is_symlink()
          and len(source.read_bytes())==row['bytes']
          and sha(source)==row['sha256'],
          'SOURCE_PINNED_FILE_CHANGED_'+expected)
  if live and row['central_live']:
   target=CENTRAL/expected
   require(target.is_file() and not target.is_symlink()
           and target.stat().st_uid==0
           and not(target.stat().st_mode&0o022)
           and sha(target)==row['sha256'],
           'CENTRAL_313_DEPLOYED_SOURCE_CHANGED_'+expected)
 manifest=json.loads((ROOT/PREFIX/
     'providers/company.resource.cas.apply/manifest.json').read_text())
 require(manifest['operation_id']=='company.resource.cas.apply'
         and manifest['cas_scope']['commercial_pricing_allowed'] is False
         and manifest['cas_scope']['allowed_meta_keys']==['rate_title']
         and all(k in manifest
                 for k in ('handler','verifier','preclaim','finalizer','evidence')),
         'CAS_SOURCE_PROVIDER_SCOPE_OR_PIN_MISSING')
 for key in ('handler','verifier','preclaim','finalizer','evidence'):
  item=manifest[key]
  path=ROOT/PREFIX/item['path']
  require(path.is_file() and sha(path)==item['sha256'],
          'CAS_306_313_SOURCE_COMPONENT_SHA_MISMATCH_'+key)
 if live:
  for key,path in (
   ('live_original306_worker_sha256',ORIGINAL_WORKER),
   ('live_original313_code_release_finalizer_sha256',ORIGINAL_FINALIZER),
   ('live_registry_index_sha256',ORIGINAL_INDEX),
   ('live_central_original313_config_sha256',CONFIG)):
   require(path.is_file() and sha(path)==doc[key],
           'LIVE_ORIGINAL_AUTHORITY_CHANGED_'+key)
  original=json.loads(ORIGINAL_INDEX.read_text())
  require(len(original['providers'])==3
          and not any('company.resource.cas.apply' in str(r)
                      for r in original['providers']),
          'CAS_PROVIDER_ACTUALLY_ENROLLED_UNCERTIFIED')
 return {
  'contract':'mad4b.company-original306313-cas-source-verifier.v2',
  'state':'CENTRAL_313_ROUTER_LIVE__CAS_306_313_PROVIDER_SOURCE_ONLY',
  'original_provider_source_files_verified':len(FILES),
  'central_live_php_parity_verified':live,
  'legacy_code_release_preserved':True,
  'cas_provider_enrolled':False,
  'original_318_signed_release_certified':False,
  'original_313_cas_certified':False,
 }


if __name__=='__main__':
 try:
  print(json.dumps(verify(json.loads(REPORT.read_text()),live=True),
                   indent=2,sort_keys=True))
 except (Refused,ValueError,OSError,KeyError,TypeError) as error:
  print(json.dumps({'state':'HOLD','reason':str(error),
                    'original_313_certified':False}))
  raise SystemExit(2)
