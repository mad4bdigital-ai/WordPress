<?php
if(!defined('ABSPATH')||!defined('WP_CLI'))exit(1);
$inner='MAD4B_Guide_JetEngine_Inner_V1';$core='MAD4B_Guide_Service_Composition_V1';
if(!class_exists($inner,false))WP_CLI::error('JetEngine inner bridge absent');
$orig=[];
foreach(['cpt'=>'tours-and-activities','taxonomies'=>'programs'] as $component=>$object){
 $entries=jet_engine()->$component->data->get_raw();
 foreach($entries as $row)if(is_array($row)&&($row['slug']??'')===$object){
   $raw=jet_engine_safe_unserialize_array($row['meta_fields']??'','guide-inner-test');
   $rawFieldName=$component==='cpt'?'itinerary_item':'program_itinerary';
   $outer=null;
   foreach($raw as $f)if(is_array($f)&&($f['name']??'')===$rawFieldName)$outer=$f;
   if(!$outer)WP_CLI::error('Expected JetEngine repeater missing '.$rawFieldName);
   $names=array_column($outer['repeater-fields'],'name');
   if(in_array($inner::FIELD,$names,true))WP_CLI::error('JetEngine persistent registry was mutated');
   $proposed=apply_filters('jet-engine/meta-boxes/raw-fields',$raw,jet_engine()->$component);
   $replayed=apply_filters('jet-engine/meta-boxes/raw-fields',$proposed,jet_engine()->$component);
   $found=null;$seen=0;
   foreach($replayed as $f)if(is_array($f)&&($f['name']??'')===$rawFieldName){
     foreach($f['repeater-fields'] as $nested)
       if(($nested['name']??'')===$inner::FIELD){$found=$nested;$seen++;}
   }
   if($seen!==1 || ($found['type']??'')!=='select' || count($found['options']??[])!==5)
    WP_CLI::error('Native JetEngine inner field missing or duplicate for '.$rawFieldName);
   $mapping=array_column($found['options'],'key');
   if($mapping!==$inner::STATES)WP_CLI::error('Options mapping mismatch '.$rawFieldName);
   echo "PASS JETENGINE_NATIVE_INNER_FIELD ".$object."::".$rawFieldName." injected_once_unmodified_registry\n";
 }
}
$src=$core::source(55260);
$review=$core::saved($src);
$draft=$inner::draftForSource($src);
if(is_wp_error($review) || ($review['statuses']['day-1']??'')!=='none' || !is_array($draft))
 WP_CLI::error('No-guide source sidecar damaged');
$cost=$core::guide(55260,'');
if(is_wp_error($cost) || $cost['cost']!=0 || $cost['checkout_ready']!==false)
 WP_CLI::error('Zero Guide cost did not survive inner editor injection');
echo "PASS NOGUIDE_55260 approved_no_guide_price_0_independent_of_inner_draft\n";
$original=get_post_meta(55260,'itinerary_item',true);
if(!is_array($original)||count($original)<2)WP_CLI::error('Expected JetEngine activity stages');
$draft=$original;
$draft['item-1'][$inner::FIELD]='full_day';
$cb=static function($value,$postId,$key,$single)use(&$draft){
 if((int)$postId===55260 && $key==='itinerary_item')return [$draft];
 return $value;
};
add_filter('get_post_metadata',$cb,28000,4);
$one=$inner::draftForSource($src);
if(($one['day-1']??null)!=='full_day')WP_CLI::error('Multi-stage single-day draft not aggregated');
$blocked=$core::guide(55260,'');
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='guide_jetengine_draft_conflicts_with_approved_review')
 WP_CLI::error('Divergent JetEngine draft silently changed the approved Guide cost');
echo "PASS DRAFT_CONFLICT cannot_override_approved_no_guide_cost\n";
$draft['item-0'][$inner::FIELD]='none';
$both=$inner::draftForSource($src);
if(($both['day-1']??null)!=='conflict')WP_CLI::error('Contradictory stage statuses not marked as conflict');
$blocked=$core::guide(55260,'');
if(!is_wp_error($blocked)||$blocked->get_error_code()!=='guide_jetengine_draft_conflicts_with_approved_review')
 WP_CLI::error('Contradictory stage review unexpectedly billed');
echo "PASS CONTRADICTORY_STAGE_DRAFT blocked\n";
remove_filter('get_post_metadata',$cb,28000);
if(get_post_meta(55260,'itinerary_item',true)!==$original)
 WP_CLI::error('Test wrote actual JetEngine itinerary');
echo "PASS JETENGINE_TEST_NO_REAL_WRITE\n";
$eight=$core::source(60443);
if(is_wp_error($eight)||count($eight['days'])!==8)WP_CLI::error('Package 60443 itinerary corrupted');
$eightCost=$core::guide(60443,'english');
if(!is_wp_error($eightCost)||$eightCost->get_error_code()!=='guide_coverage_not_reviewed')
 WP_CLI::error('JetEngine draft incorrectly unlocked commercial price');
echo "PASS PACKAGE_60443 8_unreviewed_days_draft_not_cost_authority\n";
WP_CLI::success('NATIVE_JETENGINE_INNER_INJECTION_ACCEPTED');
