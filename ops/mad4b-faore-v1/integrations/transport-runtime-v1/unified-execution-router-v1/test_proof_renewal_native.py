import importlib.util
import json
import subprocess
import unittest
from pathlib import Path
from unittest.mock import patch

path = Path(__file__).with_name('proof_renewal.py')
spec = importlib.util.spec_from_file_location('proof_renewal_native_v2', path)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)


class NativeProofRenewalTests(unittest.TestCase):
    def execute(self, data, exit_code=0, wp=None):
        if wp is None:
            wp = ['/usr/bin/docker', 'exec', '-u', 'www-data', 'site-php', '/usr/local/bin/wp', '--path=/var/www/html','--skip-themes']
        result = subprocess.CompletedProcess(wp, exit_code, json.dumps(data), '')
        with patch.object(module.os, 'geteuid', return_value=0), \
             patch.object(module, 'central_wp', return_value=wp), \
             patch.object(module.subprocess, 'run', return_value=result) as run:
            status = module.run()
        return status, run.call_args.args[0]

    def test_native_fleet_all_verified_no_wordpress_actor(self):
        status, command = self.execute({
            'contract': 'mad4b.environment.native-v4-refresh.v1',
            'all_verified': True,
            'targets': [
                {'company':'egypt-tour-gates','environment':'staging',
                 'centrally_verified':True,'state':'SIGNED_V4_CENTRAL_PROOF_CURRENT'},
                {'company':'all-royal-egypt','environment':'staging',
                 'centrally_verified':True,'state':'SIGNED_V4_CENTRAL_PROOF_CURRENT'},
            ]
        })
        self.assertEqual(status['state'],'CURRENT')
        self.assertTrue(status['verified'])
        self.assertEqual(status['staging_target_count'],2)
        self.assertNotIn('--user=1',command)
        self.assertEqual(command[:4],['/usr/bin/docker','exec','-u','root'])
        self.assertIn('--allow-root',command)
        self.assertEqual(command[-3:],['mad4b','environment-signed-v4-native','refresh-all-due'])

    def test_unverified_company_fails_closed(self):
        status, _ = self.execute({
            'contract':'mad4b.environment.native-v4-refresh.v1',
            'all_verified':True, 'targets':[
                {'company':'egypt-tour-gates','environment':'staging','centrally_verified':False}]
        })
        self.assertFalse(status['verified'])

    def test_invalid_contract_fails_closed(self):
        status,_=self.execute({'contract':'unknown', 'targets':[],
                               'all_verified':True})
        self.assertEqual(status['state'],'RETRY_SIGNED_IDENTITY')
        self.assertFalse(status['verified'])

    def test_wordpress_user_override_rejected(self):
        with patch.object(module.os,'geteuid',return_value=0), \
             patch.object(module,'central_wp',
                return_value=['wp','--user=1']), \
             patch.object(module.subprocess,'run') as process:
            with self.assertRaisesRegex(RuntimeError,'IMPERSONATION_NOT_ALLOWED'):
                module.run()
            process.assert_not_called()

    def test_split_wordpress_user_override_rejected(self):
        with patch.object(module.os,'geteuid',return_value=0), \
             patch.object(module,'central_wp',
                return_value=['wp','--user','1']), \
             patch.object(module.subprocess,'run') as process:
            with self.assertRaisesRegex(RuntimeError,'IMPERSONATION_NOT_ALLOWED'):
                module.run()
            process.assert_not_called()

    def test_transient_busy_retries_original_enrolled_runtime(self):
        wp=['/usr/bin/docker','exec','-u','www-data','site-php',
            '/usr/local/bin/wp','--path=/var/www/html','--skip-themes']
        payload={'contract':'mad4b.environment.native-v4-refresh.v1',
                 'all_verified':True,'targets':[{'company':'egypt-tour-gates',
                 'environment':'staging','centrally_verified':True}]}
        response=subprocess.CompletedProcess([],0,json.dumps(payload),'')
        with patch.object(module.os,'geteuid',return_value=0), \
             patch.object(module,'central_wp',
               side_effect=[RuntimeError('CENTRAL_WORDPRESS_BUSY'),wp]), \
             patch.object(module.time,'sleep') as pause, \
             patch.object(module.subprocess,'run',return_value=response):
            result=module.run()
        self.assertEqual(result['state'],'CURRENT')
        self.assertTrue(result['verified'])
        pause.assert_called_once_with(2)

    def test_persistent_busy_is_deferred_without_claiming_success(self):
        with patch.object(module.os,'geteuid',return_value=0), \
             patch.object(module,'central_wp',
               side_effect=RuntimeError('CENTRAL_WORDPRESS_BUSY')), \
             patch.object(module.time,'sleep') as pause, \
             patch.object(module.subprocess,'run') as executor:
            result=module.run()
        self.assertEqual(result['state'],'DEFERRED_RETRYABLE_WPCLI_BUSY')
        self.assertFalse(result['verified'])
        self.assertEqual(pause.call_count,3)
        executor.assert_not_called()

    def test_nonroot_rejected(self):
        with patch.object(module.os,'geteuid',return_value=1000), \
             patch.object(module.subprocess,'run') as process:
            with self.assertRaisesRegex(RuntimeError,'NATIVE_ROOT_REQUIRED'):
                module.run()
            process.assert_not_called()

    def test_previous_contract_compatibility(self):
        status,_ = self.execute({'state':'CURRENT','verified':True})
        self.assertTrue(status['verified'])
        self.assertEqual(status['contract'],module.CONTRACT)

if __name__=='__main__':
    unittest.main()
