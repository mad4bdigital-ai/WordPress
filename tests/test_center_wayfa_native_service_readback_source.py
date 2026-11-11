"""Unsigned native readback manifest must not claim certified original authority."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_center_wayfa_native_service_readback import verify,Invalid
DOC=json.loads((ROOT/'docs/release-coverage/center-wayfa-native-service-readback-20261111.json').read_text())
class NativeManifestTests(unittest.TestCase):
 def test_exact_local_deployment_files_and_hold(self):
  self.assertFalse(verify(DOC)['signed_313'])
 def test_no_other_company_scope(self):
  row=copy.deepcopy(DOC);row['pilots'].append('all-royal-egypt')
  with self.assertRaises(Invalid):verify(row,False)
 def test_no_unauthorized_original_completion(self):
  for field in ['original_318_signed_release_completed','original_313_signed_receipt_verified',
                'original_313_operation_registered_for_company_code_release',
                'booking_or_payment_authority_changed','catalog_plan_applied']:
   row=copy.deepcopy(DOC);row[field]=True
   with self.assertRaises(Invalid):verify(row,False)
 def test_file_drift_denied(self):
  for index in range(5):
   row=copy.deepcopy(DOC);row['files'][index]['sha256']='0'*64
   with self.assertRaises(Invalid):verify(row,False)
 def test_readback_cadence_contract(self):
  for field in ['pilot_timer_cadence_seconds','native_receipt_max_age_seconds']:
   row=copy.deepcopy(DOC);row[field]=100
   with self.assertRaises(Invalid):verify(row,False)
if __name__=='__main__':unittest.main()
