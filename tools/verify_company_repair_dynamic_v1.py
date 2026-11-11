#!/usr/bin/env python3
"""Proof of source/installed bytes and conservative authority scope, NOT a signed 318/313."""
import argparse,hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
LIVE=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
MANIFEST=ROOT/'docs/release-coverage/company-repair-jobs-dynamic-v1-provenance-20261111.json'
PREFIX='wp-content/plugins/mad4b-company-portfolio-manager/includes/'
SOURCES=[
 PREFIX+'class-mad4b-company-repair-capability-discovery.php',
 PREFIX+'class-mad4b-company-repair-job-contract.php',
 PREFIX+'class-mad4b-company-repair-jobs.php',
 PREFIX+'class-mad4b-company-reconcile-work-plan.php',
 'ops/deployment-overlays/center/company-start-reconcile/policy.json',
]
class Blocked(ValueError):pass
def verify(document,with_live=False):
 if document.get('contract')!='mad4b.company-repair-jobs.dynamic-source-provenance.v1':
  raise Blocked('wrong_manifest_contract')
 if document.get('source_site')!='tours.dev.mad4b.com' or document.get('observed_target_site')!='wayfatours.com':
  raise Blocked('unapproved_site_scope')
 if document.get('wayfa_production_site_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':
  raise Blocked('target_site_binding_mismatch')
 for name in ['scope_other_companies_touched','wayfa_target_wordpress_mutated',
   'full_clone_executed','payment_or_pricing_changed','native_305_job_submitted',
   'native_grant_created','original_313_exact_receipt_verified',
   'original_318_signed_release','company_production_release_certified',
   'main_company_manager_plugin_replaced']:
  if document.get(name) is not False:raise Blocked('unauthorized_claim_'+name)
 for name in ['central_local_repair_journal_created','dynamic_operation_map_absent',
  'resource_resolver_unenrolled','manual_original_305_required',
  'immutable_journal_upgrade_verified']:
  if document.get(name) is not True:raise Blocked('scope_invariant_'+name)
 expected_counts={
  'central_repair_journal_count':1,'repair_queue_items':19,
  'repair_queue_not_created':18,'repair_queue_integrity_holds':0,
  'provider_registry_registrations':9,'pilot_provider_candidates':0
 }
 for name,number in expected_counts.items():
  if document.get(name)!=number:raise Blocked('count_changed_'+name)
 if document.get('pilot_provider_discovery_state')!='NO_TRUSTED_PROVIDER_DECLARES_REPAIR_SUPPORT':
  raise Blocked('invented_provider_ready')
 if document.get('pilot_job_id')!='f672cfb110bbb5419a59738e8a05c0114aa98de9':
  raise Blocked('pilot_journal_changed')
 tests=document.get('tests')
 if not isinstance(tests,dict) or tests!={'dynamic_job_assertions':62,
  'original_binding_structural_assertions':17,'reconcile_depth_assertions':35,
  'queue_assertions':15,'wordpress_live_regression':21,'admin_js_parsed':True,
  'admin_queue_http':200,'guest_queue_http':401}:
  raise Blocked('test_results_inconsistent')
 files=document.get('files')
 if not isinstance(files,list) or len(files)!=len(SOURCES) or [f.get('source') for f in files]!=SOURCES:
  raise Blocked('unexpected_or_missing_source_file')
 for f in files:
  if not isinstance(f,dict) or set(f)!=set(['source','installed','sha256','bytes']):
   raise Blocked('source_file_schema')
  if not re.fullmatch(r'[0-9a-f]{64}',f['sha256']):
   raise Blocked('source_hash_invalid')
  if not isinstance(f['bytes'],int) or not 500<f['bytes']<200000:
   raise Blocked('source_size_invalid')
  path=f['installed']
  if not isinstance(path,str) or not path.startswith('wp-content/') or '..' in path.split('/'):
   raise Blocked('installed_path_unsafe')
  paths=[ROOT/f['source']]+([LIVE/path] if with_live else [])
  for src in paths:
   if not src.is_file() or src.is_symlink():raise Blocked('file_missing_or_symlink')
   b=src.read_bytes()
   if len(b)!=f['bytes'] or hashlib.sha256(b).hexdigest()!=f['sha256']:
    raise Blocked('source_or_installed_digest_changed')
 raw_policy=(ROOT/SOURCES[-1]).read_text()
 policy=json.loads(raw_policy)
 if len(policy.get('incident_scenarios',[]))!=19:
  raise Blocked('source_incident_count_changed')
 if sum(len(v) for v in policy.get('stages',{}).values())!=34:
  raise Blocked('source_check_count_changed')
 if len(policy.get('check_dependencies',{}))!=34:
  raise Blocked('dependency_graph_drift')
 if 'repair_job_operation_map' in policy or 'repair_job_operation_scope' in policy:
  raise Blocked('static_operation_map_reintroduced')
 if policy.get('repair_provider_discovery_mode')!='trusted_original_registry_scoped_readback_dynamic' or \
    policy.get('repair_ambiguous_provider_policy')!='hold_no_auto_selection':
  raise Blocked('dynamic_registry_policy_weakened')
 return {
  'state':'CENTER_DYNAMIC_REPAIR_SOURCE_VERIFIED__ORIGINAL_318_313_HOLD',
  'source_files':len(files),'live_identical':with_live,
  'current_incident_count':19,'original_313_signed':False,
  'other_companies_mutated':False,'exact_resource_resolver_ready':False
 }
if __name__=='__main__':
 parser=argparse.ArgumentParser()
 parser.add_argument('--live',action='store_true')
 args=parser.parse_args()
 try:print(json.dumps(verify(json.loads(MANIFEST.read_text()),args.live),indent=2))
 except (Blocked,OSError,ValueError) as exc:
  print(json.dumps({'state':'HOLD','reason':str(exc),'release_certified':False}))
  raise SystemExit(2)
