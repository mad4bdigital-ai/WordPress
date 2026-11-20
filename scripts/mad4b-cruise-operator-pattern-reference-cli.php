<?php
/** Site-specific cruise bank sequence operator-pattern evidence; no supplier approval. */
if(!defined('ABSPATH') || !defined('WP_CLI') || !WP_CLI) return;
const KEY='_mad4b_cruise_operator_pattern_reference_v1';
$pairs=[57562=>57563,60437=>60611,60439=>60609,60441=>60607,60443=>60601,60623=>60661,60629=>60655];
$mode=(string)(getenv('MAD4B_CRUISE_PATTERN_MODE')?:'plan');
if(!in_array($mode,['plan','apply','verify'],true))WP_CLI::error('mode_invalid');
if(home_url()!=='https://wayfatours.com')WP_CLI::error('wrong_site');
$examples=[
 ['vessel'=>'Sonesta Sun Goddess','program'=>'3_nights_aswan_luxor',
  'source_url'=>'https://staging.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/itinerary',
  'source_scope'=>'operator_owned_staging_itinerary_not_live_sailing_confirmation',
  'west_relative_day'=>3,'east_relative_day'=>3,
  'pattern'=>'same_day_west_07:00_then_east_15:00','departure_relative_day'=>4],
 ['vessel'=>'Sonesta St. George I','program'=>'3_nights_aswan_luxor',
  'source_url'=>'https://staging.sonesta.com/sonesta-st-george-1-nile-cruise-ship/itinerary',
  'source_scope'=>'operator_owned_staging_itinerary_not_live_sailing_confirmation',
  'west_relative_day'=>3,'east_relative_day'=>4,
  'pattern'=>'west_day_3_then_east_day_4_after_disembarkation','departure_relative_day'=>4],
 ['vessel'=>'Sonesta Sun Goddess','program'=>'4_nights_luxor_aswan',
  'source_url'=>'https://staging.sonesta.com/sonesta-sun-goddess-nile-cruise-ship/itinerary',
  'source_scope'=>'operator_owned_staging_itinerary_not_live_sailing_confirmation',
  'west_relative_day'=>2,'east_relative_day'=>1,
  'pattern'=>'east_day_1_then_west_day_2','departure_relative_day'=>5],
];
$results=[];$created=0;$missing=0;
foreach($pairs as $english=>$spanish){
 $en=get_post_meta($english,'_mad4b_cruise_program_day_sequence_source_audit_v1',true);
 if(!is_array($en)||($en['tour_id']??null)!==$english||
   !in_array($en['status']??'',[
    'primary_bank_sequence_differs_operator_review_required',
    'primary_bank_sequence_matches_requires_operator_confirmation'],true))
    WP_CLI::error('english_audit_not_qualified_'.$english);
 foreach(['en'=>$english,'es'=>$spanish] as $lang=>$id){
   if(get_post_type($id)!=='tours-and-activities'||get_post_status($id)!=='publish')
      WP_CLI::error('tour_invalid_'.$id);
   $audit=get_post_meta($id,'_mad4b_cruise_program_day_sequence_source_audit_v1',true);
   if(!is_array($audit)||($audit['tour_id']??0)!==$id||
     ($audit['en_tour_id']??0)!==$english||
     ($audit['wpml_trid']??null)!==$en['wpml_trid']||
     ($audit['language']??'')!==$lang||
     ($audit['operator_supplier_excursion_sequence_confirmed']??true)!==false||
     ($audit['vessel_selected']??true)!==false||
     ($audit['checkout_ready']??true)!==false)
       WP_CLI::error('localized_audit_inconsistent_'.$id);
   if((int)($audit['program_term_id_en']??0)!==(int)($en['program_term_id_en']??0)||
     (int)($audit['program_nights']??0)!==(int)($en['program_nights']??0))
       WP_CLI::error('program_mismatch_'.$id);
   $nights=(int)($audit['program_nights']??0);
   if(!in_array($nights,[3,4],true))WP_CLI::error('unsupported_cruise_length_'.$id);
   $reference=$nights===3?array_slice($examples,0,2):[$examples[2]];
   $status=(string)$audit['status'];
   $review=$nights===3?'vessel_specific_same_day_vs_post_disembarkation_day_must_be_selected':
     ($status==='primary_bank_sequence_matches_requires_operator_confirmation'?
       'generic_sequence_matches_but_selected_vessel_not_attested':
       'reversed_primary_bank_order_requires_operator_written_approval');
   $doc=[
    'contract'=>'mad4b.cruise-operator-pattern-reference.v1',
    'tour_id'=>$id,'en_tour_id'=>$english,'wpml_trid'=>$audit['wpml_trid'],
    'language'=>$lang,'program_term_id_en'=>$audit['program_term_id_en'],
    'program_nights'=>$nights,'program_route'=>$audit['program_route'],
    'existing_sequence_status'=>$status,'review_recommendation'=>$review,
    'operator_source_type'=>'public_operator_itinerary_reference_only',
    'operator_pattern_examples'=>$reference,
    'current_audit_sha256'=>hash('sha256',wp_json_encode($audit,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)),
    'selected_vessel_property_id'=>null,
    'selected_vessel_supplier_verified'=>false,
    'travel_date_specific_sailing_verified'=>false,
    'supplier_written_excursion_approval'=>false,
    'operator_itinerary_parity_confirmed'=>false,
    'original_tour_itinerary_changed'=>false,
    'native_program_modified'=>false,
    'pricing_cost_authorized'=>false,'rates_generated'=>false,'checkout_ready'=>false
   ];
   $old=get_post_meta($id,KEY,true);
   $isNew=!metadata_exists('post',$id,KEY);
   if(!$isNew && $old!==$doc)WP_CLI::error('existing_evidence_not_replaced_'.$id);
   if($mode==='verify' && $isNew)WP_CLI::error('missing_expected_evidence_'.$id);
   if($mode==='apply' && $isNew){
     if(!add_post_meta($id,KEY,$doc,true))WP_CLI::error('meta_add_failed_'.$id);
     $read=get_post_meta($id,KEY,true);
     if($read!==$doc)WP_CLI::error('meta_readback_failed_'.$id);
     $created++;
   }
   if($isNew)$missing++;
   $results[]=['tour'=>$id,'language'=>$lang,'status'=>$status,
    'program_nights'=>$nights,'review_recommendation'=>$review,
    'evidence_record_present'=>!$isNew||$mode==='apply'];
 }
}
if(count($results)!==14||($mode==='verify'&&$missing!==0))
 WP_CLI::error('cohort_cardinality_incorrect');
$out=['contract'=>'mad4b.cruise-operator-pattern-reference.cli.v1','mode'=>$mode,
 'site'=>home_url(),'tour_count'=>count($results),'new_records'=>$created,
 'missing_before_mode'=>$missing,'reference_patterns'=>count($examples),
 'original_cruise_audits_replaced'=>0,'original_itineraries_modified'=>0,
 'operator_attestations_created'=>0,'rates_generated'=>0,'checkout_ready'=>false,
 'results'=>$results];
WP_CLI::line(wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
