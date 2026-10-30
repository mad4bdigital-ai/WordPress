<?php
/** ETG Local: governed JetEngine nested repeaters and CPT Woo season-tour grid sources. */
if(!defined('ABSPATH'))return;
/** One source of editorial message items: canonical season meta; campaign meta only when core says eligible. */
function seasonal_nested_messages($selection=null,$language=null){
 $option=get_option('global-offers',[]);if(empty($option['seasonal_templates_enabled']))return [];
 if(!function_exists('shared_offer_seasonal_selection')||!function_exists('shared_offer_current_visible_language'))return [];
 $selection=is_array($selection)?$selection:shared_offer_seasonal_selection();
 $language=$language?:shared_offer_current_visible_language();
 if(!$selection||!$language||!class_exists('MAD4B_SCP_Market_Profiles')||!isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$language]))return [];
 $term_id=absint($selection['season_term_id']??0);
 if(!$term_id||!get_term($term_id,'season')||!in_array((string)get_term_meta($term_id,'season_enabled',true),['1','true','on'],true))return [];
 if(!in_array((string)get_term_meta($term_id,'season_topbar_enabled',true),['1','true','on'],true))return [];
 $rows=get_term_meta($term_id,'season_topbar_messages',true);
 if(!is_array($rows))return [];
 $state=sanitize_key($selection['state']??'');if(!in_array($state,['active','upcoming'],true))return [];
 $out=[];
 foreach(array_slice($rows,0,35) as $index=>$item){
  if(!is_array($item)||empty($item['enabled'])||($item['review_status']??'')!=='approved')continue;
  if(!in_array($item['state']??'',['both',$state],true))continue;
  if(($item['language']??'')!==$language)continue; // exact translated copy, never an implicit WPML fallback
  if(($item['source']??'season_meta')!=='season_meta')continue; // external source requires independent governed review receipt
  if(!empty($item['market_profile_id'])){
   if(!function_exists('shared_woo_market_rule_match')||!shared_woo_market_rule_match(['enabled'=>true,'scope'=>'domestic','market_profile_id'=>$item['market_profile_id']]))continue;
  }
  $title=sanitize_text_field((string)($item['title']??''));if(!$title)continue;
  $description=sanitize_text_field((string)($item['description']??''));
  $cta_label=sanitize_text_field((string)($item['cta_label']??''));$cta_url=trim((string)($item['cta_url']??''));
  $cta='';if($cta_label&&$cta_url){
   $candidate=esc_url_raw($cta_url,['https','http']);$target=wp_parse_url($candidate);
   $site=wp_parse_url(home_url('/'));
   if($target&&in_array(strtolower((string)($target['scheme']??'')),['https','http'],true)
    &&(strtolower((string)($target['scheme']??''))==='https'||strtolower((string)($target['host']??''))===strtolower((string)($site['host']??''))))$cta=$candidate;
  }
  $safe_cta=$cta?'<a class="etg-season-message-cta" href="'.esc_url($cta).'">'.esc_html($cta_label).'</a>':'';
  $out[]=['title'=>esc_html($title),'description'=>esc_html($description),
   'cta_html'=>$safe_cta,'cta_label'=>esc_html($cta?' — '.$cta_label:''),'cta_url'=>esc_url($cta),
   'priority'=>(int)($item['priority']??0),'sort_index'=>$index];
 }
 usort($out,static fn($a,$b)=>$b['priority']<=>$a['priority']?:$a['sort_index']<=>$b['sort_index']);
 return array_slice($out,0,4);
}
/** JetEngine native Dynamic Repeater reads the already-filtered season items. */
add_filter('jet-engine/listings/dynamic-repeater/pre-get-saved',static function($saved,$settings){
 if(($settings['etg_nested_messages_source']??'')!=='season_meta_reviewed')return $saved;
 $rows=seasonal_nested_messages();
 // Nonempty sentinel avoids falling back to the unrelated current post's meta.
 return $rows?:[['title'=>'','description'=>'','cta_label'=>'']];
},40,2);
/** Source is JetEngine Listing Grid with a bounded verified set of CPT WooCommerce tour IDs. */
add_filter('jet-engine/listing/grid/posts-query-args',static function($args,$renderer,$settings){
 if(($settings['etg_nested_tour_source']??'')!=='cpt_woo_season')return $args;
 $stop=['post_type'=>'tours-and-activities','post_status'=>'publish','post__in'=>[0],'posts_per_page'=>0,'no_found_rows'=>true];
 $opt=get_option('global-offers',[]);if(empty($opt['seasonal_templates_enabled']))return $stop;
 $selection=function_exists('shared_offer_seasonal_selection')?shared_offer_seasonal_selection():null;
 if(!$selection||!in_array($selection['state']??'',['active','upcoming'],true))return $stop;
 if(!function_exists('seasonal_tour_candidates'))return $stop;
 $term_id=absint($selection['season_term_id']??0);
 if(!$term_id||!in_array((string)get_term_meta($term_id,'season_topbar_enabled',true),['1','true','on'],true))return $stop;
 $candidates=seasonal_tour_candidates($term_id,3);
 $ids=array_values(array_unique(array_map('absint',array_column($candidates,'id'))));
 if(!$ids)return $stop;
 return ['post_type'=>'tours-and-activities','post_status'=>'publish','post__in'=>$ids,'posts_per_page'=>min(3,count($ids)),
  'orderby'=>'post__in','ignore_sticky_posts'=>true,'no_found_rows'=>true,'suppress_filters'=>false];
},40,3);
