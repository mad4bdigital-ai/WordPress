"""Bounded opt-in loyalty policy and Woo-native email without another sender."""
import copy,hashlib,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_early_payment_woocommerce_source import verify,Blocked
M=json.loads((ROOT/'docs/release-coverage/early-payment-woocommerce-email-source-v1-20261111.json').read_text())
class EarlyPaymentSource(unittest.TestCase):
 def test_five_exact_sources_and_disabled_defaults(self):
  self.assertEqual(verify(M)['exact_source_files'],4)
  self.assertEqual(verify(M)['reviewed_semantic_source_patches'],1)
  self.assertEqual(verify(M)['candidate_only_plain_templates'],1)
  self.assertFalse(verify(M)['benefit_default_enabled'])
  source=(ROOT/'wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php').read_text()
  for marker in ("'enabled'=>'no'","'type'=>'service'",
                 "'monetary_discount'=>false","'booking_confirmed'=>false",
                 'companyEnabled', 'claimState($order)',
                 'function boardLinked(', 'function fulfill(', 'refundState(',
                 "self::ORDER_META","self::STATE_META"):
   self.assertIn(marker,source)
 def test_thank_you_and_checkout_dont_grant_payment_authority(self):
  s=(ROOT/'wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php').read_text()
  self.assertIn('fluentform/submission_confirmation',s)
  self.assertIn('woocommerce_pay_order_before_payment',s)
  self.assertIn('MAD4B_Tour_Form6_Submission_Mode_V1::inspect',s)
  self.assertIn("get_order_key()",s)
  self.assertIn("get_date_paid('edit')",s)
  self.assertIn('commerce_manage_request_tasks',s)
  self.assertNotIn('wc_create_order(',s)
  self.assertNotIn('woocommerce_payment_complete',s)
 def test_native_email_renderer_and_one_idempotent_sender(self):
  source=(ROOT/'wp-content/mu-plugins/mad4b-request-woocommerce-email-v1.php').read_text()
  email=(ROOT/'wp-content/mu-plugins/templates/emails/mad4b-request-lifecycle.php').read_text()
  lifecycle=(ROOT/'wp-content/plugins/commerce-composition/includes/RequestLifecycle.php').read_text()
  self.assertIn("woocommerce_email_classes",source)
  self.assertIn("$this->manual = true",source)
  self.assertIn('render_lifecycle(',source)
  self.assertNotIn('wp_mail(',source)
  self.assertIn("woocommerce_email_header",email)
  self.assertIn("woocommerce_email_footer",email)
  self.assertIn("RequestAdvancePayments::gateways()",email)
  self.assertIn("RequestAdvancePayments::isAdvance",email)
  self.assertIn("render_lifecycle($msg,$p,$o)",lifecycle)
  self.assertIn("wp_mail($msg['to']",lifecycle)
  self.assertIn("'accepted_by_transport'",lifecycle)
  self.assertTrue(M['plain_text_template_missing_on_live_source'])
  plain=(ROOT/M['candidate_plain_fallback']['path']).read_text()
  self.assertIn('get_content_plain()',source)
  self.assertIn('RequestAdvancePayments::gateways()',plain)
  self.assertIn("if ($advance_order)",plain)
  self.assertNotIn('wp_mail(',plain)
  self.assertNotIn('wc_create_order(',plain)
  self.assertTrue(M['candidate_plain_fallback']['gateway_eligibility_checked_before_link'])
 def test_exact_fingerprint_tamper_blocked(self):
  for index in range(5):
   data=copy.deepcopy(M)
   data['source_files'][index]['live_sha256']='0'*64
   with self.assertRaises(Blocked):verify(data)
  forged=copy.deepcopy(M)
  forged['source_files'][0]['path']='wp-content/mu-plugins/foreign.php'
  with self.assertRaises(Blocked):verify(forged)
  forged=copy.deepcopy(M)
  forged['release_authorized']=True
  with self.assertRaises(Blocked):verify(forged)
  forged=copy.deepcopy(M)
  forged['candidate_plain_fallback']['candidate_sha256']='0'*64
  with self.assertRaises(Blocked):verify(forged)
  forged=copy.deepcopy(M)
  forged['reviewed_candidate_overlays'][0]['payment_authority_granted']=True
  with self.assertRaises(Blocked):verify(forged)
if __name__=='__main__':unittest.main()
