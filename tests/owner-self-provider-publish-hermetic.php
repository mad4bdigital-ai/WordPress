<?php
/** Guard regression: publication != commercial rate authorization. */
error_reporting(E_ALL);
define('ABSPATH', '/tmp/');
function add_filter(...$args){}
function add_action(...$args){}
function get_post_type($id){return match((int)$id){101=>'property-rates',201=>'properties',102=>'property-rates',default=>false};}
function get_post_meta($id,$key,$single=true){global $MAD4B_META;return $MAD4B_META[(int)$id][$key]??'';}
function get_the_title($id){global $MAD4B_TITLES;return $MAD4B_TITLES[(int)$id]??'';}
function wp_json_encode($value,$flags=0){return json_encode($value,$flags);}
$source=getenv('MAD4B_OWNER_PUBLISH_SOURCE')?:dirname(__DIR__).'/wp-content/mu-plugins/007-mad4b-stay-price-layers-admin.php';
require_once $source;
$hash='d0d4b70a9b73079206bd31a58ef9af1e82e784752f2e421e8948b06d337fc920';
$proposal=[
 'schema'=>'mad4b.property-wide-rate-proposal.v1','supplier_contract_verified'=>false,
 'nightly_basis_verified'=>false,'cost_receipt_approved'=>false,
 'commercial_activation_allowed'=>false,'source_sheet_id'=>'1PxrMcF2CuZrRGZ6R-2ZEeuqHXoqL6dT150XkhZk04-c',
 'source_gid'=>0,'source_row'=>95,'source_cell'=>'C95','source_entity'=>'Triumph Luxury',
 'meal_plan'=>'BB','occupancy'=>'triple','source_season'=>'summer'];
$approval=[
 'contract'=>'mad4b.self-provider-reference-cost-owner-approval.v1',
 'owner_authority'=>'explicit_owner_chat_instruction_2026-11-21',
 'supplier'=>'self','approval_scope'=>'source-matched-reference-cost-only',
 'source_sheet_id'=>$proposal['source_sheet_id'],'source_gid'=>0,'source_tab'=>'Hotels',
 'source_cell'=>'C95','source_row'=>95,'property_id'=>201,'meal_plan'=>'BB',
 'occupancy'=>'triple','season'=>'summer','source_hotel_name'=>'Triumph Luxury',
 'source_price_basis'=>'per_person_as_sheet_listed','sheet_snapshot_sha256'=>$hash,
 'cost_value_source_approved'=>true,'external_supplier_contract_required'=>false,
 'nightly_basis_verified'=>false,'native_cost_receipt_signed'=>false,
 'inventory_verified'=>false,'availability_verified'=>false,
 'selling_rate_authorized'=>false,'checkout_authorized'=>false,'post_publication_authorized'=>false,
 'price_value_usd'=>'83.00'];
$approval['source_value_fingerprint_sha256']=hash('sha256',wp_json_encode([
 $approval['source_sheet_id'],0,$approval['source_cell'],$approval['source_hotel_name'],
 $approval['price_value_usd'],$approval['occupancy'],$approval['meal_plan'],
 $approval['season'],$approval['sheet_snapshot_sha256']],JSON_UNESCAPED_SLASHES));
$MAD4B_META=[
 101=>['_mad4b_supplier_sheet_quote_unapproved_v1'=>'1',
 'mad4b_rate_scope_v1'=>'property','mad4b_rate_price_kind_v1'=>'cost',
 'prop_related_unit_id'=>'0','prop_related_property_id'=>'201','prop_price_unit'=>'per_person',
 'currency'=>'USD','stop_sale'=>'1','avilabile_status'=>'0',
 '_qa_disallow_public_checkout'=>'1',
 '_mad4b_supplier_sheet_proposal_v1'=>$proposal,
 '_mad4b_self_provider_owner_cost_approval_v1'=>$approval,
 'base_amount'=>'83'],
 102=>['_mad4b_supplier_sheet_quote_unapproved_v1'=>'1']
];
$MAD4B_TITLES=[101=>'Triumph Luxury Summer Triple',102=>'QA ONLY Fixture'];
$base=$MAD4B_META;$titles=$MAD4B_TITLES;$checks=[];
function check($name,$valid){global $checks;$checks[$name]=(bool)$valid;}
function guarded(){return MAD4B_Stay_Layers_Admin_V1::owner_self_cost_reference_publishable(101);}
function publication(){return MAD4B_Stay_Layers_Admin_V1::prevent_unverified_sheet_publication(
 ['post_type'=>'property-rates','post_status'=>'publish'],['ID'=>101])['post_status'];}
check('owner_cost_verified_without_checkout_grant',guarded());
check('owner_approved_rate_can_publish_admin',publication()==='publish');
check('unrelated_post_type_unmodified',MAD4B_Stay_Layers_Admin_V1::prevent_unverified_sheet_publication(
 ['post_type'=>'properties','post_status'=>'publish'],['ID'=>101])['post_status']==='publish');
check('unapproved_sheet_stays_draft',MAD4B_Stay_Layers_Admin_V1::prevent_unverified_sheet_publication(
 ['post_type'=>'property-rates','post_status'=>'publish'],['ID'=>102])['post_status']==='draft');
$MAD4B_META=$base;$MAD4B_META[101]['base_amount']='99';
check('price_mutation_fail_closed',!guarded() && publication()==='draft');
$MAD4B_META=$base;$MAD4B_META[101]['stop_sale']='0';
check('stop_sale_removed_fail_closed',!guarded() && publication()==='draft');
$MAD4B_META=$base;$MAD4B_META[101]['_qa_disallow_public_checkout']='';
check('checkout_block_removed_fail_closed',!guarded());
$MAD4B_META=$base;$MAD4B_TITLES[101]='QA ONLY - never approved';
check('qa_fixture_title_fail_closed',!guarded());
$MAD4B_TITLES=$titles;$MAD4B_META=$base;
$MAD4B_META[101]['_mad4b_supplier_sheet_proposal_v1']['source_cell']='H95';
check('original_source_cell_changed_fail_closed',!guarded());
$MAD4B_META=$base;$MAD4B_META[101]['_mad4b_self_provider_owner_cost_approval_v1']['sheet_snapshot_sha256']=str_repeat('0',64);
check('source_snapshot_changed_fail_closed',!guarded());
$MAD4B_META=$base;$MAD4B_META[101]['_mad4b_self_provider_owner_cost_approval_v1']['checkout_authorized']=true;
check('checkout_grant_must_remain_false',!guarded());
$MAD4B_META=$base;$MAD4B_META[101]['_mad4b_self_provider_owner_cost_approval_v1']['source_value_fingerprint_sha256']=str_repeat('0',64);
check('owner_receipt_fingerprint_checked',!guarded());
$MAD4B_META=$base;$MAD4B_META[101]['_mad4b_supplier_sheet_proposal_v1']['nightly_basis_verified']=true;
check('original_source_proposal_not_rewritten',!guarded());
$MAD4B_META=$base;
$fail=array_keys(array_filter($checks,static fn($ok)=>!$ok));
echo json_encode(['contract'=>'mad4b.owner-self-cost-publish-hermetic.v1',
 'total'=>count($checks),'passed'=>count($checks)-count($fail),'failures'=>$fail,
 'checks'=>$checks],JSON_UNESCAPED_SLASHES)."\n";
if($fail)exit(1);
