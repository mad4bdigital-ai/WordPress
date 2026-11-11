"""Source-only Form #6 successful request with conditional Woo advance CTA."""
from pathlib import Path
import hashlib,json,subprocess,unittest
ROOT=Path(__file__).resolve().parents[1]
PROOF=json.loads((ROOT/'docs/release-coverage/form6-request-thankyou-source-v1-20261111.json').read_text())
MU=json.loads((ROOT/'docs/release-coverage/direct-mu-additive-source-20261111.json').read_text())
P='wp-content/mu-plugins/mad4b-tour-form6-submission-mode-v1.php'
class Form6ThankYou(unittest.TestCase):
 def test_pinned_exact_source_and_tiny_reviewed_candidate(self):
  self.assertEqual(PROOF['path'],P)
  self.assertEqual(PROOF['php_source_version'],'1.1.0')
  s=(ROOT/P).read_bytes()
  self.assertEqual(hashlib.sha256(s).hexdigest(),PROOF['candidate_sha256'])
  prior=subprocess.check_output(['git','-C',str(ROOT),'show',
    PROOF['prior_candidate_head']+':'+P],timeout=20)
  self.assertEqual(hashlib.sha256(prior).hexdigest(),PROOF['source_old_sha256'])
  row=next(i for i in MU['files'] if i['path']==P)
  self.assertEqual(row['sha256'],PROOF['source_live_sha256'])
  o=next(i for i in MU['approved_candidate_overlays'] if i['path']==P)
  self.assertEqual(o['live_sha256'],PROOF['source_live_sha256'])
  self.assertEqual(o['candidate_sha256'],PROOF['candidate_sha256'])
  self.assertEqual(o['offline_php_cases_passed'],22)
 def test_payment_cta_is_post_submission_and_original_woo_only(self):
  s=(ROOT/P).read_text()
  for marker in (
      "unset($response['redirectUrl']);",
      "RequestAdvancePayments::findBySource(6,(int)$sid,3)",
      "RequestAdvancePayments::isAdvance($orders[0])",
      "get_meta('_request_source_id')",
      "get_meta('_request_collection_state')==='awaiting_payment'",
      "get_checkout_payment_url()",
      "wp_parse_url(home_url(),PHP_URL_HOST)",
      "get_post_status($tour)==='publish'"):
   self.assertIn(marker,s)
  for forbidden in ("wc_create_order(", "wp_redirect(", "wp_mail(", "Stripe\\"):
   self.assertNotIn(forbidden,s)
 def test_confirmation_lang_follows_original_source(self):
  s=(ROOT/P).read_text()
  self.assertIn("wpml_element_language_code",s)
  self.assertIn("$copy=self::wording($lang);",s)
  self.assertIn("mad4b-request-confirmation",s)
  self.assertIn("aria-live",s)
  self.assertIn("Pay later",s)
 def test_order_benefit_appends_after_form6_and_does_not_replace_context(self):
  s=(ROOT/P).read_text()
  loyalty=(ROOT/'wp-content/mu-plugins/mad4b-early-payment-benefits-v1.php').read_text()
  self.assertIn("[self::class,'confirmation'],10040,5",s)
  self.assertIn("[self::class,'confirmation'],10070,5",loyalty)
  self.assertIn("'mad4b-request-confirmation'",loyalty)
  self.assertIn("_request_source_id",loyalty)
  self.assertIn("self::offer($o)",loyalty)
 def test_no_deployment_authority_or_book_confirmation(self):
  for key in ('release_authorized','company_deployment','stripe_capture','live_browser_verified'):
   self.assertFalse(PROOF[key])
  for key in ('no_automatic_redirect','signed_advance_order_source_required',
              'no_new_order_created','only_existing_woocommerce_pay_url',
              'current_gateway_and_rate_readiness_required',
              'source_post_wpml_locale_preferred','no_booking_confirmation'):
   self.assertTrue(PROOF[key])
if __name__=='__main__':unittest.main()
