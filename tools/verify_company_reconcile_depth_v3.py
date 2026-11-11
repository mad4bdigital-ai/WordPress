#!/usr/bin/env python3
"""Fail-closed provenance check: source bytes, scope and no forged authority."""
import argparse,hashlib,json,re
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
CENTRAL=Path('/opt/hostinger-migrations/hosting-3/etg-commerce-staging/site-copy')
DOC=ROOT/'docs/release-coverage/company-reconcile-depth-v3-provenance-20261111.json'
REPORT=Path('/opt/mad4b-company-provisioning/reports/company-reconcile-depth-v3-20261111')
class Refused(ValueError):pass
EXPECTED=[
 'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-depth-audit.php',
 'wp-content/plugins/mad4b-company-portfolio-manager/includes/class-mad4b-company-reconcile-work-plan.php',
 'ops/deployment-overlays/center/company-start-reconcile/policy.json']
def verify(data,live=False):
 if data.get('contract')!='mad4b.company-reconcile-depth-v3.provenance.v1':raise Refused('contract')
 if data.get('site_scope')!=['tours.dev.mad4b.com','wayfatours.com']:raise Refused('scope')
 if data.get('code_deployment_site')!='tours.dev.mad4b.com':raise Refused('unexpected_code_target')
 if data.get('target_site_uuid')!='a3a7cc36-db7e-4a14-8456-b41857def05c':raise Refused('identity')
 for key in ('other_companies_touched','wayfa_wordpress_mutated','commercial_settings_mutated',
  'full_clone_performed','company_grant_issued','original_318_signed','original_313_signed',
  'reconcile_execution_authorized','company_manager_main_plugin_replaced'):
  if data.get(key) is not False:raise Refused('authorization_claim_'+key)
 for key,val in (('check_count',34),('incident_count',19),('dependency_edges',57),
                 ('dependency_waves',11),('local_probe_count',10)):
  if data.get(key)!=val:raise Refused('counts_'+key)
 c=data.get('incident_triage_counts',{})
 desired={'LOCAL_PASS_NOT_CERTIFIED':5,'LOCAL_ADVISORY_REVIEW':2,'LOCAL_DRIFT_REVIEW':0,
  'TARGET_PROBE_UNVERIFIED':5,'TARGET_PROBE_DESIGN_REQUIRED':3,
  'ORIGINAL_PROVIDER_RECEIPT_REQUIRED':3,'COMPANY_PRE_POST_DIGEST_REQUIRED':1,
  'NAMED_TARGET_PROBE_NOT_REPORTED':0}
 if c!=desired:raise Refused('triage_counts_drift')
 if not isinstance(data.get('files'),list) or [x.get('source') for x in data['files']]!=EXPECTED:
  raise Refused('files_exact_scope')
 if data.get('tests',{}).get('offline_assertions_passed')!=35 or data.get('tests',{}).get('central_wpcli_regression_passed')!=21:
  raise Refused('unverified_tests_claim')
 for f in data['files']:
  if set(f)!={'source','installed','sha256','bytes'}:raise Refused('file_schema')
  rel=f['installed']
  if not isinstance(rel,str) or not rel.startswith('wp-content/') or '..' in rel.split('/'):
   raise Refused('path_escape')
  if not re.fullmatch('[a-f0-9]{64}',f['sha256']):raise Refused('digest_shape')
  for p in [ROOT/f['source']]+([CENTRAL/rel] if live else []):
   if not p.is_file() or p.is_symlink():raise Refused('missing')
   b=p.read_bytes()
   if len(b)!=f['bytes'] or hashlib.sha256(b).hexdigest()!=f['sha256']:
    raise Refused('file_hash_drift')
 if live:
  for filename,key in (('wayfa-site-local-probe.json','local_probe_report_sha256'),
                       ('wayfa-incident-triage.json','local_triage_report_sha256')):
   p=REPORT/filename
   if not p.is_file() or hashlib.sha256(p.read_bytes()).hexdigest()!=data.get(key):
    raise Refused('local_evidence_changed')
 return {'state':'CENTER_COMPANY_RECONCILE_DEPTH_V3_VERIFIED__318_313_HOLD',
  'live_installed_matches_source':live,'checks':34,'incident_playbooks':19,
  'dependency_edges':57,'diagnostic_waves':11,'signed_313':False}
if __name__=='__main__':
 parser=argparse.ArgumentParser();parser.add_argument('--live',action='store_true')
 args=parser.parse_args()
 try:print(json.dumps(verify(json.loads(DOC.read_text()),args.live),indent=2))
 except (Refused,ValueError,OSError) as exc:
  print(json.dumps({'state':'HOLD','reason':str(exc),'release_certified':False}))
  raise SystemExit(2)
