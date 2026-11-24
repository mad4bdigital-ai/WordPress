<?php
define('ABSPATH','/');
function get_post_type($id){return $id===11?'property-rates':($id===21?'properties':null);}
function get_post_status($id){return $GLOBALS['status']??'publish';}
function get_post_meta($id,$key,$single){return $GLOBALS['meta'][$key]??'';}
final class MAD4B_Hotel_Nightly_Cost_Basis_V1 {
 static function effective(int $id):bool{return $GLOBALS['effective']??true;}
 static function evidence(int $id):array{return ['valid'=>true,'source_cell'=>'H9','source_cost_usd_cents'=>6000,'occupancy'=>'double','meal_plan'=>'BB'];}
}
require __DIR__.'/../wp-content/mu-plugins/mad4b-company-cost-source-admission-v1.php';
$c='MAD4B_Company_Cost_Source_Admission_V1';
$GLOBALS['meta']=['mad4b_rate_price_kind_v1'=>'cost','prop_related_property_id'=>21,
 '_mad4b_supplier_sheet_proposal_v1'=>['source_sheet_id'=>'sheet','source_season'=>'winter'],
 '_mad4b_company_cost_value_receipt_v1'=>['contract'=>'mad4b.company-owner-confirmed-cost-value.v1','status'=>'approved_source_cost_value_only','source_price_confirmed'=>true,'supplier_type'=>'company_self','rate_id'=>11,'property_id'=>21,'source_sheet_id'=>'sheet','source_cell'=>'H9','occupancy'=>'double','meal_plan'=>'BB','source_season'=>'winter','currency'=>'USD','amount_minor'=>6000,'receipt_fingerprint_sha256'=>str_repeat('a',64)]];
$n=0;function ck($v,$why){global $n;if(!$v)throw new RuntimeException($why);$n++;}
$good=$GLOBALS['meta'];$r=$c::verify(11);
ck($r['qualified']&&$r['amount_minor']===6000,'source verified');
ck(!$r['stage_receipt_qualified']&&!$r['checkout_authorized'],'source is not commercial receipt');
foreach(['amount_minor'=>6100,'currency'=>'EGP','property_id'=>22,'source_cell'=>'H10','occupancy'=>'triple','meal_plan'=>'HB','source_price_confirmed'=>false,'source_sheet_id'=>'other','rate_id'=>12,'receipt_fingerprint_sha256'=>'bad'] as $k=>$v){
 $GLOBALS['meta']=$good;$GLOBALS['meta']['_mad4b_company_cost_value_receipt_v1'][$k]=$v;
 ck(!$c::verify(11)['qualified'],'reject changed '.$k);
}
$GLOBALS['meta']=$good;$GLOBALS['meta']['mad4b_rate_price_kind_v1']='retail';ck(!$c::verify(11)['qualified'],'retail denied');
$GLOBALS['meta']=$good;$GLOBALS['effective']=false;ck(!$c::verify(11)['qualified'],'stale basis denied');
$GLOBALS['effective']=true;$GLOBALS['status']='draft';ck(!$c::verify(11)['qualified'],'draft denied');
echo "PASS $n/$n company source-value admission\n";
