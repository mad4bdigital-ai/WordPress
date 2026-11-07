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
 def test_heartbeat_signature_reject_has_only_bounded_machine_code(self):
  with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_1:HEARTBEAT_SIGNATURE_REJECTED'):
   self.call(self.run_result(1,stderr='Error: signed_host_heartbeat_rejected'))
 def test_principal_denial_has_only_bounded_machine_code(self):
  with self.assertRaisesRegex(RuntimeError,'WPCLI_FAILURE_1:CENTRAL_PRINCIPAL_REQUIRED'):
   self.call(self.run_result(1,stderr='Error: Central WP-CLI host principal required'))
 def test_signed_payload_generated_after_wordpress_identity_preflight(self):
  stages=[]
  def bind(x):
   stages.append('identity_verified')
   return ['original-broker-cli']
  def signed():
   stages.append('signed_after_identity')
   return ['heartbeat','--host=srv2030908']
  def run(argv,**kwargs):
   stages.append('signed_request_sent')
   self.assertEqual(argv,['original-broker-cli','heartbeat','--host=srv2030908'])
   return self.run_result(0,stdout='{"verified":true}\n')
  with patch.object(B,'with_actor',side_effect=bind),patch.object(B.subprocess,'run',side_effect=run):
   self.assertTrue(B.wp_call([],signed_builder=signed)['verified'])
  self.assertEqual(stages,['identity_verified','signed_after_identity','signed_request_sent'])
 def test_signed_builder_cannot_send_unapproved_or_mixed_action(self):
  with patch.object(B,'with_actor',return_value=['original-broker-cli']),patch.object(B.subprocess,'run') as run:
   with self.assertRaisesRegex(RuntimeError,'SIGNED_BROKER_PAYLOAD_SHAPE_INVALID'):
    B.wp_call(['pending'],signed_builder=lambda:['heartbeat'])
   with self.assertRaisesRegex(RuntimeError,'SIGNED_BROKER_ACTION_INVALID'):
    B.wp_call([],signed_builder=lambda:['install'])
   run.assert_not_called()
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
