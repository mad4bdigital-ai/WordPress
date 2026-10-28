<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
use Commerce\Composition\CommercialFinalQuoteLifecycle as F;
use Commerce\Composition\CommercialQuoteEvidenceProbe as P;
if (DB_NAME!=='etg_woocommerce_lab'||wp_get_environment_type()!=='local'
 ||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)exit(43);
global $wpdb;
$counts=static fn()=>[
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'tasks'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_tasks"),
 'holds'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}abk_inventory_holds"),
 'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour_bookings'"),
 'quotes'=>(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
   'commerce_final_quote_qa45_%')),
 'approvals'=>(int)$wpdb->get_var($wpdb->prepare(
   "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
   'commerce_final_quote_approval_qa45_%'))
];
$before=$counts();
$results=[];
$ok=static function(string $k,bool $v,$obs=null)use(&$results){$results[$k]=['pass'=>$v,'observed'=>$obs];};
$config=[
 'schema_version'=>2,'tour_id'=>60629,'source'=>'rate','rate_id'=>60820,
 'travel_date'=>'2026-11-10','audience'=>'general','occupancy'=>'double',
 'quantities'=>['persons'=>2],'booking_intent'=>'quote','extras'=>[],'selections'=>[]
];
wp_set_current_user(1);
$overview=F::evaluate(60820,$config,'independent_rate');
$ok('real_qa45_evaluate_returns_array',is_array($overview),
 is_wp_error($overview)?$overview->get_error_code():null);
if(!is_array($overview)){echo json_encode($results);exit(43);}
$ok('company_policy_currently_draft',$overview['company_policy_active']===false);
$ok('financial_mode_not_approved',$overview['native_financial_mode_approved']===false);
$ok('no_certified_source_receipts',$overview['source_receipts_observed']===0);
$ok('source_missing_exact_four',count(array_filter($overview['blockers'],
 fn($s)=>str_starts_with($s,'source_receipt_missing:')))===4);
$ok('does_not_offer_fake_issuance',!$overview['can_issue']&&$overview['candidate']===null);
$ok('no_execution_authorizations',!$overview['order_authorized']&&!$overview['payment_authorized']&&!$overview['supplier_booking_authorized']);
$denied=F::issue(60820,$config,'independent_rate');
$ok('real_lab_issue_denied',is_wp_error($denied)&&$denied->get_error_code()==='final_quote_issuance_blocked');
$parent=F::evaluate(60629,$config,'parent_service');
$ok('parent_sale_line_consistent',is_array($parent)&&$parent['plan_digest']!==$overview['plan_digest']);
$invalid=F::verifyApproval(str_repeat('b',64),'manager_approval',60820,$config,'independent_rate');
$ok('missing_issued_quote_cannot_approve',is_wp_error($invalid)&&$invalid->get_error_code()==='final_quote_not_found');
$invalid=F::verifyApproval(str_repeat('b',64),'admin_override',60820,$config,'independent_rate');
$ok('unknown_approval_kind_denied',is_wp_error($invalid)&&$invalid->get_error_code()==='final_quote_approval_kind_invalid');
$invalid=F::approvalStatus(str_repeat('b',64));
$ok('unissued_quote_status_denied',is_wp_error($invalid)&&$invalid->get_error_code()==='final_quote_not_found');
$binding=[
 'site_uuid'=>$overview['site_uuid'],'policy_revision'=>$overview['policy_revision'],
 'policy_digest'=>$overview['policy_digest'],'plan_digest'=>$overview['plan_digest'],
 'woo_product_id'=>60820,'service_id'=>60629,'rate_id'=>60820,'sale_role'=>'independent_rate'
];
$fixture=[
 'supplier_cost'=>['provider_id'=>'test.cost','receipt_id'=>'qa45-cost-a','receipt_digest'=>str_repeat('a',64),'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+1800),'currency'=>'USD','amount_minor'=>60000],
 'tax'=>['provider_id'=>'test.tax','receipt_id'=>'qa45-tax-b','receipt_digest'=>str_repeat('b',64),'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+2000),'currency'=>'USD','amount_minor'=>6000],
 'availability'=>['provider_id'=>'test.hold','receipt_id'=>'qa45-hold-c','receipt_digest'=>str_repeat('c',64),'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+1600),'hold_reference'=>'TEST_HOLD_001'],
 'final_quote'=>['provider_id'=>'test.quote','receipt_id'=>'qa45-quote-d','receipt_digest'=>str_repeat('d',64),'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+1200),'currency'=>'USD',
  'amount_minor'=>90000,'supplier_cost_minor'=>60000,'tax_minor'=>6000,'margin_minor'=>24000,'fee_minor'=>2000,'discount_minor'=>2000,
  'reference_digests'=>['supplier_cost'=>str_repeat('a',64),'tax'=>str_repeat('b',64),'availability'=>str_repeat('c',64)]]
];
$rec=F::reconcile($fixture,$binding);
$ok('pure_reconciliation_valid_synthetic',is_array($rec)&&$rec['total_minor']===90000);
$ok('pure_quote_digest_sha',is_array($rec)&&preg_match('/^[a-f0-9]{64}$/D',$rec['quote_digest'])===1);
$ok('quote_expiry_shortest_signed_receipt',is_array($rec)&&$rec['expires_at_utc']===$fixture['final_quote']['expires_at_utc']);
$ok('pure_stable_digest',is_array($rec)&&$rec['quote_digest']===F::reconcile($fixture,$binding)['quote_digest']);
$x=$fixture;$x['tax']['currency']='EGP';
$r=F::reconcile($x,$binding);
$ok('reject_currency_conflict',is_wp_error($r)&&$r->get_error_code()==='final_quote_source_currency_mismatch');
$x=$fixture;$x['final_quote']['tax_minor']=7000;
$r=F::reconcile($x,$binding);
$ok('reject_cost_tax_mismatch',is_wp_error($r)&&$r->get_error_code()==='final_quote_cost_tax_not_reconciled');
$x=$fixture;$x['final_quote']['amount_minor']=100000;
$r=F::reconcile($x,$binding);
$ok('reject_unreconciled_total',is_wp_error($r)&&$r->get_error_code()==='final_quote_total_not_reconciled');
$x=$fixture;$x['final_quote']['reference_digests']['tax']=str_repeat('f',64);
$r=F::reconcile($x,$binding);
$ok('reject_mismatched_receipt_digest',is_wp_error($r)&&$r->get_error_code()==='final_quote_reference_mismatch');
$x=$fixture;unset($x['availability']['hold_reference']);
$r=F::reconcile($x,$binding);
$ok('reject_abstract_inventory_no_hold',is_wp_error($r)&&$r->get_error_code()==='final_quote_confirmed_hold_missing');
$x=$fixture;$x['availability']['expires_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-60);
$r=F::reconcile($x,$binding);
$ok('reject_expired_hold',is_wp_error($r)&&$r->get_error_code()==='final_quote_expiry_not_safe');
$x=$fixture;$x['final_quote']['amount_minor']='90000';
$r=F::reconcile($x,$binding);
$ok('reject_string_as_source_amount',is_wp_error($r)&&$r->get_error_code()==='final_quote_source_amount_unverified');
$x=$fixture;$x['final_quote']['discount_minor']=999999999999;
$r=F::reconcile($x,$binding);
$ok('reject_invalid_discount_and_total',is_wp_error($r));
$ctx=$binding;$ctx['site_uuid']='';
$r=F::reconcile($fixture,$ctx);
$ok('reject_unbound_issuer_context',is_wp_error($r)&&$r->get_error_code()==='final_quote_binding_untrusted');
// Synthetic registered verifier is never authorization while policy stays Draft.
$base=P::inspectForIssuer(60820,$config,'independent_rate');
$ok('issuer_private_claims_present',is_array($base)&&isset($base['source_verified_claims'])&&count($base['source_verified_claims'])===0);
$plain=P::inspect(60820,$config,'independent_rate');
$ok('public_evidence_probe_no_internal_claims',is_array($plain)&&!isset($plain['source_verified_claims']));
$owner=isset($base['site_uuid'])?$base['site_uuid']:'';
$mock=[];
foreach(['supplier_cost','tax','availability','final_quote'] as $kind) {
 $claim=$fixture[$kind];
 $mock[$kind]=[
  'contract'=>'commerce.source-evidence-provider.v1',
  'site_uuid'=>$owner,'provider_id'=>'qa45.test_'.$kind,
  'source_owner'=>'test_qa45_lab',
  'read'=>static function($ctx)use($claim,$kind){
     return array_merge($claim,[
      'contract'=>'commerce.source-evidence-receipt.v1','kind'=>$kind,
      'provider_id'=>'qa45.test_'.$kind,'site_uuid'=>$ctx['site_uuid'],
      'policy_revision'=>$ctx['policy_revision'],'policy_digest'=>$ctx['policy_digest'],
      'preflight_digest'=>$ctx['preflight_digest'],'plan_digest'=>$ctx['plan_digest'],
      'woo_product_id'=>$ctx['woo_product_id'],
      'issued_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-10),
     ]);
  },
  'verify'=>static fn()=>true
 ];
}
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>$mock);
$synthetic=F::evaluate(60820,$config,'independent_rate');
$ok('synthetic_sources_seen_but_policy_blocks',is_array($synthetic)
 &&$synthetic['source_receipts_observed']===4
 &&!$synthetic['can_issue']
 &&in_array('company_policy_not_active',$synthetic['blockers'],true)
 &&in_array('native_source_financial_policy_not_approved',$synthetic['blockers'],true),is_wp_error($synthetic)?$synthetic->get_error_code():null);
$syntheticIssue=F::issue(60820,$config,'independent_rate');
$ok('synthetic_verified_receipts_never_issue_draft',is_wp_error($syntheticIssue)&&$syntheticIssue->get_error_code()==='final_quote_issuance_blocked');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$rest=static function(string $method,string $path,?array $body=null){
 $r=new WP_REST_Request($method,'/commerce-composition/v1'.$path);
 if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}
 return rest_do_request($r);
};
$req=['source_id'=>60820,'configuration'=>$config,'sale_role'=>'independent_rate'];
$resp=$rest('POST','/management/commercial-quote/final/readiness',$req);
$ok('admin_rest_readiness_200',$resp->get_status()===200,$resp->get_status());
$ok('rest_no_store',($resp->get_headers()['Cache-Control']??'')==='private,no-store');
$ok('admin_rest_preview_does_not_expose_supplier_amount',$resp->get_status()===200&&!isset($resp->get_data()['candidate']['supplier_cost_minor']));
$resp=$rest('POST','/management/commercial-quote/final/issue',$req);
$ok('admin_rest_issuance_409',$resp->get_status()===409,$resp->get_status());
$bad=$req;$bad['client_quote']=['signed'=>true];
$resp=$rest('POST','/management/commercial-quote/final/issue',$bad);
$ok('rest_client_signed_quote_rejected',$resp->get_status()===400,$resp->get_status());
$bad=$req;$bad['kind']='manager_approval';$bad['quote_digest']=str_repeat('b',64);
$resp=$rest('POST','/management/commercial-quote/final/approval/verify',$bad);
$ok('admin_approval_for_unissued_quote_404',$resp->get_status()===404,$resp->get_status());
$resp=$rest('GET','/management/commercial-quote/final/'.str_repeat('b',64).'/status');
$ok('admin_quote_status_unissued_404',$resp->get_status()===404,$resp->get_status());
wp_set_current_user(0);
$resp=$rest('POST','/management/commercial-quote/final/readiness',$req);
$ok('guest_readiness_denied',in_array($resp->get_status(),[401,403],true));
$resp=$rest('POST','/management/commercial-quote/final/issue',$req);
$ok('guest_issue_denied',in_array($resp->get_status(),[401,403],true));
$resp=$rest('POST','/management/commercial-quote/final/approval/verify',$bad);
$ok('guest_approval_denied',in_array($resp->get_status(),[401,403],true));
wp_set_current_user(1);
$after=$counts();
$ok('business_and_quote_tables_unchanged',$before===$after,['before'=>$before,'after'=>$after]);
$passed=count(array_filter($results,fn($v)=>$v['pass']));
echo wp_json_encode(['result'=>$passed===count($results)?'PASS':'FAIL',
 'passed'=>$passed,'total'=>count($results),'tests'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if($passed!==count($results))exit(45);
