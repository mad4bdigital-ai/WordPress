import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

path=Path(__file__).with_name('mcp_stdio_client.py')
spec=importlib.util.spec_from_file_location('optional_user_native_site_read',path)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class ExactNativeSiteReadTests(unittest.TestCase):
    def setUp(self):
        self.row={'company_id':'egypt-tour-gates','site_id':'primary',
            'environment':'staging','provider':'registry_ssh_wpcli',
            'provider_locator':'hosting-3:staging','wp_root':'/wordpress/staging',
            'registration_status':'enrolled','canonical_origin':'https://staging.egypttourgates.com',
            'site_uuid':'d745d81f-6fc4-5c6a-99dd-d953c92137bf'}
        self.status={'state':'WPCLI_IDENTITY_AND_SIGNED_CENTRAL_PROOF_VERIFIED',
            'readback_verified':True,'siteurl_observed':self.row['canonical_origin'],
            'site_uuid_observed':self.row['site_uuid'],
            'execution_authorized':False,'write_authorized':False}
        self.original=module.registry_remote.TARGETS
        self.remote=type('RemoteTargets',(),{'TARGETS':{
            'hosting-3:staging':{'root':'/wordpress/staging',
                                'site_uuid':self.row['site_uuid']}}})()

    def check(self,identity='host_operator',wp_user=None,registered=None,readback=None):
        records={'site_bindings':registered if registered is not None else [self.row],
                 'host_registry':{'hosting-3':{}}}
        with patch.object(module,'original_host_authority',return_value=True) as auth, \
             patch.object(module.resolver,'registry',return_value=records), \
             patch.object(module,'registry_remote',self.remote), \
             patch.object(module.resolver,'central_sites',
                return_value={('egypt-tour-gates','primary','staging'):{'identity_verified':True}}), \
             patch.object(module.wpcli_identity,'inspect',
                return_value=readback if readback is not None else self.status) as inspect:
            result=module.native_site_read('hosting-3:staging',identity,wp_user)
            auth.assert_called_once_with('hosting-3:staging')
            inspect.assert_called_once()
        return result

    def test_verified_native_read_does_not_issue_grant(self):
        result=self.check()
        self.assertEqual(result['state'],'EXACT_NATIVE_SITE_READ_VERIFIED')
        self.assertFalse(result['wordpress_user_required'])
        self.assertFalse(result['mcp_private_ability_unlocked'])
        self.assertFalse(result['grant_issued'])
        self.assertFalse(result['write_authorized'])

    def test_auto_mode_still_requires_original_host_grant(self):
        result=self.check(identity='auto')
        self.assertTrue(result['host_operator_authority_verified'])

    def test_explicit_wordpress_user_mode_rejected(self):
        with self.assertRaisesRegex(ValueError,'NONIMPERSONATING'):
            module.native_site_read('hosting-3:staging','wordpress_user','admin')

    def test_wp_user_injection_into_auto_rejected(self):
        with self.assertRaisesRegex(ValueError,'NONIMPERSONATING'):
            module.native_site_read('hosting-3:staging','auto','1')

    def test_duplicate_site_bindings_fail_closed(self):
        with self.assertRaisesRegex(ValueError,'EXACT_UNIQUE'):
            self.check(registered=[self.row,dict(self.row)])

    def test_wrong_site_uuid_fails_closed(self):
        r=dict(self.status,site_uuid_observed='b0725ffa-87e1-47e3-b0c0-c4aa4eb8882b')
        with self.assertRaisesRegex(ValueError,'PROOF_NOT_VERIFIED'):
            self.check(readback=r)

    def test_pending_signed_identity_fails_closed(self):
        r=dict(self.status,state='WPCLI_IDENTITY_OBSERVED_AWAITING_SIGNED_PROOF')
        with self.assertRaisesRegex(ValueError,'PROOF_NOT_VERIFIED'):
            self.check(readback=r)

    def test_unexpected_write_authorization_not_accepted(self):
        r=dict(self.status,write_authorized=True)
        with self.assertRaisesRegex(ValueError,'PROOF_NOT_VERIFIED'):
            self.check(readback=r)

    def test_original_operator_authority_denial_propagated(self):
        with patch.object(module,'original_host_authority',
                          side_effect=ValueError('ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED')):
            with self.assertRaisesRegex(ValueError,'ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED'):
                module.native_site_read('hosting-3:staging','host_operator',None)

if __name__=='__main__':
    unittest.main()
