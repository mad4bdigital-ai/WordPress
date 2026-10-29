<?php
declare(strict_types=1);
class WP_Error {
    public function __construct(private string $code, private string $description='') {}
    public function get_error_code():string {return $this->code;}
}
function wp_json_encode(mixed $x,int $flags=0):string|false {return json_encode($x,$flags);}
require __DIR__.'/../includes/CommercialQuotePlan.php';

use Commerce\Composition\CommercialQuotePlan as Plan;
$receipt=[
 'contract'=>'commerce.commercial-preflight.v1','site_uuid'=>'test-site',
 'policy_revision'=>1,'preflight_digest'=>str_repeat('a',64),
 'policy_digest'=>str_repeat('b',64),'no_side_effects'=>true,
 'single_sale_line_required'=>true,'double_charge_parent_and_rate_forbidden'=>true,
 'commercial_quote_issued'=>false,'cart_mutated'=>false,'order_created'=>false,
 'supplier_booking_created'=>false,
 'source'=>['service_id'=>60629,'rate_id'=>60820,'relationship_verified'=>true],
 'sale_line_choices'=>[
  ['role'=>'parent_service','product_id'=>60629,'source_post_type'=>'tours-and-activities','cart_write_authorized'=>false,'order_item_write_authorized'=>false],
  ['role'=>'independent_rate','product_id'=>60820,'source_post_type'=>'tour-rates','cart_write_authorized'=>false,'order_item_write_authorized'=>false]
 ],
 'company_policy'=>['availability_mode'=>'manual_confirmation','order_timing'=>'after_acceptance','supplier_booking_timing'=>'manual'],
 'approvals'=>['manager'=>['company_mode'=>'required'],'traveler'=>['company_mode'=>'required']],
 'pricing_preview'=>['status'=>'indicative_only','amount'=>4620,'currency'=>'USD','price_is_final'=>false]
];
$checks=[];
$assert=static function(string $key,bool $ok) use (&$checks){$checks[$key]=$ok;};
$r=Plan::fromPreflight($receipt,'independent_rate');
$p=Plan::fromPreflight($receipt,'parent_service');
$assert('rate_existing_woo_id',is_array($r)&&$r['chosen_sale_line']['woo_product_id']===60820);
$assert('parent_existing_woo_id',is_array($p)&&$p['chosen_sale_line']['woo_product_id']===60629);
$assert('role_changes_plan_digest',is_array($r)&&is_array($p)&&$r['plan_digest']!==$p['plan_digest']);
$assert('never_use_preview_as_cart_price',is_array($r)&&$r['chosen_sale_line']['cart_price']===null&&$r['chosen_sale_line']['quantity']===null);
$assert('price_is_only_indicative',is_array($r)&&$r['indicative_preview']['not_a_final_price']);
$assert('never_write_order_or_supplier',is_array($r)&&!$r['order_creation_authorized']&&!$r['supplier_booking_authorized']&&!$r['cart_write_authorized']&&!$r['payment_authorized']);
$assert('policy_specific_manager_gate',is_array($r)&&$r['requirements']['manager_approval']==='required_same_final_quote_digest');
$assert('policy_specific_availability_gate',is_array($r)&&$r['requirements']['availability']==='supplier_manual_confirmation_receipt');
$mutate=static function(callable $change)use($receipt){$x=$receipt;$change($x);return Plan::fromPreflight($x,'independent_rate') instanceof WP_Error;};
$assert('forged_final_quote_denied',$mutate(static function(&$x){$x['commercial_quote_issued']=true;}));
$assert('preflight_cart_mutation_denied',$mutate(static function(&$x){$x['cart_mutated']=true;}));
$assert('wrong_parent_denied',$mutate(static function(&$x){$x['sale_line_choices'][0]['product_id']=99;}));
$assert('wrong_rate_denied',$mutate(static function(&$x){$x['sale_line_choices'][1]['product_id']=99;}));
$assert('unverified_relation_denied',$mutate(static function(&$x){$x['source']['relationship_verified']=false;}));
$assert('forged_cart_write_denied',$mutate(static function(&$x){$x['sale_line_choices'][1]['cart_write_authorized']=true;}));
$assert('forged_final_preview_denied',$mutate(static function(&$x){$x['pricing_preview']['price_is_final']=true;}));
$assert('broken_digest_denied',$mutate(static function(&$x){$x['preflight_digest']='broken';}));
$assert('unregistered_role_denied',Plan::fromPreflight($receipt,'new_role') instanceof WP_Error);
$c=$receipt;$c['approvals']['manager']['company_mode']='not_required';$n=Plan::fromPreflight($c,'independent_rate');
$assert('company_may_waive_manager',is_array($n)&&$n['requirements']['manager_approval']==='not_required_by_company_policy');
$c=$receipt;$c['company_policy']['availability_mode']='allotment';$n=Plan::fromPreflight($c,'independent_rate');
$assert('company_allotment_mode',is_array($n)&&$n['requirements']['availability']==='native_allotment_hold_receipt');
$ok=!in_array(false,$checks,true);
echo json_encode(['status'=>$ok?'PASS':'FAIL','passed'=>count(array_filter($checks)),'total'=>count($checks),'checks'=>$checks],JSON_PRETTY_PRINT),PHP_EOL;
exit($ok?0:1);
