#!/usr/bin/env python3
"""Read-only native Site Profile parity; never a signed write grant."""
import pathlib,runpy,unittest
SOURCE=pathlib.Path(__file__).with_name('company-native-external-enrollment-readback.py')
mod=runpy.run_path(str(SOURCE),run_name='_hermetic')
UUID='0c7786ba-a025-4650-9ceb-32a51d74aaec'
ORIGIN='https://egypttourgates.com'
def fixture():
    site={'company_id':'egypt-tour-gates','environment':'production',
          'origin':ORIGIN,'ssh_target':'hosting-3','ssh_username':'u123456789'}
    central={'company':'egypt-tour-gates','environment':'production','site_uuid':UUID,
      'canonical_origin':ORIGIN,'standing_grant_exists':False,
      'central_native_readonly_identity_verified':True,
      'production_environment_assignment_state':'active',
      'production_environment_assignment_binding_current':True,
      'production_environment_assignment_uuid':UUID,
      'production_environment_owner_operations_authorized':True}
    remote={'contract':'mad4b.company-remote-exact-site-read.v1',
      'home':ORIGIN,'siteurl':ORIGIN,'wordpress_environment':'production',
      'site_profile_installed':True,'site_profile_plugin_active':True,
      'site_profile_loaded':True,'site_profile_uuid':UUID,
      'site_profile_environment':'production','site_profile_origin':ORIGIN,
      'site_profile_revision':1,'site_profile_audit_ready':True,
      'site_profile_governed_write':False,
      'company_code':None,'company_site_uuid':None}
    return site,central,remote
class ReadonlyIdentity(unittest.TestCase):
    def test_native_profile_is_not_duplicate_identity_request(self):
        row,c,r=fixture()
        self.assertEqual(mod['classify'](row,c,r),(
          'CENTRAL_SSH_NATIVE_IDENTITY_AND_OWNER_VERIFIED_WRITE_GRANT_MISSING',
          'ORIGINAL_SIGNED_JOB_SCOPED_305_312_313_REQUIRED'))
        self.assertFalse(c['standing_grant_exists'])
    def test_all_missing_owner_and_profile_pins_fail_closed(self):
        for key,bad in (
          ('central_native_readonly_identity_verified',False),
          ('production_environment_assignment_state','approved'),
          ('production_environment_assignment_binding_current',False),
          ('production_environment_assignment_uuid','b8dffda1-db99-4789-aa72-86bf63f557cc'),
          ('production_environment_owner_operations_authorized',False),
          ('canonical_origin','https://wrong.example'),
        ):
            row,c,r=fixture();c[key]=bad
            with self.subTest(field=key):
                self.assertEqual(mod['classify'](row,c,r)[0],'REMOTE_COMPANY_IDENTITY_NOT_BOUND')
        for key,bad in (
          ('site_profile_audit_ready',False),('site_profile_governed_write',True),
          ('home','https://wrong.example'),('siteurl','https://wrong.example'),
          ('site_profile_origin','https://wrong.example'),
          ('site_profile_environment','staging'),
          ('wordpress_environment','staging'),
          ('site_profile_revision',0),
        ):
            row,c,r=fixture();r[key]=bad
            with self.subTest(field=key):
                self.assertEqual(mod['classify'](row,c,r)[0],'REMOTE_COMPANY_IDENTITY_NOT_BOUND')
    def test_uuid_drift_is_not_hidden(self):
        row,c,r=fixture();r['site_profile_uuid']='b8dffda1-db99-4789-aa72-86bf63f557cc'
        self.assertEqual(mod['classify'](row,c,r)[0],'SITE_PROFILE_UUID_MISMATCH')
    def test_original_remote_identity_still_needs_grant(self):
        row,c,r=fixture();r['company_code']=row['company_id'];r['company_site_uuid']=UUID
        self.assertEqual(mod['classify'](row,c,r)[0],'ORIGINAL_GRANT_OR_TRANSPORT_NOT_ENROLLED')
    def test_staging_does_not_inherit_production(self):
        row,c,r=fixture();row['environment']='staging'
        self.assertEqual(mod['classify'](row,c,r)[0],'REMOTE_COMPANY_IDENTITY_NOT_BOUND')
    def test_original_wrong_host_remains_blocked(self):
        row,c,r=fixture();c.update(standing_grant_exists=True,
                 standing_grant_target_host_id='srv2030908',
                 standing_transport_host_id='srv2030908',
                 standing_grant_target_ssh_principal='root',
                 standing_transport_host_principal='root')
        self.assertEqual(mod['classify'](row,c,r)[0],
                         'ORIGINAL_305_LOCAL_PAIR_INCOMPATIBLE_WITH_REMOTE_HOST')
    def test_original_readonly_contract_unchanged(self):
        src=SOURCE.read_text()
        self.assertIn("'write_authorized_by_readback'=>false",src)
        self.assertIn('"new_grants_issued":0',src)
        self.assertIn('"tenant_write_executed":False',src)
        self.assertNotIn('wp_set_current_user',src)
        self.assertNotIn('--user=',src)
if __name__=='__main__':unittest.main(verbosity=2)
