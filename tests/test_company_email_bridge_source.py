"""Tamper proofing for source-owned WooCommerce + Fluent Forms visual integration."""
import copy,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_company_email_bridge import verify,Blocked
SOURCE=ROOT/'docs/release-coverage/company-email-experience-bridge-20261111.json'
MANIFEST=json.loads(SOURCE.read_text())
class EmailBridgeSource(unittest.TestCase):
 def test_source_signed_by_hash_and_release_hold(self):
  data=verify(MANIFEST)
  self.assertEqual(data['verified_files'],1)
  self.assertFalse(data['release_authorized'])
  self.assertFalse(data['sender_changed'])
 def test_source_digest_tamper_rejected(self):
  v=copy.deepcopy(MANIFEST)
  v['source_sha256']='0'*64
  with self.assertRaises(Blocked):verify(v)
 def test_forged_release_authorization_rejected(self):
  v=copy.deepcopy(MANIFEST)
  v['central_composite_release_authorized']=True
  with self.assertRaises(Blocked):verify(v)
 def test_notification_toggle_or_additional_sender_rejected(self):
  for field,value in [('Fluent_Forms_notification_enablement_unmodified',False),
                      ('one_sender_no_new_wp_mail_dispatch',False),
                      ('gmail_inbox_visual_verified',True)]:
   v=copy.deepcopy(MANIFEST);v[field]=value
   with self.assertRaises(Blocked):verify(v)
if __name__=='__main__':unittest.main()
