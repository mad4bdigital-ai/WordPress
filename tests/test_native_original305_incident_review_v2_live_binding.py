"""Original305 review V2 must bind to currently enrolled Supervisor V7 and original Unit."""
from __future__ import annotations
from pathlib import Path
import ast
import unittest
ROOT=Path(__file__).resolve().parents[1]
BASE=ROOT/'ops/native-source-publisher-v1'
class Original305LiveReview(unittest.TestCase):
 def test_exact_live_enrolled_service_and_supervisor_pins(self):
  text=(BASE/'original305_incident_signed_review_v2.py').read_text();ast.parse(text)
  for value in ("SUPERVISOR = HOST/'original305_readiness_supervisor_v7.py'",
                "SUPERVISOR_PIN='dd6695c759031b37a7b1d3497c4764ac6bd4f6c1dfc53c6743a544d484f89023'",
                "SERVICE_PIN='8cc78ec5143f0b1aa45e8a7e776138d093d33bce90d74f3cfef734221b45c471'",
                "INDEX_PIN='b3746d97f1440ecf69fdcb5a9b33042a4fcf1349122ea93420bd5002f43d3b12'",
                'INCIDENT_SERVICE_NOT_BOUND_TO_REVIEW_SOURCE',
                "'service_unit_sha256':request['service_unit_sha256']",
                "'original_wordpress_ticket_admission_verified':False"):
   self.assertIn(value,text)
 def test_no_approval_issuance_or_ad_hoc_hold_mutation(self):
  text=(BASE/'original305_incident_signed_review_v2.py').read_text()
  for phrase in ('HOLD.unlink(', 'HOLD.write_bytes(', 'systemctl enable',
                 'subprocess.run(', 'original_signer(', 'update_option('):
   self.assertNotIn(phrase,text)
  self.assertIn("'incident_closed':False",text)
  self.assertIn("'host_release_authorized':False",text)
 def test_old_v1_review_is_not_implicitly_granted_by_v2(self):
  text=(BASE/'original305_incident_signed_review_v2.py').read_text()
  self.assertIn("APPROVAL_CONTRACT = 'mad4b.original305-incident-closure-master-approval.v2'",text)
  self.assertIn('INCIDENT_MASTER_APPROVAL_BINDING_CHANGED',text)
  self.assertIn('INCIDENT_MASTER_SIGNATURE_INVALID',text)
if __name__=='__main__':unittest.main()
