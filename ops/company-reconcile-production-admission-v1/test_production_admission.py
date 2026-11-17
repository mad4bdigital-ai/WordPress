#!/usr/bin/env python3
"""Hermetic first-party admission tests. No network/WordPress/SSH required."""
from __future__ import annotations
import copy,datetime as dt,importlib.util,pathlib,unittest
p=pathlib.Path(__file__).with_name('company-reconcile-production-admission.py')
spec=importlib.util.spec_from_file_location('mad4b_company_production_admission',p)
m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
NOW=dt.datetime(2026,11,17,13,0,tzinfo=dt.timezone.utc)
TS='2026-11-17T12:58:00+00:00'
def remote(company='egypt-tour-gates',env='production',uuid=None):
 return {'company_code':company,'environment':env,'canonical_origin':'https://egypttourgates.com',
  'authenticated_pinned_ssh_read':True,'wordpress_origin_environment_verified':True,
  'remote_site_uuid':uuid,'remote_company_site_uuid':uuid,'central_site_uuid':uuid,
  'original_grant_present':False,'original_transport_state':None,
  'production_environment_owner_operations_authorized':False}
def sample():
 return ({'contract':'mad4b.company-native-external-enrollment-doctor.v1',
          'observed_utc':TS,'read_only':True,'tenant_write_executed':False,'remote_sites':[remote()]},
         {'contract':'mad4b.company-reconcile.host-asset-observation.v1','observed_utc':TS,
          'full_wpcli_inventory_certified':False,'read_only':True,'company_code':'wayfatours',
          'environment':'production','catalog_files':11,'different_files':0},
         {'contract':'mad4b.company-native-universal-readiness.v1'})
class Tests(unittest.TestCase):
 def project(self,*a,ops=None):
  e,h,n=sample()
  if a:
   if a[0]:e.update(a[0])
   if len(a)>1 and a[1]:h.update(a[1])
   if len(a)>2 and a[2]:n.update(a[2])
  return m.project(e,h,n,ops or {'company.code.release'},NOW)
 def test_owner_link_only_no_identity_forgery(self):
  x=self.project();r=x['scopes'][0];self.assertEqual(r['state'],'OWNER_SITE_PROFILE_SAVE_REQUIRED')
  self.assertEqual(r['owner_site_profile_url'],'https://egypttourgates.com/wp-admin/admin.php?page=mad4b-control-plane-site-profile')
  self.assertFalse(r['native_writes_authorized']);self.assertEqual(x['enrollments_created'],0)
 def test_host_zero_file_difference_is_not_original_signed_receipt(self):
  x=self.project();self.assertEqual(x['wayfatours_appearance']['state'],'HOST_ASSET_SHA_PARITY_OBSERVED_NO_NEW_JOB_REQUIRED')
  self.assertFalse(x['wayfatours_appearance']['original_313_receipt_issued'])
 def test_signed_305_not_issued_by_good_pair(self):
  identity='38a8a4fa-f103-4db4-9a71-31bb13a20341'
  x=self.project({'remote_sites':[remote(uuid=identity)]})
  self.assertEqual(x['scopes'][0]['state'],'ORIGINAL_OWNER_DELEGATION_NOT_VERIFIED')
  self.assertFalse(x['scopes'][0]['signed_312_job_ready'])
 def test_production_delegation_requires_all_independent_owner_proofs(self):
  uuid='38a8a4fa-f103-4db4-9a71-31bb13a20341'
  r=remote(company='all-royal-egypt',uuid=uuid)
  r.update({'central_owner_delegation_active':True,
     'central_assignment_matches_target_site_uuid':False,
     'central_admin_has_exact_tenant_identity':False,
     'production_environment_owner_operations_authorized':True})
  self.assertEqual(self.project({'remote_sites':[r]})['scopes'][0]['state'],
                   'ORIGINAL_OWNER_DELEGATION_NOT_VERIFIED')
  for missing in ['central_owner_delegation_active','central_assignment_matches_target_site_uuid',
                  'central_admin_has_exact_tenant_identity','production_environment_owner_operations_authorized']:
   q=dict(r);q.update({key:True for key in [
    'central_owner_delegation_active','central_assignment_matches_target_site_uuid',
    'central_admin_has_exact_tenant_identity','production_environment_owner_operations_authorized']})
   q[missing]=False
   self.assertEqual(self.project({'remote_sites':[q]})['scopes'][0]['state'],
                    'ORIGINAL_OWNER_DELEGATION_NOT_VERIFIED')
  q=dict(r);q.update({key:True for key in [
    'central_owner_delegation_active','central_assignment_matches_target_site_uuid',
    'central_admin_has_exact_tenant_identity','production_environment_owner_operations_authorized']})
  self.assertEqual(self.project({'remote_sites':[q]})['scopes'][0]['state'],
                   'ORIGINAL_REMOTE_305_GRANT_NOT_ISSUED')
 def test_standing_grant_does_not_sign_313(self):
  identity='38a8a4fa-f103-4db4-9a71-31bb13a20341'
  r=remote(uuid=identity);r.update({'production_environment_owner_operations_authorized':True,'central_owner_delegation_active':True,
           'central_assignment_matches_target_site_uuid':True,'central_admin_has_exact_tenant_identity':True,
           'original_grant_present':True,'original_transport_state':'REMOTE_HOSTINGER_WPCLI_ACCESS_VERIFIED'})
  x=self.project({'remote_sites':[r]});self.assertEqual(x['scopes'][0]['state'],'BOUND_REMOTE_ORIGINAL_305_STILL_REQUIRES_JOB_SCOPED_312_313')
  self.assertFalse(x['scopes'][0]['signed_313_execution_receipt'])
 def test_old_remote_evidence_fails_closed(self):
  x=self.project({'observed_utc':'2026-11-16T00:00:00+00:00'})
  self.assertEqual(x['scopes'][0]['state'],'REOBSERVE_REMOTE_ORIGINAL_IDENTITY')
 def test_old_host_evidence_fails_closed(self):
  x=self.project(None,{'observed_utc':'2026-11-15T00:00:00+00:00'})
  self.assertEqual(x['wayfatours_appearance']['state'],'HOST_ASSET_OBSERVATION_STALE')
 def test_missing_exact_provider_blocks_drift(self):
  x=self.project(None,{'different_files':3})
  self.assertEqual(x['wayfatours_appearance']['state'],'EXACT_ORIGINAL_NATIVE_APPEARANCE_HANDLER_UNREGISTERED')
 def test_registered_provider_is_not_a_signed_write(self):
  x=self.project(None,{'different_files':3},ops={'company.appearance.reconcile'})
  self.assertEqual(x['wayfatours_appearance']['state'],'EXACT_SIGNED_JOB_305_312_313_STILL_REQUIRED')
  self.assertFalse(x['wayfatours_appearance']['original_305_312_313_ready'])
 def test_wrong_remote_scope_rejected(self):
  with self.assertRaisesRegex(RuntimeError,'REMOTE_SCOPE_MALFORMED'):
   self.project({'remote_sites':[remote(company='../unsafe')]})
 def test_duplicate_scope_rejected(self):
  with self.assertRaisesRegex(RuntimeError,'DUPLICATE_ENROLLMENT_SCOPE'):
   r=remote();self.project({'remote_sites':[r,r]})
 def test_host_scope_rejected(self):
  with self.assertRaisesRegex(RuntimeError,'HOST_ASSET_SCOPE_CHANGED'):
   self.project(None,{'company_code':'egypt-tour-gates'})
 def test_unsigned_host_report_fails(self):
  with self.assertRaisesRegex(RuntimeError,'READBACK_AUTHORITY_FLAGS_INVALID'):
   self.project(None,{'full_wpcli_inventory_certified':True})
 def test_invalid_count_blocks(self):
  with self.assertRaisesRegex(RuntimeError,'HOST_ASSET_COUNT_INVALID'):
   self.project(None,{'different_files':50})
 def test_provider_manifest_hash_mismatch(self):
  entries={'contract':'mad4b.dynamic-operation-index.v1','installed_by_first_party':True,
           'providers':[{'manifest':'dynamic-operation-registry-v1/providers/example/manifest.json','sha256':'a'*64}]}
  with self.assertRaisesRegex(RuntimeError,'REGISTERED_PROVIDER_CONTENT_CHANGED'):
   m.enrolled_ops(entries,lambda p:({'contract':'mad4b.dynamic-operation-provider.v1','operation_id':'company.code.release'},'b'*64))
 def test_reject_second_hand_signed_claims(self):
  x=self.project()
  for k in ('read_only','enrollments_created','transports_enabled','grants_created','target_writes','certificates_issued'):
   self.assertIn(k,x)
  self.assertEqual(x['target_writes'],0)
 def test_timestamp_future_rejected(self):
  self.assertFalse(m.parse_timestamp('2026-11-20T12:00:00Z',NOW,3600))
 def test_all_business_scopes_remain_independent(self):
  e,h,n=sample();e['remote_sites']=[remote(),remote(company='tourism-almallahgroup')]
  x=m.project(e,h,n,set(),NOW)
  self.assertEqual(len(x['scopes']),2)
  self.assertEqual({v['state'] for v in x['scopes']},{'OWNER_SITE_PROFILE_SAVE_REQUIRED'})
if __name__=='__main__':unittest.main(verbosity=2)
