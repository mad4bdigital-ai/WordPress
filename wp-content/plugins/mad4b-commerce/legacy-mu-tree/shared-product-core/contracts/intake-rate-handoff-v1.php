<?php
/** Reviewed Content Intake => Price-source proposal. No Rate/Unit creation. */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_intake_rate_handoff_v1')){
function mad4b_intake_rate_handoff_v1(array $review, array $options=[]) {
 $plan=$review['plan']??null;
 if(!is_array($plan)||($plan['schema']??'')!=='mad4b.property-source-intake.plan.v1')
  return new WP_Error('intake_price_plan_invalid');
 $digest=$plan['candidate_digest']??'';
 if(!is_string($digest)||!preg_match('/^[a-f0-9]{64}$/D',$digest)
    ||!hash_equals($digest,(string)($review['review_source_digest']??'')))
  return new WP_Error('intake_price_source_digest_mismatch');
 if(($review['state']??'')!=='CONTENT_REVIEW_APPROVED'
   ||($review['review_decision']??'')!=='approve'
   ||empty($review['content_job_id']))
  return new WP_Error('intake_price_editorial_review_required');
 $approvedProvenance=($options['supplier_evidence_verified']??false)===true;
 // This is a proposal classification only; a caller-supplied boolean NEVER
 // grants authoring, provider authority, inventory, checkout or publication.
 $source=(string)($plan['proof_reference']??'');
 $isDemo=preg_match('/(?:^|[^a-z])(test|demo|sample|fixture|not-real|synthetic)(?:[^a-z]|$)/i',$source.' '.($plan['company_property_key']??''))===1;
 $observations=$plan['price_observation_candidates']??[];
 if(!is_array($observations))return new WP_Error('intake_price_observations_invalid');
 $unitKeys=[];
 foreach(($plan['unit_candidates']??[]) as $unit){
   if(!is_array($unit)||!isset($unit['key']))return new WP_Error('intake_price_unit_invalid');
   $unitKeys[$unit['key']]=true;
 }
 $proposals=[];
 foreach($observations as $i=>$o){
  if(!is_array($o)||!is_numeric($o['amount']??null)
    ||!is_finite((float)$o['amount'])||(float)$o['amount']<0
    ||!preg_match('/^[A-Z]{3}$/D',(string)($o['currency']??'')))
    return new WP_Error('intake_price_observation_invalid');
  $unitKey=$o['unit_key']??null;
  if($unitKey!==null&&!isset($unitKeys[$unitKey]))return new WP_Error('intake_price_unit_unknown');
  $proposals[]=[
   'id'=>substr(hash('sha256',$digest.':'.(string)$i),0,20),
   'unit_key'=>$unitKey,'source_amount'=>(float)$o['amount'],
   'currency'=>$o['currency'],'price_basis'=>$o['basis']??null,
   'date_from'=>$o['date_from']??null,'date_to'=>$o['date_to']??null,
   'occupancy'=>$o['occupancy']??null,
   'fees_included'=>$o['fees_included']??false,
   'target'=>'property-rates',
   'pricing_status'=>'source_observation_not_authorized',
   'rate_create_authorized'=>false,
  ];
 }
 $issues=[];
 if($isDemo)$issues[]='synthetic_test_source';
 if(!$approvedProvenance)$issues[]='supplier_price_evidence_unverified';
 if(empty($plan['source_url'])&&($plan['platform']??'')!=='direct_owner')
  $issues[]='original_source_url_missing';
 $issues[]='property_unit_ids_unresolved';
 $issues[]='supplier_inventory_and_availability_unverified';
 $issues[]='fees_taxes_and_cancellation_unconfirmed';
 return [
  'contract'=>'mad4b.intake-rate-handoff.v1','source_digest'=>$digest,
  'content_job_id'=>$review['content_job_id'],
  'product_family'=>$review['product_family']??'properties',
  'source_platform'=>$plan['platform']??'',
  'company_property_key'=>$plan['company_property_key']??'',
  'source_proof_type'=>$plan['proof_type']??null,
  'synthetic_source'=>$isDemo,
  'editorial_review'=>'approved','commercial_review'=>'not_approved',
  'rate_proposals'=>$proposals,'count'=>count($proposals),
  'blockers'=>$issues,
  'status'=>$isDemo?'demo_only_not_commercial':
    'pricing_source_review_required',
  'price_source_authority'=>false,'rate_create_authorized'=>false,
  'checkout_authority'=>false,'side_effects'=>false
 ];
}
}
