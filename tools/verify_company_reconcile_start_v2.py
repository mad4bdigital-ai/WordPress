#!/usr/bin/env python3
"""Strict source/live attestation. This proves code identity, not 318/313 signature."""
import argparse,hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CENTRAL=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
WAYFA=Path('/opt/wayfa-rebuild-20261102/site-copy')
DOC=ROOT/'docs/release-coverage/company-reconcile-start-v2-20261111.json'
class Refused(ValueError):pass
def verify(doc,live=False):
 if doc.get('contract')!='mad4b.company-reconcile-start-company.source-v2.v1':raise Refused('contract')
 if doc.get('source_site')!='tours.dev.mad4b.com' or doc.get('verified_runtime_target')!='wayfatours.com':
  raise Refused('site_scope')
 if doc.get('target_site_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':raise Refused('site_uuid')
 for key in ['other_companies_modified','full_clone_executed','company_resource_created','wp_options_modified',
 'prices_or_payments_modified','native_grant_issued','original_318_release_signed',
 'original_313_postcondition_signed','company_code_release_provider_registered_by_this_change']:
  if doc.get(key) is not False:raise Refused('unauthorized_authority_claim_'+key)
 for key in ['company_runtime_probe_is_unsigned','registered_target_environment_isolation',
 'source_policy_provider_ownership_in_digest','start_company_duplicate_reservation_guard',
 'read_only_rest_admin_200_guest_403_verified','not_reconciled_main_plugin_source_vs_git']:
  if doc.get(key) is not True:raise Refused('required_scope_invariant_'+key)
 if doc.get('source_policy_checks')!=34 or doc.get('source_policy_stage_count')!=4 or doc.get('mixed_ownership_count')!=7:
  raise Refused('policy_coverage_drift')
 if doc.get('workplan_offline_tests_passed')!=9 or doc.get('original_source_regression_tests_passed')!=21:
  raise Refused('unit_test_count_claim')
 files=doc.get('files')
 if not isinstance(files,list) or len(files)!=6:raise Refused('file_set_drift')
 expected=[
  'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-work-plan.php',
  'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-start-reconcile-completeness.php',
  'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-vps-company-launch.php',
  'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-operation-readiness.php',
  'ops/deployment-overlays/center/company-start-reconcile/policy.json',
  'ops/deployment-overlays/center-and-wayfa/mad4b-company-reconcile-target-evidence-v1.php'
 ]
 if [x.get('source') for x in files]!=expected:raise Refused('source_files')
 for row in files:
  if set(row)!={'source','sha256','bytes','installed'}:raise Refused('file_schema')
  if not re.fullmatch('[a-f0-9]{64}',row['sha256']):raise Refused('file_hash')
  if not isinstance(row['bytes'],int) or not 20<row['bytes']<200000:raise Refused('file_size')
  sites=row['installed']
  if not isinstance(sites,dict) or not sites or not set(sites)<=set(['central','wayfa']):raise Refused('installed_sites')
  locations=[ROOT/row['source']]
  if live:
   for name,rel in sites.items():
    if not isinstance(rel,str) or not rel.startswith('wp-content/') or '..' in rel.split('/'):
     raise Refused('path_escape')
    locations.append((CENTRAL if name=='central' else WAYFA)/rel)
  for f in locations:
   if not f.is_file() or f.is_symlink():raise Refused('missing_file')
   b=f.read_bytes()
   if len(b)!=row['bytes'] or hashlib.sha256(b).hexdigest()!=row['sha256']:
    raise Refused('file_digest_drift')
 return {'state':'CENTER_WAYFA_RECONCILE_START_V2_SOURCE_VERIFIED__318_313_HOLD',
  'files':len(files),'live':live,'source_checks':doc['source_policy_checks'],
  'source_incident_scenarios':doc['source_policy_scenarios'],
  'original_313_signed':False,'other_companies_deferred':True}
if __name__=='__main__':
 p=argparse.ArgumentParser();p.add_argument('--live',action='store_true');args=p.parse_args()
 try:print(json.dumps(verify(json.loads(DOC.read_text()),live=args.live),indent=2))
 except (Refused,OSError,ValueError) as e:
  print(json.dumps({'state':'BLOCKED','reason':str(e),'release_authorized':False}))
  raise SystemExit(2)
