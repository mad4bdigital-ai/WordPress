<?php
define('ABSPATH','/');
$done=0;
$rate=118;
$proposal=['schema'=>'mad4b.property-wide-rate-proposal.v1','source_sheet_id'=>'sheet1',
 'source_cell'=>'C29','source_entity'=>'Hotel A','source_season'=>'winter_2026_27',
 'occupancy'=>'double','meal_plan'=>'BB','price_unit'=>'per_person',
 'nightly_basis_verified'=>false,'cost_receipt_approved'=>false,
 'commercial_activation_allowed'=>false];
$approval=['supplier'=>'self','cost_value_source_approved'=>true,
 'source_sheet_id'=>'sheet1','source_cell'=>'C29'];
$meta=[118=>['mad4b_rate_scope_v1'=>'property','prop_related_unit_id'=>0,
 'mad4b_rate_price_kind_v1'=>'cost','_mad4b_supplier_sheet_proposal_v1'=>$proposal,
 '_mad4b_self_provider_owner_cost_approval_v1'=>$approval,'base_amount'=>'40.00',
 'currency'=>'USD','prop_price_unit'=>'per_person']];
$entries=[['cell'=>'C29','source_entity'=>'Hotel A','season'=>'winter_2026_27',
 'occupancy'=>'double','rate_basis'=>'per_person_per_night',
 'evidence'=>'uniform_hotels_template_owner_approved','source_cost_usd_cents'=>4000,
 'checkout_authorized'=>false]];
for($i=1;$i<=252;$i++)$entries[]=['cell'=>'M'.($i+500),'source_cost_usd_cents'=>1];
$options=['mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1'=>[
 'contract'=>'mad4b.company-owner-approved-inferred-hotel-nightly-cost-basis.v1',
 'site'=>'https://wayfatours.com','basis'=>'per_person_per_night',
 'method'=>'owner_approved_uniform_hotels_template_inference',
 'sheet_cell_count'=>253,'entries'=>$entries]];
function get_post_type($id){return $id===118?'property-rates':'';}
function get_post_meta($id,$k,$single=true){return $GLOBALS['meta'][$id][$k]??'';}
function get_option($key){return $GLOBALS['options'][$key]??null;}
function home_url(){return $GLOBALS['site']??'https://wayfatours.com';}
function check($pass,$label){$GLOBALS['done']++;if(!$pass)throw new RuntimeException($label);}
require __DIR__.'/../wp-content/mu-plugins/mad4b-hotel-nightly-cost-basis-v1.php';
$c='MAD4B_Hotel_Nightly_Cost_Basis_V1';
$e=$c::evidence($rate);check($e['valid']===true,'owner basis verified by exact original cell');
check($e['basis']==='per_person_night','normalized unit');
check($e['source_cost_usd_cents']===4000,'amount in cents matches');
check($e['occupancy']==='double','room occupancy remains source-owned');
check($e['cost_receipt_approved']===false,'no phantom supplier receipt');
check($e['checkout_ready']===false,'no phantom checkout');
check($c::effective($rate)===false,'original unconverted rate not normalized');
$meta[118]['prop_price_unit']='per_person_night';
$meta[118]['_mad4b_hotel_cost_basis_normalized_v1']=[
 'contract'=>$c::CONTRACT,'rate_id'=>118,'original_unit'=>'per_person',
 'normalized_unit'=>'per_person_night','source_cell'=>'C29',
 'source_cost_usd_cents'=>4000,'scope'=>'property_cost_not_retail',
 'checkout_authorized'=>false];
check($c::effective($rate)===true,'exact normalized proof recognized');
$meta[118]['base_amount']='41.00';
check($c::effective($rate)===false,'amount change invalidates original cell');
$meta[118]['base_amount']='40.00';
$meta[118]['_mad4b_hotel_cost_basis_normalized_v1']['checkout_authorized']=true;
check($c::effective($rate)===false,'checkout elevation refused');
$meta[118]['_mad4b_hotel_cost_basis_normalized_v1']['checkout_authorized']=false;
$site='https://tours.dev.mad4b.com';
check($c::evidence($rate)['valid']===false,'tenant swap invalidates source evidence');
$site='https://wayfatours.com';
$options['mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1']['entries'][0]['source_cost_usd_cents']=4100;
check($c::effective($rate)===false,'receipt cost changed invalidates proof');
$options['mad4b_company_hotels_uniform_template_nightly_basis_20261122_v1']['entries'][0]['source_cost_usd_cents']=4000;
check($c::effective($rate)===true,'restored matching proof');
$meta[118]['mad4b_rate_price_kind_v1']='sell';
check($c::effective($rate)===false,'selling Rate never normalized as supplier cost');
echo 'PASS '.$done.'/'.$done.' normalized cost basis original source / scope / tenant / approval guards'.PHP_EOL;
