"""Exact registered-company site readback cannot manufacture Original305/313 rights."""
from pathlib import Path
import importlib.util
import subprocess
import unittest
from unittest.mock import patch

ROOT=Path(__file__).resolve().parents[2]
SRC=ROOT/'ops/host-authority-v146/external_host_evidence.py'
spec=importlib.util.spec_from_file_location('v146_external_readback',SRC)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

def valid(site):
    co,env,host,uuid,origin,path=site
    remote={'siteurl':origin,'home':origin,'wp_environment':env,
            'profile_environment':env,'site_uuid':uuid,'company_code':co}
    central={'site_uuid':uuid,'canonical_origin':origin}
    operator={'site_uuid':uuid,'canonical_origin':origin,
              'registry_binding':host+':'+env,'expected_root':path}
    return remote,central,operator

class HostEvidence(unittest.TestCase):
    def test_exact_all_five_known_remote_sites(self):
        self.assertEqual(len(m.SITES),5)
        self.assertEqual(set(s[2] for s in m.SITES),
                         {'hosting-1','hosting-2','hosting-3'})
        self.assertEqual(set(s[0] for s in m.SITES),
                         {'tourism-almallahgroup','all-royal-egypt','egypt-tour-gates'})
        for s in m.SITES:
            with self.subTest(s=s[:3]):
                a,b,c=valid(s)
                result=m.validate(a,b,c,s)
                self.assertTrue(result['host_company_binding_readonly_verified'])
                self.assertFalse(result['host_write_authorized'])
                self.assertFalse(result['original313_predecessor_verified'])
                self.assertFalse(result['original312_signed_exact_job_verified'])
                self.assertFalse(result['owner_v3_stepup_signed_verified'])
    def test_wrong_identity_or_cross_company_scope_fails_closed(self):
        for index,field,new in [(0,'home','https://wrong.example'),
                                (0,'site_uuid','bad'),
                                (0,'wp_environment','staging'),
                                (0,'company_code','other-company'),
                                (1,'canonical_origin','https://changed.example'),
                                (1,'site_uuid','bad'),
                                (2,'registry_binding','hosting-3:production'),
                                (2,'site_uuid','not-enrolled'),
                                (2,'expected_root','/other/site')]:
            with self.subTest(index=index,field=field):
                e=list(valid(m.SITES[0]))
                e[index][field]=new
                with self.assertRaises(m.Denied):
                    m.validate(*e,m.SITES[0])
    def test_no_arbitrary_remote_shell_or_private_signing(self):
        source=SRC.read_text()
        for x in ('shell=True','StrictHostKeyChecking=no','ssh-keyscan',
                  'Ed25519PrivateKey.generate','wp option update',
                  'sodium_crypto_sign','register_rest_route(',
                  'maintenance.shell','--allow-root'):
            self.assertNotIn(x,source)
        self.assertIn("'--skip-plugins','--skip-themes'",source)
        self.assertIn("'StrictHostKeyChecking=yes'",source)
        self.assertIn('host_write_authorized',source)
        self.assertIn('WORDPRESS_READONLY_BINDING_PROJECTION_NOT_INSTALLED',source)
    def test_five_scopes_unique_and_never_derive_other_hosted_sites(self):
        seen=set()
        for company,env,host,uuid,origin,path in m.SITES:
            key=(company,env)
            self.assertNotIn(key,seen)
            seen.add(key)
            self.assertIn(host,('hosting-1','hosting-2','hosting-3'))
            self.assertTrue(m.UUID.fullmatch(uuid))
        self.assertNotIn('wovacation',str(m.SITES))
        self.assertNotIn('donatours',str(m.SITES))
    def test_root_only_auditor_without_network_raises(self):
        with patch.object(m.os,'geteuid',return_value=1000):
            with self.assertRaises(m.Denied):
                m.inspect(central={},policy={},v144=object())
    def test_site_origin_fingerprint_is_not_a_signed_authority(self):
        a,b,c=valid(m.SITES[1]);x=m.validate(a,b,c,m.SITES[1])
        for field in ('original305_signed_grant_verified',
                      'original312_signed_exact_job_verified',
                      'original313_predecessor_verified',
                      'owner_v3_stepup_signed_verified',
                      'host_write_authorized'):
            self.assertIs(x[field],False)
        self.assertEqual(x['next_action'],'ORIGINAL_FIRSTPARTY_SIGNED_COMPANY_SCOPE_ENROLLMENT')

if __name__=='__main__':unittest.main()
