<?php
/**
 * Read-only contract: one WPML Tour language, same-language Term universe,
 * scoped geographic context, and itinerary evidence (not guessed visits).
 */
defined('ABSPATH')||exit;
global $wpdb;
if(!class_exists('MAD4B_Tour_Highlights_V1',false)
 || !class_exists('MAD4B_Tour_Highlights_Presentation_V1',false))
 WP_CLI::error('Original Highlights renderer unavailable');
$method=new ReflectionMethod('MAD4B_Tour_Highlights_Presentation_V1','related_links');
$contract=['site'=>site_url(),'read_only'=>true,'locale_cases'=>[],'semantic_cases'=>[]];
foreach([57562,57563,57564,56353,60437,54877] as $tour){
 if(get_post_type($tour)!=='tours-and-activities')continue;
 $lang=apply_filters('wpml_element_language_code',null,['element_id'=>$tour,'element_type'=>'post_tours-and-activities']);
 if(!is_string($lang)||$lang==='')WP_CLI::error('Tour WPML language missing '.$tour);
 do_action('wpml_switch_language',$lang);
 if((string)apply_filters('wpml_current_language',null)!==$lang)WP_CLI::error('WPML switch failed '.$tour);
 $places=MAD4B_Tour_Highlights_V1::related_places($tour);
 $ids=[];
 foreach($places as $item){
  $id=(int)$item['id'];
  $ids[]=$id;
  $ttid=$wpdb->get_var($wpdb->prepare("SELECT term_taxonomy_id FROM {$wpdb->term_taxonomy} WHERE taxonomy='destinations' AND term_id=%d LIMIT 1",$id));
  $termLang=$wpdb->get_var($wpdb->prepare("SELECT language_code FROM {$wpdb->prefix}icl_translations WHERE element_type='tax_destinations' AND element_id=%d LIMIT 1",(int)$ttid));
  if($termLang!==$lang)WP_CLI::error('Leaked cross-language Term '.$tour.' '.$id.' '.$termLang.' '.$lang);
  if(($item['evidence']??'assigned')==='exact-highlight-mention' &&
     ($item['kind']??'')!=='state_governorate')
     WP_CLI::error('Inferred non-governorate term cannot enter the geographic registry');
 }
 if(count($ids)!==count(array_unique($ids)))WP_CLI::error('Duplicated term '.$tour);
 $contract['locale_cases'][]=['tour'=>$tour,'lang'=>$lang,'terms'=>count($places),
  'inferred'=>count(array_filter($places,static fn($p)=>($p['evidence']??'')==='exact-highlight-mention'))];
}
// The wrong active language must NEVER cause the Spanish tour to inherit
// the English Destination IDs or names from another member of its WPML group.
if(get_post_type(57563)==='tours-and-activities'){
 do_action('wpml_switch_language','en');
 $bad=MAD4B_Tour_Highlights_V1::related_places(57563);
 if($bad)WP_CLI::error('Wrong WPML runtime language returned '.$bad[0]['label']);
 do_action('wpml_switch_language','es');
 $good=MAD4B_Tour_Highlights_V1::related_places(57563);
 if(count($good)<20)WP_CLI::error('Correct ES locale was not restored');
 $contract['wrong_locale_test']=['bad_context_count'=>count($bad),'correct_context_count'=>count($good)];
}
foreach([['en',55160,1],['en',55160,5],['en',60437,1],['en',54877,1],['es',56353,1]] as [$lang,$tour,$step]){
 if(get_post_type($tour)!=='tours-and-activities')continue;
 do_action('wpml_switch_language',$lang);
 $cards=MAD4B_Tour_Highlights_Presentation_V1::cards((string)get_post_meta($tour,'tour_highlights_content',true));
 if(!isset($cards[$step-1]))continue;
 $places=MAD4B_Tour_Highlights_V1::related_places($tour);
 $args=[$cards[$step-1],$places];
 if($method->getNumberOfParameters()>=4)$args=array_merge($args,[$step,$tour]);
 $markup=$method->invokeArgs(null,$args);
 preg_match_all('/<a[^>]*class="tour-hl-ui__place"[^>]*>/',$markup,$tags);
 $links=[];
 foreach($tags[0] as $tag){
  preg_match('/data-tour-term-id="(\d+)"/',$tag,$idmatch);
  preg_match('/data-tour-evidence="([^"]+)"/',$tag,$origin);
  preg_match('/data-tour-match="([^"]+)"/',$tag,$evidence);
  $links[]=['id'=>(int)($idmatch[1]??0),'type'=>$origin[1]??'',
    'match'=>$evidence[1]??''];
 }
 if(in_array($tour,[55160,60437],true)&&($step===5||$tour===60437) && $links)
   WP_CLI::error('Generic geographic/service Highlight was linked like an included visit');
 if($tour===55160 && $step===1){
   foreach([2489,2490] as $required)
     if(!in_array($required,array_column($links,'id'),true))
       WP_CLI::error('Actual landmark lost from Cairo highlight '.$required);
   if(!in_array('geographic-context',array_column($links,'type'),true))
     WP_CLI::error('Geographic qualifier missing from specific landmark Highlight');
 }
 if($tour===54877 && $step===1){
   foreach([2517,2518] as $required)
     if(!in_array($required,array_column($links,'id'),true))
       WP_CLI::error('Pyramids/Sphinx link lost '.$required);
   $temple=array_values(array_filter($links,static fn($x)=>$x['id']===2522));
   if($temple && $temple[0]['type']!=='itinerary')WP_CLI::error('Valley Temple inferred as generic Highlight mention');
 }
 $contract['semantic_cases'][]=['tour'=>$tour,'lang'=>$lang,'step'=>$step,'links'=>$links];
}
WP_CLI::success(wp_json_encode($contract,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
