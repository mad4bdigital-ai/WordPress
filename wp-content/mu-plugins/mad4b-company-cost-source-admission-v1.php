<?php
/** Plugin Name: MAD4B Company Cost Source Admission
 * Source value qualification is separate from signed package cost receipts and booking inventory.
 */
defined('ABSPATH')||exit;
final class MAD4B_Company_Cost_Source_Admission_V1 {
 const CONTRACT='mad4b.company-cost-source-admission.v1';
 const PROVIDER='company_hotel_sheet';
 static function verify(int $id):array {
  $bad=static fn($reason)=>['contract'=>self::CONTRACT,'qualified'=>false,'reason'=>$reason,'stage_receipt_qualified'=>false,'checkout_authorized'=>false];
  if(get_post_type($id)!=='property-rates'||get_post_status($id)!=='publish'||get_post_meta($id,'mad4b_rate_price_kind_v1',true)!=='cost')return $bad('not_published_cost_rate');
  if(!class_exists('MAD4B_Hotel_Nightly_Cost_Basis_V1',false)||!MAD4B_Hotel_Nightly_Cost_Basis_V1::effective($id))return $bad('nightly_basis_not_verified');
  $r=get_post_meta($id,'_mad4b_company_cost_value_receipt_v1',true);
  $p=get_post_meta($id,'_mad4b_supplier_sheet_proposal_v1',true);
  $e=MAD4B_Hotel_Nightly_Cost_Basis_V1::evidence($id);
  $property=(int)get_post_meta($id,'prop_related_property_id',true);
  if(!is_array($r)||!is_array($p)||($r['contract']??'')!=='mad4b.company-owner-confirmed-cost-value.v1'||
     ($r['status']??'')!=='approved_source_cost_value_only'||($r['source_price_confirmed']??false)!==true||
     ($r['supplier_type']??'')!=='company_self'||(int)($r['rate_id']??0)!==$id||
     !$property||(int)($r['property_id']??0)!==$property||get_post_type($property)!=='properties'||
     ($r['source_sheet_id']??'')!==($p['source_sheet_id']??null)||
     ($r['source_cell']??'')!==($e['source_cell']??null)||
     ($r['occupancy']??'')!==($e['occupancy']??null)||
     ($r['meal_plan']??'')!==($e['meal_plan']??null)||
     ($r['source_season']??'')!==($p['source_season']??null)||
     ($r['currency']??'')!=='USD'||($r['amount_minor']??null)!==($e['source_cost_usd_cents']??null))
   return $bad('source_receipt_binding_mismatch');
  $digest=$r['receipt_fingerprint_sha256']??'';
  if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest))return $bad('source_receipt_fingerprint_missing');
  return ['contract'=>self::CONTRACT,'qualified'=>true,'status'=>'owner_confirmed_source_value_only',
    'rate_id'=>$id,'property_id'=>$property,'source_cell'=>$e['source_cell'],'amount_minor'=>$e['source_cost_usd_cents'],
    'currency'=>'USD','basis'=>'per_person_night','occupancy'=>$e['occupancy'],'meal_plan'=>$e['meal_plan'],
    'source_receipt_fingerprint'=>$digest,'stage_receipt_qualified'=>false,'checkout_authorized'=>false];
 }
}
