#!/usr/bin/env python3
"""Source and deployed target CAS parity. Does not issue release certification."""
import hashlib
import json
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
CENTER=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
WAYFA=Path('/opt/wayfa-rebuild-20261102/site-copy')
REGISTRY=Path('/opt/mad4b-company-transport/native-313-independent/dynamic-operation-registry-v1/enrolled')
MANIFEST=ROOT/'docs/release-coverage/company-executor-time-cas-v1-provenance-20261112.json'
TARGET=[
 'wp-content/mu-plugins/mad4b-company-executor-time-cas-v1.php',
 'wp-content/mu-plugins/mad4b-company-executor-time-cas-policy-v1.json',
]
ORIGINAL=[
 'ops/dynamic-operation-registry-v1/executor-time-cas-v1/host_handler.py',
 'ops/dynamic-operation-registry-v1/executor-time-cas-v1/independent_readback.py',
 'ops/dynamic-operation-registry-v1/providers/company.resource.cas.apply/manifest.json',
 'ops/dynamic-operation-registry-v1/providers/company.resource.cas.apply/evidence-schema.json',
]
FORBIDDEN_TRUE=[
 'original_dynamic_cas_provider_enrolled',
 'original_worker_modified',
 'signed_specific_release_grant_created',
 'original_305_job_created_for_resource',
 'original_313_independent_signature_issued',
 'original_318_signed_release_certified',
 'wayfatours_postmeta_or_prices_mutated',
 'other_companies_modified',
 'full_clone_executed',
 'public_apply_rest_exposed',
 'original_broker_private_key_read_by_tests',
]
class Hold(ValueError):
 pass

def check(data,live=False):
 if data.get('contract')!='mad4b.company.original-executor-time-cas.v1.provenance':
  raise Hold('wrong_manifest_contract')
 if data.get('as_of')!='2026-11-12' or data.get('central')!='tours.dev.mad4b.com' or data.get('tenant')!='wayfatours.com' or data.get('target_resource')!='wordpress.post:61152':
  raise Hold('wrong_company_or_resource_scope')
 if data.get('site_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':
  raise Hold('wrong_site_uuid')
 for name in FORBIDDEN_TRUE:
  if data.get(name) is not False:
   raise Hold('unverified_original_release_claim_'+name)
 for name in ('target_cas_source_deployed_both_sites','source_owned_catalog_excludes_prices','live_target_source_digest_match','live_wrong_source_digest_rejected','source_only_mutating_provider'):
  if data.get(name) is not True:
   raise Hold('target_source_acceptance_claim_missing_'+name)
 if data.get('tests')!={'legacy_company_reconcile_php':320,
                        'new_cas_target_php':22,
                        'original_provider_source_python':36,
                        'live_source_catalog_denies_single_price':True,
                        'guest_cas_apply_http':404,
                        'central_schema_http':200,
                        'wayfatours_home_http':200}:
  raise Hold('test_results_not_grounded')
 rows=data.get('files')
 if not isinstance(rows,list) or len(rows)!=6:
  raise Hold('unexpected_source_files')
 for idx,name in enumerate(TARGET+ORIGINAL):
  item=rows[idx]
  if item.get('source')!=name or set(item)!={'source','sha256','bytes','deployed_to','root_owned'}:
   raise Hold('unexpected_target_file_source')
  if item['deployed_to']!=(['central','wayfatours'] if idx<2 else []) or item['root_owned']!=(idx<2):
   raise Hold('unapproved_deployment_destination')
  files=[ROOT/name]
  if live and idx<2:
   files.extend((site/name for site in (CENTER,WAYFA)))
  for path in files:
   if not path.is_file() or path.is_symlink():
    raise Hold('source_missing_or_symlink')
   current=path.read_bytes()
   if (len(current)!=item['bytes'] or
       hashlib.sha256(current).hexdigest()!=item['sha256']):
    raise Hold('source_hash_mismatch_'+name)
   if live and idx<2 and path!=ROOT/name:
    stat=path.stat()
    if stat.st_uid!=0 or stat.st_mode&0o022:
     raise Hold('deployed_code_writable_or_not_root')
 manifest=json.loads((ROOT/ORIGINAL[2]).read_text())
 base=ROOT/'ops/dynamic-operation-registry-v1'
 if manifest.get('operation_id')!='company.resource.cas.apply' or manifest.get('version')!=1 or manifest.get('cas_scope',{}).get('allowed_meta_keys')!=['rate_title'] or manifest.get('cas_scope',{}).get('commercial_pricing_allowed') is not False:
  raise Hold('mutation_scope_not_exact')
 for component in ('handler','verifier','evidence'):
  path=base/manifest[component]['path']
  if hashlib.sha256(path.read_bytes()).hexdigest()!=manifest[component]['sha256']:
   raise Hold('provider_source_not_pinned')
 if live:
  original_index=json.loads((REGISTRY/'index.json').read_text())
  if any('company.resource.cas.apply' in str(record)
         for record in original_index['providers']):
   raise Hold('original_provider_enrolled_but_provenance_says_source_only')
 return {
  'state':'CENTRAL_WAYFATOURS_SOURCE_SCOPED_CAS_VERIFIED__ORIGINAL_313_HOLD',
  'target_files_verified':2,
  'original_source_only_files_verified':4,
  'live_file_parity':live,
  'original_305_job_issued':False,
  'original_313_certified':False,
  'original_318_release_certified':False,
 }
if __name__=='__main__':
 try:
  print(json.dumps(check(json.loads(MANIFEST.read_text()),True),indent=2))
 except (ValueError,OSError,KeyError,TypeError) as error:
  print(json.dumps({'state':'HOLD','reason':str(error),
                    'signed_release_certified':False}))
  raise SystemExit(2)
