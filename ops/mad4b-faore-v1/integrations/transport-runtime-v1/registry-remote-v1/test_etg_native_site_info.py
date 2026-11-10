import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

p=Path(__file__).with_name('mcp_stdio_client.py')
spec=importlib.util.spec_from_file_location('etg_native_site_info_mcp',p)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

ROUTE='hosting-3:staging'
UUID=m.registry_remote.TARGETS[ROUTE]['site_uuid']
ROOT=m.registry_remote.TARGETS[ROUTE]['root']
BINDING={'provider':'registry_ssh_wpcli','provider_locator':ROUTE,
         'registration_status':'enrolled','site_uuid':UUID,
         'wp_root':ROOT,'canonical_origin':'https://staging.egypttourgates.com'}
BASE=['/usr/local/bin/wp','--path='+ROOT,'--skip-themes']
PREFIX=['ssh','-T','-o','StrictHostKeyChecking=yes']
HOST='pinned-site@registered-host'

class ETGNativeReadScopeTests(unittest.TestCase):
    def test_original_host_operator_exact_read_is_nonimpersonating(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'bound_route',return_value=(BASE,PREFIX,HOST)),\
             patch.object(m.resolver,'registry',return_value={'site_bindings':[BINDING]}),\
             patch.object(m.registry_remote,'remote',return_value='u783534439_caN4L\n') as remote:
            cmd=m.command_for(ROUTE,'host_operator',None)
        self.assertEqual(cmd[:len(PREFIX)],PREFIX)
        self.assertEqual(cmd[len(PREFIX)],HOST)
        self.assertIn('MAD4B_CLI_TARGET_SITE_UUID='+UUID,cmd[-1])
        self.assertIn('MAD4B_CLI_TARGET_ORIGIN='+BINDING['canonical_origin'],cmd[-1])
        self.assertIn('MAD4B_CLI_TARGET_DATABASE=u783534439_caN4L',cmd[-1])
        self.assertIn('MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=none',cmd[-1])
        self.assertIn('mcp-adapter serve',cmd[-1])
        self.assertNotIn('--user=',cmd[-1])
        remote.assert_called_once()

    def test_wrong_target_site_binding_refused(self):
        bad=dict(BINDING,site_uuid='00000000-0000-4000-8000-000000000000')
        with patch.object(m.resolver,'registry',return_value={'site_bindings':[bad]}),\
             patch.object(m.registry_remote,'remote') as remote:
            with self.assertRaisesRegex(ValueError,'EXACT_ENROLLED'):
                m.exact_etg_host_read_env(BASE)
            remote.assert_not_called()

    def test_duplicate_binding_refused(self):
        with patch.object(m.resolver,'registry',return_value={'site_bindings':[BINDING,BINDING]}),\
             patch.object(m.registry_remote,'remote') as remote:
            with self.assertRaisesRegex(ValueError,'EXACT_ENROLLED'):
                m.exact_etg_host_read_env(BASE)
            remote.assert_not_called()

    def test_untrusted_database_readback_refused(self):
        with patch.object(m.resolver,'registry',return_value={'site_bindings':[BINDING]}),\
             patch.object(m.registry_remote,'remote',return_value='bad db ; invalid\n'):
            with self.assertRaisesRegex(ValueError,'DATABASE_READBACK'):
                m.exact_etg_host_read_env(BASE)

    def test_auto_mode_does_not_inject_native_scope(self):
        with patch.object(m,'bound_route',return_value=(BASE,PREFIX,HOST)),\
             patch.object(m,'exact_etg_host_read_env') as native:
            cmd=m.command_for(ROUTE,'auto',None)
            native.assert_not_called()
            self.assertNotIn('MAD4B_CLI_TARGET_',cmd[-1])

    def test_explicit_wordpress_user_keeps_original_route(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'bound_route',return_value=(BASE,PREFIX,HOST)),\
             patch.object(m,'exact_etg_host_read_env') as native:
            cmd=m.command_for(ROUTE,'wordpress_user','editor')
            native.assert_not_called()
            self.assertIn('--user=editor',cmd[-1])

    def test_host_operator_original_authority_denial_preserved(self):
        with patch.object(m,'original_host_authority',
             side_effect=ValueError('ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED')),\
             patch.object(m,'bound_route') as bound:
            with self.assertRaisesRegex(ValueError,'ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED'):
                m.command_for(ROUTE,'host_operator',None)
            bound.assert_not_called()

if __name__=='__main__':
    unittest.main()
