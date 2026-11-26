<?php
/**
 * Read-only live regression for MAD4B Transport Package Draft Workflow.
 *
 * Usage (in an authorized WordPress runtime):
 *   wp --user=<authorized-admin-id> eval-file tests/transport-package-workflow-regression.php
 *
 * NEVER writes Rates, availability, prices, terms, assignments, or posts.
 * The separate reversible Save/Readback/stale-tab test is executed manually
 * with original postmeta snapshots and full restoration.
 */
if(!defined('ABSPATH'))exit(1);
$fail=[];$results=[];
$assert=static function($condition,$name)use(&$results,&$fail){
 $results[$name]=(bool)$condition;
 if(!$condition)$fail[]=$name;
};
$assert(class_exists('MAD4B_Transport_Package_Workflow_V1'),'workflow_loaded');
$assert(class_exists('MAD4B_Transport_Cost_Bridge_V1'),'cost_bridge_loaded');
$assert(MAD4B_Transport_Package_Workflow_V1::permitted(),'operator_permitted');
$cairo=get_term_by('slug','cairo','destinations');
$hurghada=get_term_by('slug','hurghada','destinations');
$assert($cairo instanceof WP_Term&&$hurghada instanceof WP_Term,'route_terms_available');
$sources=get_posts(['post_type'=>'transportations','post_status'=>'publish',
 'fields'=>'ids','posts_per_page'=>100,'suppress_filters'=>true,
 'meta_key'=>'_mad4b_transfer_pdf_2026_source_key','meta_value'=>'Cairo:11']);
$source=0;
foreach($sources as $id){
 $lang=apply_filters('wpml_post_language_details',null,(int)$id);
 if(is_array($lang)&&($lang['language_code']??'')==='en'){$source=(int)$id;break;}
}
$assert($source>0,'english_pdf_source_found');
$stage=['key'=>'transfer01','label'=>'Cairo to Hurghada','day_offset'=>0,
 'origin_term_id'=>$cairo instanceof WP_Term?$cairo->term_id:0,
 'destination_term_id'=>$hurghada instanceof WP_Term?$hurghada->term_id:0,
 'join'=>'OR','required'=>true,'return_to_origin'=>false,'price_policy'=>'inquiry',
 'service_kind'=>'transfer','transport_ids'=>[]];
$candidates=MAD4B_Transport_Package_Workflow_V1::candidates($stage,'en');
$eligible=!is_wp_error($candidates)?$candidates['draft_eligible']:[];
$assert(!is_wp_error($candidates),'route_lookup_valid');
$matching=array_values(array_filter($eligible,static fn($r)=>(int)$r['post_id']===$source));
$assert(count($matching)===1,'english_source_draft_eligible');
if($matching){
 $assert($matching[0]['commercial_ready']===false &&
   $matching[0]['checkout_authorized']===false,'no_false_sale_readiness');
 $assert(!empty($matching[0]['available_vehicle_pdf_costs_egp']['limousine']),
   'vehicle_cost_reference_available');
}
$line=['stage_key'=>'transfer01','transportation_id'=>$source,
 'vehicle'=>'limousine','service'=>'transfer','road'=>'highway','km'=>10,
 'drive_min'=>20,'quantity'=>1];
$cost=MAD4B_Transport_Cost_Bridge_V1::evaluate([
 'context'=>'planning','lines'=>[$line]]);
$assert(!is_wp_error($cost),'cost_bridge_valid');
if(!is_wp_error($cost)){
 $assert(($cost['total_pdf_reference_company_cost_egp']??0)>0 &&
   ($cost['total_modeled_operating_cost_egp']??0)>0,'pdf_and_model_separate');
 $assert(($cost['checkout_authorized']??true)===false &&
   ($cost['write_performed']??true)===false,'read_only_cost_preview');
}
$bad=MAD4B_Transport_Cost_Bridge_V1::evaluate([
 'context'=>'planning','lines'=>[$line,$line]]);
$assert(is_wp_error($bad)&&$bad->get_error_code()==='transport_bridge_duplicate_selected_stage',
 'duplicate_alt_denied');
$trash=MAD4B_Transport_Cost_Bridge_V1::evaluate([
 'context'=>'planning','lines'=>[array_replace($line,['transport_rate_id'=>66570])]]);
$assert(is_wp_error($trash)&&$trash->get_error_code()==='transport_bridge_rate_source_invalid',
 'trashed_rate_denied');
$drafts=get_posts(['post_type'=>'tour-rates','post_status'=>'draft',
 'fields'=>'ids','posts_per_page'=>100]);
$tour=0;
foreach($drafts as $id){
 $p=(int)get_post_meta($id,'related_tour_id',true);
 $lang=apply_filters('wpml_post_language_details',null,(int)$id);
 if($p&&get_post_type($p)==='tours-and-activities'&&is_array($lang)&&
    ($lang['language_code']??'')==='en'){$tour=(int)$id;break;}
}
$assert($tour>0,'draft_rate_present_for_edit_test');
if($tour){
 $snap=MAD4B_Transport_Package_Workflow_V1::snapshot($tour);
 $assert(!is_wp_error($snap),'snapshot_readable');
 if(!is_wp_error($snap)){
  $stale=MAD4B_Transport_Package_Workflow_V1::save([
   'tour_rate_id'=>$tour,'expected_hash'=>str_repeat('0',64),'mode'=>'simple',
   'stages'=>[array_replace($stage,['transport_ids'=>[$source]])],'cost_lines'=>[$line]]);
  $assert(is_wp_error($stale)&&$stale->get_error_code()==='transport_package_stale_editor',
   'stale_tab_fails_before_write');
  $again=MAD4B_Transport_Package_Workflow_V1::snapshot($tour);
  $assert(!is_wp_error($again)&&$snap['hash']===$again['hash'],
   'no_postmeta_change_after_stale_attempt');
 }
}
global $wpdb;
$draftCount=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='transport-rates' AND post_status='draft'");
$assert($draftCount===0,'no_legacy_draft_transport_rates');
echo wp_json_encode(['site'=>home_url(),'contract'=>'mad4b.transport-package-regression.v1',
 'passes'=>count($results)-count($fail),'total'=>count($results),'failures'=>$fail,
 'checks'=>$results,'checkout_authorized'=>false,'mutation_performed'=>false],
 JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
if($fail){if(class_exists('WP_CLI'))WP_CLI::halt(1);exit(1);}
