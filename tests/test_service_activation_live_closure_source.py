"""Protect the site-neutral Service Activation live-state closure from drift."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_service_activation_live_closure import verify,Blocked
DOC=json.loads((ROOT/'docs/release-coverage/service-activation-live-closure-20261111.json').read_text())
class ServiceActivationLiveClosureTests(unittest.TestCase):
 def test_exact_source_integration_hold(self):
  result=verify(DOC)
  self.assertEqual(result['verified_source_files'],7)
  self.assertFalse(result['central_release_authorized'])
  self.assertFalse(result['settings_changed'])
 def test_digest_tamper_rejected(self):
  for index in range(7):
   fake=copy.deepcopy(DOC)
   fake['files'][index]['sha256']='0'*64
   with self.assertRaises(Blocked):verify(fake)
 def test_false_release_attestation_rejected(self):
  for key in ('central_composite_release_authorized',
              'company_plan_remote_activation_performed',
              'stripes_or_payments_activated',
              'wayfa_schema_full_parity'):
   fake=copy.deepcopy(DOC)
   fake[key]=True
   with self.assertRaises(Blocked):verify(fake)
 def test_schema_gap_cannot_be_hidden(self):
  fake=copy.deepcopy(DOC)
  fake['wayfa_remaining_schema_gap']=''
  with self.assertRaises(Blocked):verify(fake)
 def test_required_policy_assertion_cannot_be_removed(self):
  for key in ('public_rest_tour_rate_denied','admin_rest_rate_editor_preserved',
              'readback_status_endpoint_admin_only','tour_form_cta_now_explicit_inquiry'):
   fake=copy.deepcopy(DOC)
   fake[key]=False
   with self.assertRaises(Blocked):verify(fake)
if __name__=='__main__':unittest.main()
