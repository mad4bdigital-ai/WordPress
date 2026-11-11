"""Source attestation tests for the central + Wayfa-only live service observation pilot."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_center_wayfa_service_pilot import verify,Blocked
DOC=json.loads((ROOT/'docs/release-coverage/center-wayfa-service-pilot-20261111.json').read_text())
class CenterWayfaPilotTests(unittest.TestCase):
 def test_local_source_contract(self):
  result=verify(DOC)
  self.assertEqual(result['source_files'],7)
  self.assertFalse(result['central_composite_release_authorized'])
 def test_widened_company_scope_rejected(self):
  doc=copy.deepcopy(DOC)
  doc['observation_pilot_codes'].append('all-royal-egypt')
  with self.assertRaises(Blocked):verify(doc)
 def test_false_release_or_booking_claim_rejected(self):
  for field in ('central_composite_release_authorized','company_plan_applied',
                'booking_payment_activated','stored_service_activation_modified'):
   doc=copy.deepcopy(DOC);doc[field]=True
   with self.assertRaises(Blocked):verify(doc)
 def test_source_tamper_rejected(self):
  for i in range(7):
   doc=copy.deepcopy(DOC);doc['files'][i]['sha256']='0'*64
   with self.assertRaises(Blocked):verify(doc)
 def test_wayfa_schema_gap_cannot_be_hidden(self):
  doc=copy.deepcopy(DOC);doc['wayfa_schema_gap_count']=1
  with self.assertRaises(Blocked):verify(doc)
if __name__=='__main__':unittest.main()
