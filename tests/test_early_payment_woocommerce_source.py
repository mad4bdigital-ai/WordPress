"""Bounded opt-in loyalty policy and Woo-native email without another sender."""
import copy,hashlib,json,sys,unittest
from pathlib import Path
ROOT=Path(__file__).resolve().parents[1]
sys.path.insert(0,str(ROOT/'tools'))
from verify_early_payment_woocommerce_source import verify,Blocked
from verify_company_branded_email_v2 import verify as verify_v2,Blocked as V2Blocked
M=json.loads((ROOT/'docs/release-coverage/early-payment-woocommerce-email-source-v1-20261111.json').read_text())
V2=json.loads((ROOT/'docs/release-coverage/company-branded-woocommerce-email-v2-20261111.json').read_text())
class EarlyPaymentSource(unittest.TestCase):
 def test_v1_baseline_detects_superseding_v2_and_disabled_defaults(self):
  # The immutable v1 snapshot must reject the new company-branded files,
  # while the reviewed v2 manifest must account for every changed source.
  with self.assertRaises(Blocked) as old:
   verify(M)
  self.assertEqual(str(old.exception),'PLAIN_FALLBACK_CONTENT_CHANGED')
  current=verify_v2(V2)
  self.assertEqual(current['verified_files'],5)
  self.assertFalse(current['release_authorized'])
  self.assertFalse(V2['stripe_activation_or_payment_capture'])
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
   data=copy.deepcopy(V2)
   data['files'][index]['sha256']='0'*64
   with self.assertRaises(V2Blocked):verify_v2(data)
  forged=copy.deepcopy(V2)
  forged['files'][0]['path']='wp-content/mu-plugins/foreign.php'
  with self.assertRaises(V2Blocked):verify_v2(forged)
  forged=copy.deepcopy(V2)
  forged['release_authorized']=True
  with self.assertRaises(V2Blocked):verify_v2(forged)
  forged=copy.deepcopy(V2)
  forged['new_mail_queue_or_dispatcher']=True
  with self.assertRaises(V2Blocked):verify_v2(forged)
if __name__=='__main__':unittest.main()
