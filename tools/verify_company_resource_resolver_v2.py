#!/usr/bin/env python3
"""Verifies source parity, exact company scope and absence of signed-release claims."""
import argparse, hashlib, json, os, subprocess
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
LIVE=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
MANIFEST=ROOT/'docs/release-coverage/company-resource-resolver-v2-provenance-20261111.json'
INC='wp-content/plugins/mad4b-company-portfolio-manager/includes/'
EXPECTED=[
 INC+'class-mad4b-company-repair-resource-resolver.php',
 INC+'class-mad4b-company-repair-capability-discovery.php',
 INC+'class-mad4b-company-repair-job-contract.php',
 INC+'class-mad4b-company-repair-jobs.php']
POLICY='ops/deployment-overlays/center/company-start-reconcile/policy.json'
POLICY_SHA='7108762fa323f49b161c31b7fb5c5b0177a89eccf2581be5a7b175b167006d37'
class Blocked(ValueError):pass
HISTORICAL_V2_COMMIT='a7ea8620e1'
def verify(data,with_live=False,historical_revision=None):
 if historical_revision is not None:
  if with_live or historical_revision!=HISTORICAL_V2_COMMIT:
   raise Blocked('historical_commit_or_live_mode_invalid')
 def source_bytes(relative_path):
  if historical_revision is None:
   p=ROOT/relative_path
   if not p.is_file() or p.is_symlink():raise Blocked('source_missing_or_symlink')
   return p.read_bytes()
  p=subprocess.run(['git','show',historical_revision+':'+relative_path],
     cwd=ROOT,capture_output=True,timeout=14)
  if p.returncode:raise Blocked('historical_source_not_in_git')
  return p.stdout
 if data.get('contract')!='mad4b.company-repair.resource-resolver-v2.source-provenance.v1':
  raise Blocked('manifest_contract')
 if data.get('central_source')!='tours.dev.mad4b.com' or data.get('target_observed')!='wayfatours.com':
  raise Blocked('source_target_scope')
 if data.get('target_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':
  raise Blocked('site_uuid')
 for key in ['wayfa_production_modified','full_clone_executed','native_305_job_issued',
  'original_312_signed','original_313_signed','original_318_signed_deployment',
  'central_exact_resource_job_created','source_policy_modified',
  'static_operation_map_reintroduced','independent_receipt_mocked_as_real',
  'resource_index_paged_at_scale']:
  if data.get(key) is not False:raise Blocked('forged_or_inaccurate_claim_'+key)
 if data.get('no_other_companies_modified') is not True:raise Blocked('other_company_scope')
 for key,expected in [
  ('central_existing_logical_repair_job_count',1),('target_resource_child_journals',0),
  ('target_queue_incident_count',19)]:
  if data.get(key)!=expected:raise Blocked('runtime_count_changed_'+key)
 if data.get('target_resource_preview_state')!='RESOURCE_RESOLUTION_HOLD':
  raise Blocked('false_live_resolver_success')
 if data.get('target_resource_preview_reason')!='NO_UNAMBIGUOUS_TRUSTED_PROVIDER_RESOLVER':
  raise Blocked('false_live_provider_admission')
 expected_tests={
  'resource_resolver_assertions':51,'resource_lifecycle_assertions':28,
  'dynamic_discovery_assertions':67,'original_binding_structural_assertions':17,
  'queue_assertions':15,'reconcile_depth_assertions':35,
  'central_wordpress_regression':21,'admin_js_parsed':True,
  'live_resource_endpoint_admin_http':200,'live_resource_endpoint_guest_http':401
 }
 if data.get('tests')!=expected_tests:raise Blocked('test_count_changed')
 policy=source_bytes(POLICY)
 if hashlib.sha256(policy).hexdigest()!=POLICY_SHA:
  raise Blocked('source_policy_modified')
 doc=json.loads(policy)
 if len(doc.get('incident_scenarios',[]))!=19 or 'repair_job_operation_map' in doc:
  raise Blocked('dynamic_catalog_policy_invalid')
 rows=data.get('files')
 if not isinstance(rows,list) or len(rows)!=4 or [f.get('source') for f in rows]!=EXPECTED:
  raise Blocked('unexpected_files')
 for entry in rows:
  if set(entry)!={'source','installed','sha256','bytes','installed_root_owned'}:
   raise Blocked('file_schema')
  if entry['installed']!=entry['source'] or entry['installed_root_owned'] is not True:
   raise Blocked('unexpected_install_path_or_owner')
  if not isinstance(entry['bytes'],int) or entry['bytes']<1000 or entry['bytes']>200000:
   raise Blocked('file_size_invalid')
  parts=[source_bytes(entry['source'])]
  if with_live:
   file=LIVE/entry['installed']
   if not file.is_file() or file.is_symlink():raise Blocked('file_missing_or_symlink')
   parts.append(file.read_bytes())
  for data_bytes in parts:
   if len(data_bytes)!=entry['bytes'] or hashlib.sha256(data_bytes).hexdigest()!=entry['sha256']:
    raise Blocked('file_digest_changed')
  if with_live:
   stat=(LIVE/entry['installed']).stat()
   if stat.st_uid!=0 or stat.st_mode&0o022:
    raise Blocked('source_code_not_root_owned')
 return {
  'state':'CENTRAL_DYNAMIC_RESOURCE_RESOLVER_V2_SOURCE_VERIFIED__318_313_HOLD',
  'root_owned_live_modules':4 if with_live else None,
  'policy_unchanged':True,
  'read_only_wayfa_preview':True,
  'resource_child_jobs_executed':0,
  'original_313_signed':False,
  'live_source_parity':with_live,
  'historical_commit_verified':historical_revision
 }
if __name__=='__main__':
 parser=argparse.ArgumentParser()
 parser.add_argument('--live',action='store_true')
 parser.add_argument('--historical-v2',action='store_true',
   help='Validate the unchanged V2 manifest against its original Git commit instead of current source')
 args=parser.parse_args()
 try:print(json.dumps(verify(json.loads(MANIFEST.read_text()),args.live,
    HISTORICAL_V2_COMMIT if args.historical_v2 else None),indent=2))
 except (Blocked,OSError,ValueError) as exc:
  print(json.dumps({'state':'HOLD','reason':str(exc),'release_certified':False}))
  raise SystemExit(2)
