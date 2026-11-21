"""Current original worker: only exact revision of verified route is retired."""
import importlib.util
import pathlib
import unittest
from unittest.mock import patch

ROOT=pathlib.Path(__file__).resolve().parents[1]
RUNNER=ROOT/'ops/dynamic-critical-css-20261116/run-public-route.py'
spec=importlib.util.spec_from_file_location('css_original_worker_bridge',RUNNER)
mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
TOKEN='b'*32
ROUTE='/tours-and-activities/authorized/'

class ManagementTokenBridge(unittest.TestCase):
    def test_snapshot_existing_token_and_successful_exact_ack(self):
        captured=[]
        def evaluate(php,code,timeout=0):
            captured.append(code)
            if 'worker_source_for_path' in code:
                return {'ok':True,'path':ROUTE,'source_token':TOKEN,'updated':1760000000}
            self.assertIn('"certified"',code)
            self.assertIn(TOKEN,code)
            return {'ok':True,'matched':1,'status':'certified'}
        with patch.object(mod,'wp_eval',side_effect=evaluate):
            ticket=mod.management_revision_snapshot('fake-php',ROUTE)
            self.assertEqual(ticket['source_token'],TOKEN)
            self.assertTrue(mod.acknowledge_management_certified('fake-php',ROUTE,ticket))
        self.assertEqual(len(captured),2)

    def test_wrong_revision_or_route_rejected_no_write(self):
        cases=[None,{'source_token':'broken','path':ROUTE},
               {'source_token':TOKEN,'path':'/different/'}]
        with patch.object(mod,'wp_eval',side_effect=AssertionError('no WP writes')) as call:
            for ticket in cases:
                self.assertFalse(mod.acknowledge_management_certified('fake',ROUTE,ticket))
            self.assertIsNone(mod.management_revision_snapshot('fake','/x/../wp-admin'))
            self.assertIsNone(mod.management_revision_snapshot('fake','//some-url'))
            call.assert_not_called()

    def test_new_content_revision_survives_old_proof(self):
        def evaluate(php,code,timeout=0):
            if 'worker_source_for_path' in code:
                return {'ok':True,'path':ROUTE,'source_token':TOKEN,'updated':1760000000}
            return {'ok':False,'matched':0,'error':'source_revision_changed'}
        with patch.object(mod,'wp_eval',side_effect=evaluate):
            ticket=mod.management_revision_snapshot('fake',ROUTE)
            self.assertFalse(mod.acknowledge_management_certified('fake',ROUTE,ticket))

    def test_missing_ticket_or_broken_cli_defers_original_job(self):
        with patch.object(mod,'wp_eval',return_value={'ok':False,'error':'no_matching_edit'}):
            self.assertIsNone(mod.management_revision_snapshot('fake',ROUTE))
        with patch.object(mod,'wp_eval',side_effect=ValueError('fake PHP unavailable')):
            self.assertIsNone(mod.management_revision_snapshot('fake',ROUTE))
            self.assertFalse(mod.acknowledge_management_certified('fake',ROUTE,
                  {'path':ROUTE,'source_token':TOKEN}))
if __name__=='__main__':unittest.main()
