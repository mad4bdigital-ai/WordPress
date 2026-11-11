"""Regression: child age preview is provider-owned; media composite code not lost."""
import hashlib,json,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
DOC=ROOT/'docs/release-coverage/pricing-and-media-semantic-review-20261111.json'
DATA=json.loads(DOC.read_text())
PRICE=ROOT/'wp-content/plugins/mad4b-pricing-registry/mad4b-pricing-registry.php'
MEDIA=ROOT/'wp-content/mu-plugins/mad4b-unified-media-apply.php'
COMPOSITE=ROOT/'wp-content/mu-plugins/mad4b-media-composite-integrity.php'
class PricingAndMedia(unittest.TestCase):
 def test_original_registry_delegates_child_ages_without_payment(self):
  s=PRICE.read_text()
  self.assertIn("'children','child_ages','room_occupancy'",s)
  self.assertIn("'checkout_ready']??null)!==false",s)
  self.assertIn("'mutation_performed'=>false,'checkout_authorized'=>false",s)
  self.assertIn("call_user_func($handler['callback'],$payload)",s)
  self.assertNotIn('wc_create_order(',s)
 def test_media_composite_rollback_and_signed_backend_not_lost(self):
  s=MEDIA.read_text()
  self.assertIn("'post_registered_composite'=>",s)
  self.assertIn("'term_registered_composite'=>",s)
  self.assertIn("MAD4B_Composite_Media_Adapter_V1::run",s)
  self.assertIn("mad4b_composite_adapter_missing",s)
  self.assertIn("$credits_updated=true",s)
  self.assertIn("signed_write'=>true",s)
 def test_registered_repeater_value_format_retained_for_inspection(self):
  s=COMPOSITE.read_text()
  self.assertIn("'repeater_fields'=>array_map",s)
  self.assertIn("'value_format'=>$r['value_format']??null",s)
  self.assertIn("gallery_stored_foreign_host",s)
 def test_source_fidelity_and_no_claimed_execution(self):
  self.assertFalse(DATA['company_release_authorized'])
  self.assertFalse(DATA['live_runtime_tested'])
  self.assertEqual(len(DATA['files']),6)
  for row in DATA['files']:
   p=ROOT/row['path']
   digest=hashlib.sha256(p.read_bytes()).hexdigest()
   self.assertEqual(digest,row.get('candidate_sha256',row.get('reviewed_live_sha256')),row['path'])
if __name__=='__main__':unittest.main()
