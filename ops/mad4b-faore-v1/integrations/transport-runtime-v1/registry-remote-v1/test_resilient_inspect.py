import importlib.util
import unittest
from pathlib import Path
from unittest.mock import patch

source=Path(__file__).with_name('mcp_stdio_client.py')
spec=importlib.util.spec_from_file_location('resilient_mcp_client',source)
m=importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)
TARGET='hosting-3:staging'


class ResilientReadTests(unittest.TestCase):
    def test_mcp_tool_success_does_not_need_fallback(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'inspect',return_value={'state':'MCP_CONNECTED_WITH_TOOLS',
                'read_tool_pass':True}),\
             patch.object(m,'native_site_read') as native:
            outcome=m.resilient_inspect(TARGET,'host_operator',None)
        self.assertEqual(outcome['state'],'ORIGINAL_MCP_ABILITY_READ_VERIFIED')
        self.assertTrue(outcome['mcp_read_tool_pass'])
        native.assert_not_called()

    def test_mcp_permission_denied_uses_signed_native_read_only(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'inspect',return_value={'state':'MCP_CONNECTED_WITH_TOOLS',
                'read_tool_pass':False,'read_tool_error':'WORDPRESS_PERMISSION_DENIED'}),\
             patch.object(m,'native_site_read',return_value={
                 'state':'EXACT_NATIVE_SITE_READ_VERIFIED'}):
            outcome=m.resilient_inspect(TARGET,'host_operator',None)
        self.assertEqual(outcome['state'],'OUT_OF_BAND_SIGNED_NATIVE_READ_VERIFIED')
        self.assertFalse(outcome['mcp_read_tool_pass'])
        self.assertEqual(outcome['mcp_read_tool_error'],'WORDPRESS_PERMISSION_DENIED')
        self.assertFalse(outcome['mcp_private_ability_unlocked'])
        self.assertFalse(outcome['write_authorized'])

    def test_broken_plugin_uses_skip_plugins_doctor_after_signed_failure(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'inspect',side_effect=RuntimeError('MCP_STDOUT_CLOSED')), \
             patch.object(m,'native_site_read',side_effect=ValueError('PROOF_EXPIRED')), \
             patch.object(m.registry_remote,'doctor',create=True,return_value={
               'state':'CONTROL_PLANE_BOOT_FAILED',
               'identity_verified':True,'wpcli_without_plugins':True}):
            outcome=m.resilient_inspect(TARGET,'host_operator',None)
        self.assertEqual(outcome['state'],'OUT_OF_BAND_PLUGIN_INDEPENDENT_DIAGNOSIS_VERIFIED')
        self.assertTrue(outcome['plugin_independent_diagnosis_verified'])
        self.assertFalse(outcome['native_identity_read_verified'])
        self.assertFalse(outcome['mutation_performed'])

    def test_both_recovery_routes_fail_closed(self):
        with patch.object(m,'original_host_authority',return_value=True),\
             patch.object(m,'inspect',side_effect=TimeoutError()), \
             patch.object(m,'native_site_read',side_effect=ValueError()), \
             patch.object(m.registry_remote,'doctor',create=True,side_effect=ValueError()):
            outcome=m.resilient_inspect(TARGET,'host_operator',None)
        self.assertEqual(outcome['state'],'OUT_OF_BAND_RECOVERY_NOT_VERIFIED')
        self.assertFalse(outcome['write_authorized'])

    def test_wordpress_user_mode_cannot_claim_native_fallback(self):
        with self.assertRaisesRegex(ValueError,'WITHOUT_WORDPRESS_USER_REQUIRED'):
            m.resilient_inspect(TARGET,'wordpress_user','admin')
        with self.assertRaisesRegex(ValueError,'WITHOUT_WORDPRESS_USER_REQUIRED'):
            m.resilient_inspect(TARGET,'auto',None)

    def test_original_host_scope_denial_is_not_fallback(self):
        with patch.object(m,'original_host_authority',side_effect=ValueError('NO_ENROLLED_HOST')),\
             patch.object(m,'inspect') as inspect:
            with self.assertRaisesRegex(ValueError,'NO_ENROLLED_HOST'):
                m.resilient_inspect(TARGET,'host_operator',None)
            inspect.assert_not_called()


if __name__=='__main__':
    unittest.main()
