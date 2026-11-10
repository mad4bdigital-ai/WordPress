import importlib.util
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

source=Path(__file__).with_name('mcp_stdio_client.py')
spec=importlib.util.spec_from_file_location('native_client_local_test',source)
module=importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)

BASE=['/usr/bin/docker','exec','-u','www-data',
      'php-wkgtkzta35wkkgouwt6ruljc','/usr/local/bin/wp',
      '--path=/var/www/html','--skip-themes']
FACTS={'container':'php-wkgtkzta35wkkgouwt6ruljc',
       'site_uuid':'e5e64bca-ed62-48d2-b2b3-dff693cd702a',
       'canonical_origin':'https://tours.dev.mad4b.com',
       'environment':'staging'}

class ExactNativeClientTests(unittest.TestCase):
    def setUp(self):
        module.exact_enrolled_central_target.cache_clear()

    def tearDown(self):
        module.exact_enrolled_central_target.cache_clear()

    def make(self,db='etg_commerce_staging',facts=FACTS):
        with patch('wpcli_dynamic_actor.enrolled_target',return_value=facts),\
             patch.object(module.subprocess,'run',return_value=subprocess.CompletedProcess([],0,db,'')):
            return module.exact_central_native_read_command(BASE)

    def test_exact_readonly_host_runtime(self):
        command=self.make()
        self.assertEqual(command[:4],['/usr/bin/docker','exec','-i','-u'])
        self.assertEqual(command[4],'root')
        self.assertIn('--allow-root',command)
        self.assertIn('MAD4B_CLI_ALLOWED_WRITE_ENVIRONMENTS=none',command)
        self.assertIn('MAD4B_CLI_TARGET_SITE_UUID='+FACTS['site_uuid'],command)
        self.assertNotIn('--user=1',command)

    def test_wrong_database_refused(self):
        with self.assertRaisesRegex(ValueError,'DATABASE_READBACK_INVALID'):
            self.make(db='invalid db')

    def test_wrong_scope_refused(self):
        with self.assertRaisesRegex(ValueError,'EXACT_CENTRAL_NATIVE_RUNTIME_REQUIRED'):
            self.make(facts={**FACTS,'environment':'production'})

    def test_server_requires_host_operator_mode(self):
        with patch.object(module,'original_host_authority',return_value=True),\
             patch.object(module,'bound_route',return_value=(BASE,None,None)),\
             patch.object(module,'exact_central_native_read_command',return_value=['wp']) as build:
            with self.assertRaisesRegex(ValueError,'EXACT_NATIVE_OPERATOR'):
                module.command_for(module.CENTRAL,'auto',None,'mad4b-vps-local')
            build.assert_not_called()

    def test_server_rejects_user_impersonation(self):
        with patch.object(module,'original_host_authority',return_value=True),\
             patch.object(module,'bound_route',return_value=(BASE,None,None)),\
             patch.object(module,'exact_central_native_read_command',return_value=['wp']) as build:
            with self.assertRaisesRegex(ValueError,'EXACT_NATIVE_OPERATOR'):
                module.command_for(module.CENTRAL,'host_operator','1','mad4b-vps-local')
            build.assert_not_called()

    def test_busy_retries_only_exact_read_preflight(self):
        with patch.object(module,'_command_for_once',
              side_effect=[RuntimeError('CENTRAL_WORDPRESS_BUSY'),['wp']]) as route, \
             patch.object(module.time,'sleep') as sleep:
            result=module.command_for(module.CENTRAL,'host_operator',None,'mad4b-vps-local')
        self.assertEqual(result,['wp'])
        self.assertEqual(route.call_count,2)
        sleep.assert_called_once_with(2)

    def test_other_runtime_errors_not_retried(self):
        with patch.object(module,'_command_for_once',
              side_effect=RuntimeError('ORIGINAL_SITE_IDENTITY_MISMATCH')) as route, \
             patch.object(module.time,'sleep') as sleep:
            with self.assertRaisesRegex(RuntimeError,'ORIGINAL_SITE_IDENTITY_MISMATCH'):
                module.command_for(module.CENTRAL,'host_operator',None,'mad4b-vps-local')
        route.assert_called_once()
        sleep.assert_not_called()

    def test_missing_original_authority_denies_route(self):
        with patch.object(module,'original_host_authority',
             side_effect=ValueError('ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED')):
            with self.assertRaisesRegex(ValueError,'ORIGINAL_HOST_OPERATOR_AUTHORITY_REQUIRED'):
                module.command_for(module.CENTRAL,'host_operator',None,'mad4b-vps-local')

if __name__=='__main__':
    unittest.main()
