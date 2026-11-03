<?php
if(DB_NAME!=='etg_woocommerce_lab'||wp_get_environment_type()!=='local'
 ||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)
 WP_CLI::error('Wrong local Lab');
global $wpdb;
$counts=static function()use($wpdb){return [
 'orders'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
 'requests'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}commerce_request_batches"),
 'tasks'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}fbs_tasks"),
 'holds'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}abk_inventory_holds"),
 'bookings'=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='tour_bookings'")];};
$before=$counts();$checks=[];
$mark=static function($key,bool $ok,$data=null)use(&$checks){$checks[$key]=['pass'=>$ok,'observed'=>$data];};
wp_set_current_user(1);
$readiness=\Commerce\Composition\CommercialQuoteReadiness::class;
$over=$readiness::overview();
$mark('overview_has_site_bound_policy',is_array($over)&&($over['policy_status']??null)==='draft');
$mark('native_tax_disabled_observed',is_array($over)&&$over['woocommerce_tax_calculation']==='no');
$mark('provider_rules_bounded_and_named',is_array($over)&&count($over['native_rule_coverage'])===8);
$mark('no_certified_final_quote_provider',is_array($over)&&$over['final_quote_provider_registered']===false);
$mark('no_cost_tax_inventory_provider_receipts',is_array($over)&&
 !$over['supplier_cost_receipt_registered']&&!$over['tax_receipt_registered']&&
 !$over['availability_hold_receipt_registered']);
$mark('no_approval_or_order_authority',is_array($over)&&
 !$over['commercial_quote_certification_authorized']&&!$over['woo_order_creation_authorized']);
$labProvider=[
 'contract'=>'commerce.source-evidence-provider.v1',
 'site_uuid'=>$over['site_uuid'],
 'provider_id'=>'qa43.localcost.v1',
 'source_owner'=>'native_supplier_contract',
 'read'=>static fn()=>['unverified_source_data'=>true]
];
add_filter('commerce_composition_final_quote_evidence_providers',
 static fn()=>['supplier_cost'=>$labProvider],10,0);
$withProvider=$readiness::overview();
$mark('tenant_exact_provider_discovered_only',
 is_array($withProvider)&&$withProvider['supplier_cost_receipt_registered']===true
 &&($withProvider['registered_source_providers']['supplier_cost']['provider_id']??null)==='qa43.localcost.v1');
$mark('registered_provider_cannot_escalate_certification',
 is_array($withProvider)&&
 $withProvider['registered_source_providers']['supplier_cost']['evidence_certified']===false
 &&$withProvider['commercial_quote_certification_authorized']===false);
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$foreignProvider=$labProvider;$foreignProvider['site_uuid']='not-current-tenant';
add_filter('commerce_composition_final_quote_evidence_providers',
 static fn()=>['supplier_cost'=>$foreignProvider],10,0);
$foreignOverview=$readiness::overview();
$mark('cross_tenant_provider_registration_ignored',
 is_array($foreignOverview)&&$foreignOverview['supplier_cost_receipt_registered']===false);
remove_all_filters('commerce_composition_final_quote_evidence_providers');
$config=[
 'schema_version'=>2,'tour_id'=>60629,'source'=>'rate','rate_id'=>60820,
 'travel_date'=>'2026-11-10','audience'=>'general','occupancy'=>'double',
 'quantities'=>['persons'=>2],'booking_intent'=>'quote','extras'=>[],'selections'=>[]
];
$rate=$readiness::inspect(60820,$config,'independent_rate');
$parent=$readiness::inspect(60629,$config,'parent_service');
$mark('standalone_rate_readiness_array',is_array($rate),is_wp_error($rate)?$rate->get_error_code():null);
$mark('tour_parent_readiness_array',is_array($parent),is_wp_error($parent)?$parent->get_error_code():null);
$mark('rate_native_product_id_unchanged',
 is_array($rate)&&$rate['selected_sale_line']['woo_product_id']===60820);
$mark('parent_native_product_id_unchanged',
 is_array($parent)&&$parent['selected_sale_line']['woo_product_id']===60629);
$mark('same_source_and_policy_digests',
 is_array($parent)&&is_array($rate)&&$parent['policy_digest']===$rate['policy_digest']
 &&$parent['preflight_digest']===$rate['preflight_digest']);
$mark('distinct_readiness_per_selected_sale_line',
 is_array($parent)&&is_array($rate)&&$parent['readiness_digest']!==$rate['readiness_digest']);
$mark('policy_draft_blocks_certification',
 is_array($rate)&&$rate['gates']['site_company_policy']['state']==='blocked'
 &&in_array('company_policy_not_active',$rate['gates']['site_company_policy']['reasons'],true));
$mark('native_financial_mode_reason_exposed',
 is_array($rate)&&in_array('inferred_mode_policy_pending_approval',$rate['native_financial_blockers'],true));
$mark('native_tax_disabled_blocks_certification',
 is_array($rate)&&in_array('woocommerce_tax_calculation_disabled',
 $rate['gates']['tax_and_fees']['reasons'],true));
$mark('manual_company_availability_provenance',
 is_array($rate)&&$rate['gates']['availability_inventory']['next_action']==='obtain_supplier_confirmation_and_expiring_hold');
$mark('unverified_supplier_cost_not_fabricated',
 is_array($rate)&&$rate['gates']['supplier_cost_margin']['state']==='blocked');
$mark('pricing_preview_never_final',
 is_array($rate)&&$rate['gates']['final_pricing']['state']==='blocked');
$mark('manager_and_traveler_remain_independent',
 is_array($rate)&&$rate['gates']['manager_decision']['state']==='blocked'
 &&$rate['gates']['traveler_acceptance']['state']==='blocked');
$mark('native_woo_unpurchasable_exposed',
 is_array($rate)&&in_array('woo_product_not_purchasable',$rate['gates']['woo_cart_order']['reasons'],true));
$mark('no_financial_or_booking_writes',
 is_array($rate)&&!$rate['final_quote_certified']&&!$rate['order_authorized']&&
 !$rate['payment_authorized']&&!$rate['supplier_booking_authorized']);
$mark('no_fake_quantity_or_cart_price',
 is_array($rate)&&$rate['selected_sale_line']['quantity']===null&&
 $rate['selected_sale_line']['cart_price']===null);
$mark('pending_gates_match_states',is_array($rate)&&
 $rate['pending_gate_count']===count($rate['pending_gates'])&&$rate['pending_gate_count']>=9);
$mark('deterministic_digest',
 is_array($rate)&&$rate['readiness_digest']===$readiness::inspect(60820,$config,'independent_rate')['readiness_digest']);
$bad=$config;$bad['tour_id']=60655;
$invalid=$readiness::inspect(60820,$bad,'independent_rate');
$mark('cross_tour_rate_denied',is_wp_error($invalid));
$bad=$config;$bad['travel_date']='2026-02-31';
$invalid=$readiness::inspect(60820,$bad,'independent_rate');
$mark('invalid_date_does_not_certify_quote',
 is_wp_error($invalid)||(is_array($invalid)&&$invalid['final_quote_certified']===false));
$rest=static function(string $method,string $path,?array $body=null){
 $r=new \WP_REST_Request($method,'/commerce-composition/v1'.$path);
 if($body!==null){$r->set_header('Content-Type','application/json');$r->set_body(wp_json_encode($body));}
 return rest_do_request($r);
};
$overRest=$rest('GET','/management/commercial-quote/providers');
$mark('providers_rest_admin_200',$overRest->get_status()===200,$overRest->get_status());
$req=['source_id'=>60820,'configuration'=>$config,'sale_role'=>'independent_rate'];
$inspection=$rest('POST','/management/commercial-quote/readiness',$req);
$mark('readiness_rest_admin_200',$inspection->get_status()===200,
 $inspection->get_status()!==200?$inspection->get_data():null);
$mark('rest_authorization_stays_false',
 $inspection->get_status()===200&&($inspection->get_data()['order_authorized']??true)===false);
$wrong=$req;$wrong['client_signed_quote']=true;
$badRequest=$rest('POST','/management/commercial-quote/readiness',$wrong);
$mark('caller_supplied_certification_not_accepted',$badRequest->get_status()===400);
wp_set_current_user(0);
$guestRead=$rest('GET','/management/commercial-quote/providers');
$mark('visitor_provider_read_denied',in_array($guestRead->get_status(),[401,403],true));
$guestWrite=$rest('POST','/management/commercial-quote/readiness',$req);
$mark('visitor_readiness_post_denied',in_array($guestWrite->get_status(),[401,403],true));
wp_set_current_user(1);
ob_start();\Commerce\Composition\CommerceEngineDashboard::render();$html=ob_get_clean();
$mark('admin_dashboard_readiness_panel',
 str_contains($html,'Final Quote')&&str_contains($html,'Provider Readiness')&&
 str_contains($html,'Not yet certified')&&str_contains($html,'Woo Tax:'));
$after=$counts();
$mark('business_rows_unchanged',$before===$after,['before'=>$before,'after'=>$after]);
$passed=count(array_filter($checks,static fn($x)=>$x['pass']));
$ok=$passed===count($checks);
echo wp_json_encode(['result'=>$ok?'PASS':'FAIL','passed'=>$passed,
 'total'=>count($checks),'tests'=>$checks],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES),PHP_EOL;
if(!$ok)WP_CLI::error('QA43 integration test failed');
