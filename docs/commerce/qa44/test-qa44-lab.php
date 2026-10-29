<?php
declare(strict_types=1);
require '/var/www/html/wp-load.php';
if (DB_NAME!=='etg_woocommerce_lab' || wp_get_environment_type()!=='local'
    || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) exit(41);
use Commerce\Composition\CommercialQuoteEvidenceProbe as P;
$checks=[];
$mark=static function(string $name, bool $ok, $details=null)use(&$checks){$checks[$name]=['pass'=>$ok,'observed'=>$details];};
global $wpdb;
$counts=static fn()=>[
    'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
    'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
    'tasks'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_tasks"),
    'holds'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}abk_inventory_holds"),
    'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour_bookings'")
];
$before=$counts();
wp_set_current_user(1);
$config=[
 'schema_version'=>2,'tour_id'=>60629,'source'=>'rate','rate_id'=>60820,
 'travel_date'=>'2026-11-10','audience'=>'general','occupancy'=>'double',
 'quantities'=>['persons'=>2],'booking_intent'=>'quote','extras'=>[],'selections'=>[]
];
$report=P::inspect(60820,$config,'independent_rate');
$mark('rate_inspection_valid',is_array($report),is_wp_error($report)?$report->get_error_code():null);
if(!is_array($report)){echo json_encode($checks);exit(42);}
$mark('source_providers_six',$report['source_receipts_required']===6 && count($report['provider_probes'])===6);
$mark('no_providers_currently',$report['source_receipts_observed']===0 && count(array_filter($report['provider_probes'],fn($v)=>$v['state']==='missing_provider'))===6);
$mark('quote_not_certified',$report['final_quote_certified']===false && $report['commercial_quote_issued']===false);
$mark('write_authority_locked',!$report['order_authorized']&&!$report['payment_authorized']&&!$report['checkout_authorized']&&!$report['supplier_booking_authorized']);
$mark('policy_draft_held',$report['company_policy_status']==='draft');
$mark('chosen_native_rate',$report['selected_sale_line']['woo_product_id']===60820);
$parent=P::inspect(60629,$config,'parent_service');
$mark('parent_native_tour',is_array($parent) && $parent['selected_sale_line']['woo_product_id']===60629);
$mark('parent_rate_distinct_plan',is_array($parent) && $parent['plan_digest']!==$report['plan_digest']);
$adapter=static function($reader,$verify,$site=null)use($report){
    return ['contract'=>'commerce.source-evidence-provider.v1',
       'site_uuid'=>$site??$report['site_uuid'],
       'provider_id'=>'qa44.test-provider',
       'source_owner'=>'qa44_native_test',
       'read'=>$reader,'verify'=>$verify];
};
$neverCalled=false;
$foreign=$adapter(function()use(&$neverCalled){$neverCalled=true;return [];},static fn()=>true,'foreign-site-uuid');
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['tax'=>$foreign]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('foreign_tenant_denied',is_array($x)&&$x['provider_probes']['tax']['state']==='invalid_provider_binding'&&!$neverCalled);
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$noVerifier=$adapter(static fn()=>[],null);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['tax'=>$noVerifier]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('missing_verifier_denied',is_array($x)&&$x['provider_probes']['tax']['state']==='invalid_provider_binding');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$reader=static fn(array $ctx)=>[
   'contract'=>'commerce.source-evidence-receipt.v1',
   'kind'=>'supplier_cost','provider_id'=>'qa44.test-provider',
   'site_uuid'=>$ctx['site_uuid'],'policy_revision'=>$ctx['policy_revision'],
   'policy_digest'=>$ctx['policy_digest'],'preflight_digest'=>$ctx['preflight_digest'],
   'plan_digest'=>$ctx['plan_digest'],'woo_product_id'=>$ctx['woo_product_id'],
   'receipt_id'=>'qa44-synthetic-only','receipt_digest'=>str_repeat('a',64),
   'issued_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()-10),
   'expires_at_utc'=>gmdate('Y-m-d\TH:i:s\Z',time()+3600)
];
$verifyCalls=0;
$wrongSite=$adapter(static function($ctx)use($reader){$x=$reader($ctx);$x['site_uuid']='foreign';return $x;},function()use(&$verifyCalls){$verifyCalls++;return true;});
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$wrongSite]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('receipt_foreign_site_denied_before_verifier',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='receipt_rejected'&&$verifyCalls===0);
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$expired=$adapter(static function($ctx)use($reader){$x=$reader($ctx);$x['expires_at_utc']=gmdate('Y-m-d\TH:i:s\Z',time()-5);return $x;},static fn()=>true);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$expired]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('expired_receipt_denied',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='receipt_rejected');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$wrongRate=$adapter(static function($ctx)use($reader){$x=$reader($ctx);$x['woo_product_id']=60629;return $x;},static fn()=>true);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$wrongRate]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('wrong_sale_line_denied',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='receipt_rejected');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$notVerified=$adapter($reader,static fn()=>false);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$notVerified]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('unverified_provenance_denied',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='receipt_rejected');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$throws=$adapter(static function(){throw new RuntimeException('read failed');},static fn()=>true);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$throws]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('provider_read_exception_denied',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='source_unavailable');
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$verified=$adapter($reader,static fn()=>true);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$verified]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('verifier_result_evidence_only',is_array($x)&&$x['provider_probes']['supplier_cost']['state']==='source_verified_evidence_only'&&$x['source_receipts_observed']===1);
$mark('synthetic_verification_never_grants_sale',is_array($x)&&!$x['final_quote_certified']&&!$x['order_authorized']&&!$x['payment_authorized']&&!$x['supplier_booking_authorized']);
$mark('synthetic_secret_payload_not_echoed',is_array($x)&&!str_contains(json_encode($x),'qa44-synthetic-only')&&!str_contains(json_encode($x),str_repeat('a',64)));
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$allKinds=['supplier_cost','tax','availability','final_quote','manager_approval','traveler_acceptance'];
$all=[];
foreach($allKinds as $kind){
    $all[$kind]=$adapter(static function($ctx)use($reader,$kind){$r=$reader($ctx);$r['kind']=$kind;return $r;},static fn()=>true);
}
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=> $all);
$allVerified=P::inspect(60820,$config,'independent_rate');
$mark('all_synthetic_receipts_still_not_quote',is_array($allVerified)
 && $allVerified['source_receipts_observed']===6 && $allVerified['commercial_quote_issued']===false
 && $allVerified['final_quote_certified']===false && $allVerified['payment_authorized']===false
 && $allVerified['supplier_booking_authorized']===false);
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$privacyCaptured=[];
$privacy=$adapter(static function($ctx)use(&$privacyCaptured){$privacyCaptured=$ctx;return null;},static fn()=>false);
add_filter('commerce_composition_final_quote_evidence_providers',static fn()=>['supplier_cost'=>$privacy]);
$x=P::inspect(60820,$config,'independent_rate');
$mark('only_whitelisted_context_sent',is_array($x) && $privacyCaptured!==[]
 && !array_key_exists('audience',$privacyCaptured) && !array_key_exists('quantities',$privacyCaptured)
 && array_key_exists('plan_digest',$privacyCaptured) && array_key_exists('travel_date',$privacyCaptured));
$personal=$config;$personal['traveler_email']='secret@test.invalid';
$untrusted=P::inspect(60820,$personal,'independent_rate');
$mark('unknown_personal_config_not_forwarded',is_array($untrusted) && !array_key_exists('traveler_email',$privacyCaptured));
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$rest=static function($method,$body) {
 $r=new WP_REST_Request($method,'/commerce-composition/v1/management/commercial-quote/evidence-probe');
 $r->set_header('Content-Type','application/json');
 $r->set_body(wp_json_encode($body));
 return rest_do_request($r);
};
$req=['source_id'=>60820,'configuration'=>$config,'sale_role'=>'independent_rate'];
$ok=$rest('POST',$req);
$mark('rest_admin_ok',$ok->get_status()===200,$ok->get_status());
$mark('rest_no_store',($ok->get_headers()['Cache-Control']??'')==='private,no-store');
$bad=$req;$bad['evidence_receipt']=['fake'=>true];
$reject=$rest('POST',$bad);
$mark('rest_reject_client_receipt',$reject->get_status()===400,$reject->get_status());
wp_set_current_user(0);
$anon=$rest('POST',$req);
$mark('rest_anonymous_denied',in_array($anon->get_status(),[401,403],true),$anon->get_status());
wp_set_current_user(1);
$after=$counts();
$mark('orders_tasks_inventory_unchanged',$before===$after,['before'=>$before,'after'=>$after]);
$passed=count(array_filter($checks,fn($v)=>$v['pass']));
echo wp_json_encode(['result'=>$passed===count($checks)?'PASS':'FAIL','passed'=>$passed,
 'total'=>count($checks),'tests'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if($passed!==count($checks)) exit(42);
