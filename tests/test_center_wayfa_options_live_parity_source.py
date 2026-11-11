"""Reject accidental scope or authority expansion in the options reconciliation."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_center_wayfa_options_live_parity import verify,Refused
DOC=json.loads((ROOT/'docs/release-coverage/center-wayfa-options-live-parity-20261111.json').read_text())
class OptionsParityTests(unittest.TestCase):
 def test_source_and_unsigned_status(self):
  result=verify(DOC)
  self.assertEqual(result['source_files'],5)
  self.assertFalse(result['original_318_313_certified'])
 def test_sites_cannot_expand(self):
  row=copy.deepcopy(DOC);row['site_scope'].append('allroyalegypt.com')
  with self.assertRaises(Refused):verify(row)
 def test_payment_or_rates_cannot_claim_mutation(self):
  for key in ('original_318_313_certified','stripe_activated','live_booking_enabled',
              'published_tour_rate_records_modified','other_companies_touched'):
   row=copy.deepcopy(DOC);row[key]=True
   with self.assertRaises(Refused):verify(row)
 def test_source_hash_tamper_rejected(self):
  for i in range(5):
   row=copy.deepcopy(DOC);row['files'][i]['sha256']='0'*64
   with self.assertRaises(Refused):verify(row)
 def test_dimension_divergence_not_falsified(self):
  row=copy.deepcopy(DOC);row['dimension_available_wayfa']=19
  with self.assertRaises(Refused):verify(row)
if __name__=='__main__':unittest.main()
