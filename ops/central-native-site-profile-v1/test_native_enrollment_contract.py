#!/usr/bin/env python3
import importlib.util,pathlib,unittest
P=pathlib.Path(__file__).parent
def load(filename):
    spec=importlib.util.spec_from_file_location('mad4b_'+filename.replace('.','_'),P/filename)
    m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m);return m
host=load('host_enroll.py')
register=load('central_register.py')
class Contract(unittest.TestCase):
    def probe(self):
        return dict(contract='mad4b.central.native-site-profile.probe.v1',
          origin='https://egypttourgates.com',siteurl='https://egypttourgates.com',
          environment='production',wp_cli=True,user_id=0,
          php_sha256=host.PHP_SHA,native_method_exists=True,
          profile_exists=False,configured=False,write_enabled=False,audit_ready=True)
    def outcome(self):
        return dict(contract='mad4b.central.native-site-profile.saved.v1',
          origin='https://egypttourgates.com',environment='production',
          site_uuid='0c7786ba-a025-4650-9ceb-32a51d74aaec',
          revision=1,configured=True,origin_match=True,environment_match=True,
          profile_digest='a'*64,features={'write':False,'oauth':False,'skills':False,
          'managed_runtime':False,'acceptance':False},oauth_user_count=0,audit_ready=True,
          audit_count=5,wp_user=0,write_enabled=False)
    def test_exact_scope(self):
        r=dict(company_id='egypt-tour-gates')
        host.check_scope(r,self.probe(),host.ALLOW['egypt-tour-gates'])
        for key,value in [('origin','https://wrong.example'),('environment','staging'),
          ('user_id',1),('php_sha256','0'*64),('audit_ready',False),('native_method_exists',False),
          ('profile_exists',True),('configured',True)]:
            v=self.probe();v[key]=value
            with self.subTest(key=key),self.assertRaises(RuntimeError):
                host.check_scope(r,v,host.ALLOW['egypt-tour-gates'])
    def test_independent_postwrite(self):
        host.ensure_after(self.outcome(),'https://egypttourgates.com')
        for key,val in [('origin','https://bad.example'),('site_uuid','invalid'),
          ('profile_digest','0'),('revision',2),('oauth_user_count',1),
          ('write_enabled',True),('audit_ready',False),('wp_user',12)]:
            x=self.outcome();x[key]=val
            with self.subTest(key=key),self.assertRaises(RuntimeError):
                host.ensure_after(x,'https://egypttourgates.com')
    def test_no_impersonation_and_fixed_host_pins(self):
        s=(P/'host_enroll.py').read_text()
        self.assertIn('StrictHostKeyChecking=yes',s)
        self.assertIn('HostKeyAlgorithms=ssh-ed25519',s)
        self.assertNotIn('wp_set_current_user',s)
        self.assertNotIn('--user=',s)
        self.assertIn('validate_record_for_test',host.site_probe())
        self.assertIn('wp_cache_delete',host.site_probe())
    def test_central_registration_readonly(self):
        r=register.central_code('egypt-tour-gates')
        self.assertIn('mad4b_tourism_company_site_registry_v1',r)
        self.assertIn('native_write_authorized',r)
        self.assertNotIn('wp_set_current_user',r)
if __name__=='__main__':unittest.main(verbosity=2)
