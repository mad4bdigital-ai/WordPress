<?php
/** Canonical Tour Rate locale authority shared by pricing, booking and presentation. */
defined('ABSPATH') || exit;

/**
 * Legacy Rates inherit the existing native source policy with no new gate.
 * Only an explicitly localized Rate has a binding to verify.
 * This checks identity/provenance; it does not create a new price or authorize checkout.
 */
function shared_product_rate_locale_decision(int $rateId, int $expectedTourId=0): array {
 $mode=(string)get_post_meta($rateId,'_shared_generated_locale_policy',true);
 $result=['allowed'=>true,'mode'=>'inherit','reason'=>'legacy_inheritance',
  'rate_id'=>$rateId,'tour_id'=>(int)get_post_meta($rateId,'related_tour_id',true),
  'source_currency'=>(string)get_post_meta($rateId,'base_currency',true)];
 if($mode==='')return $result;
 $result['allowed']=false;
 $result['mode']=$mode;
 if($mode!=='independent'){$result['reason']='unsupported_override';return $result;}
 $parent=$result['tour_id'];
 if($rateId<1||get_post_type($rateId)!=='tour-rates'
   ||$parent<1||get_post_type($parent)!=='tours-and-activities'
   ||($expectedTourId>0&&$parent!==$expectedTourId)){
  $result['reason']='override_parent_mismatch';return $result;
 }
 $language=(string)get_post_meta($rateId,'_shared_generated_locale_language',true);
 $authority=(string)get_post_meta($rateId,'_shared_generated_price_authority',true);
 if(!preg_match('/^[a-z]{2}(?:-[a-z]{2})?$/D',$language)
   ||$authority!=='explicit_locale_amount_and_currency'){
  $result['reason']='override_evidence_missing';return $result;
 }
 $rateLanguage=apply_filters('wpml_post_language_details',null,$rateId);
 $tourLanguage=apply_filters('wpml_post_language_details',null,$parent);
 if(!is_array($rateLanguage)||!is_array($tourLanguage)
   ||($rateLanguage['language_code']??null)!==$language
   ||($tourLanguage['language_code']??null)!==$language){
  $result['reason']='override_language_mismatch';return $result;
 }
 $result['allowed']=true;
 $result['reason']='explicit_locale_authority';
 $result['language']=$language;
 return $result;
}
