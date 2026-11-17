<?php
if (!class_exists('MAD4B_Destination_Demand_Rank_V1',false))
    throw new RuntimeException('SHARED_DEMAND_RANK_NOT_LOADED');
$c='MAD4B_Destination_Demand_Rank_V1';
$reflect=new ReflectionClass($c);
if(basename((string)$reflect->getFileName())!=='mad4b-destination-demand-rank-v1.php'
 || !str_contains((string)$reflect->getFileName(),'/mu-plugins/'))
 throw new RuntimeException('DEMAND_RANK_CLASS_NOT_OWNED_BY_MU');
if(has_action('transition_post_status',[$c,'publication_changed'])!==25)
 throw new RuntimeException('PUBLICATION_CACHE_INTEGRATION_MISSING');
$platform_bridge=WP_PLUGIN_DIR.'/mad4b-platform/modules/presentation/mad4b-destination-demand-rank-v1.php';
if(is_file($platform_bridge)){
 $all=get_included_files();
 $verified=false;
 foreach($all as $inc)if(realpath((string)$inc)===realpath($platform_bridge))$verified=true;
 if(!$verified)throw new RuntimeException('PLATFORM_MAP_MODULE_NOT_LOADED');
 $map=require WP_PLUGIN_DIR.'/mad4b-platform/config/ordered-mu-map.php';
 if(!class_exists('MAD4B_MU_Map_Contract_V1',false)||!MAD4B_MU_Map_Contract_V1::current($map))
  throw new RuntimeException('PLATFORM_ORDERED_MU_MAP_INVALID');
}

$manager=\Jet_Engine\Query_Builder\Manager::instance();
$queries=[288=>true,294=>true,285=>true,286=>true,289=>true,296=>true,
  283=>false,284=>false,287=>false,291=>false,299=>false];
foreach($queries as $qid=>$expected){
 $q=$manager->get_query_by_id($qid);
 if((bool)$c::query_qualifies($q)!==$expected)
     throw new RuntimeException('QUERY_CONTRACT_FAILED:'.$qid);
}
$en_ids=[];foreach([722,122,123,721,560,125] as $id){
 $translated=(int)apply_filters('wpml_object_id',$id,'destinations',false,'en');
 $en_ids[]=$translated?:$id;
}
$counts=$c::counts($en_ids,'en');
if(!is_array($counts) || count($counts)!==count($en_ids))
  throw new RuntimeException('EN_COUNT_UNAVAILABLE');
$eligible=function_exists('native_destinations_eligible_children')
  ?native_destinations_eligible_children(665,'en'):[];
$ordered=$c::rank_ids($eligible,'en');
$numbers=array_map(fn($id)=>(int)($counts[$id]??0),$ordered);
if($numbers!==array_values(array_map('intval',$numbers)) || $numbers!==array_values(array_map('intval',array_map(fn($v)=>$v, $numbers))))throw new RuntimeException('TYPE_ERROR');
for($i=1;$i<count($numbers);$i++)if($numbers[$i]>$numbers[$i-1])throw new RuntimeException('NOT_DESCENDING');
if(array_diff($ordered,$eligible)||array_diff($eligible,$ordered)||count($eligible)!==count($ordered))throw new RuntimeException('CHANGED_CANDIDATES');
$settings=['lisitng_id'=>67125,'_css_classes'=>'navigation-editor-grid'];
$args=$c::sort_nav_query_args(['include'=>$eligible,'orderby'=>'name'],$settings,$settings);
if(($args['orderby']??'')!=='include'||$args['include']!==$ordered)throw new RuntimeException('MENU_ARG_ORDER_FAILED');
$other=$c::sort_nav_query_args(['include'=>$eligible,'orderby'=>'name'],[],['lisitng_id'=>67124,'_css_classes'=>'navigation-editor-grid']);
if(($other['orderby']??'')!=='name')throw new RuntimeException('OTHER_LISTING_CHANGED');
$zero_terms=[WP_Term::get_instance(732),WP_Term::get_instance(713),WP_Term::get_instance(742)];
$zero_rank=$c::rank_terms($zero_terms,'en');
if(array_map(fn($t)=>$t->term_id,$zero_rank)!==[732,713,742])throw new RuntimeException('TIED_ZERO_ORDER_CHANGED');
$langs=array_keys((array)apply_filters('wpml_active_languages',null,['skip_missing'=>0]));
$previous=(string)apply_filters('wpml_current_language','');
$per_lang=[];
foreach($langs as $lang){
 $local=array_map(static fn($id)=>(int)apply_filters('wpml_object_id',$id,'destinations',false,$lang),$en_ids);
 $local=array_values(array_filter($local));
 $lc=$c::counts($local,$lang);
 if(!is_array($lc)||count($lc)!==count($local))throw new RuntimeException('LANG_COUNT_FAILED:'.$lang);
 if((string)apply_filters('wpml_current_language','')!==$previous)
   throw new RuntimeException('WPML_CONTEXT_LEAK:'.$lang);
 do_action('wpml_switch_language',$lang);
 try {
  foreach($local as $term_id){
   // Native WP_Query is the same query visibility path used by JetEngine
   // Query 273; this assertion also exercises WPML fallback terms and
   // exclusion of extra_trip / nonpublished packages.
   $query=new WP_Query([
    'post_type'=>'tours-and-activities','post_status'=>'publish',
    'posts_per_page'=>-1,'fields'=>'ids','no_found_rows'=>true,
    'suppress_filters'=>false,'update_post_meta_cache'=>false,
    'update_post_term_cache'=>false,
    'tax_query'=>[[
      'taxonomy'=>'destinations','field'=>'term_id',
      'terms'=>[$term_id],'include_children'=>false
    ]]
   ]);
   if(count((array)$query->posts)!==($lc[$term_id]??-1))
     throw new RuntimeException('VISIBLE_TOUR_COUNT_MISMATCH:'.$lang.':'.$term_id);
  }
 } finally { do_action('wpml_switch_language',$previous?:null); }
 $ranked=$c::rank_ids($local,$lang);
 for($i=1;$i<count($ranked);$i++)
   if(($lc[$ranked[$i]]??0)>($lc[$ranked[$i-1]]??0))
     throw new RuntimeException('NOT_DESCENDING:'.$lang);
 $per_lang[$lang]=array_sum($lc);
}
echo 'PASS '.wp_json_encode(['site'=>home_url(),'contract'=>$c::CONTRACT,
  'visible_en_count'=>$counts,'nav_eligible'=>$eligible,
  'nav_rank'=>$ordered,'query_tests'=>count($queries),
  'wpml_language_totals'=>$per_lang],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n";
