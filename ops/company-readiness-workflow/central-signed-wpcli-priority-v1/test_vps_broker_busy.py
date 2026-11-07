#!/usr/bin/env python3
"""Original Broker WP-CLI congestion classification; no host execution or WordPress writes."""
import importlib.util,json,types,unittest
from pathlib import Path
from unittest.mock import patch
P=Path(__file__).resolve().parent/'vps-broker-poller.py'
S=importlib.util.spec_from_file_location('original_vps_broker_capacity_test',str(P))
B=importlib.util.module_from_spec(S);S.loader.exec_module(B)

class BrokerCongestionRegression(unittest.TestCase):
 @staticmethod
 def run_result(rc,stderr='',stdout=''):
  return types.SimpleNamespace(returncode=rc,stdout=stdout,stderr=stderr)
 def call(self,result):
  with patch.object(B,'with_actor',side_effect=lambda a:a),patch.object(B.subprocess,'run',return_value=result):
   return B.wp_call(['status'])
 def test_busy_code_1_is_deferred(self):
  with self.assertRaises(B.RetryableWPCLIBusy):
   self.call(self.run_result(1,stderr='STAGING_WPCLI_BUSY: two active WordPress CLI sessions; retry later.\n'))
 def test_busy_code_75_is_deferred(self):
  with self.assertRaises(B.RetryableWPCLIBusy):
   self.call(self.run_result(75,stderr='STAGING_WPCLI_BUSY: two active WordPress CLI sessions; retry later.\n'))
 def test_authority_denied_not_reclassified_busy(self):
  with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_1'):
   self.call(self.run_result(1,stderr='CENTRAL_HOST_REQUIRED'))
 def test_fake_stdout_busy_does_not_override_real_denial(self):
  with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_1'):
   self.call(self.run_result(1,stderr='OWNER_PERMISSION_DENIED',stdout='STAGING_WPCLI_BUSY: fake'))
 def test_wp_cli_error_prefix_busy_is_deferred(self):
  with self.assertRaises(B.RetryableWPCLIBusy):
   self.call(self.run_result(1,stderr='Error: STAGING_WPCLI_BUSY: two active WordPress CLI sessions; retry later.'))
 def test_success_requires_broker_json_receipt(self):
  o=self.call(self.run_result(0,stdout='{"contract":"mad4b.central-vps-broker.v1","verified":true}\n'))
  self.assertEqual(o['verified'],True)
 def test_no_json_receipt_denied(self):
  with self.assertRaisesRegex(RuntimeError,'NO_JSON_RECEIPT'):
   self.call(self.run_result(0,stdout='Success without typed receipt'))

if __name__=='__main__':unittest.main()
