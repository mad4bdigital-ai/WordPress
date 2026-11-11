<?php
if(!defined('WP_CLI') || !WP_CLI)throw new RuntimeException('cli_only');
$site=parse_url(home_url('/'),PHP_URL_HOST);
if(!in_array($site,['tours.dev.mad4b.com','wayfatours.com'],true))throw new RuntimeException('unauthorized_site');
$locales=$site==='wayfatours.com'?['en','es']:['en','ar','fr','de','es','it'];
$enabled=get_option('mad4b_shared_taxonomy_routing_v1',[]);
$should=['property-types','tour-types','tour-styles'];
$issues=[];$links=[];
if(!function_exists('mad4b_shared_taxonomy_routing_policy_v1')||!function_exists('mad4b_shared_taxonomy_wpml_term_ids_v1'))
 $issues[]='missing_shared_routing_runtime';
if(!is_array($enabled)||($enabled['managed']??[])!==$should||empty($enabled['enabled']))$issues[]='policy_mismatch';
$source=['property-types'=>2150,'tour-types'=>625,'tour-styles'=>288];
$original=apply_filters('wpml_current_language',null);
foreach($locales as $locale){
 do_action('wpml_switch_language',$locale);
 foreach($source as $taxonomy=>$id){
  $t=get_taxonomy($taxonomy);
  if(!$t || !is_array($t->rewrite) || ($t->rewrite['slug']??'')!==$taxonomy)$issues[]="rewrite_".$taxonomy;
  $translated=(int)apply_filters('wpml_object_id',$id,$taxonomy,false,$locale);
  $term=get_term($translated,$taxonomy);
  if(!$term instanceof WP_Term){$issues[]="translation_".$taxonomy.'_'.$locale;continue;}
  $url=get_term_link($term);
  if(is_wp_error($url)||!is_string($url)||!str_contains($url,'/'.$taxonomy.'/')||
     str_contains($url,'?'.$taxonomy.'='))$issues[]="term_link_".$taxonomy.'_'.$locale;
  $links[]=['language'=>$locale,'taxonomy'=>$taxonomy,'term_id'=>$translated,'url'=>$url];
 }
}
do_action('wpml_switch_language',$original);
foreach(['programs','unit-type'] as $name)if(($t=get_taxonomy($name)) && $t->rewrite!==false)
 $issues[]='internal_rewrite_enabled_'.$name;
$dest=get_taxonomy('destinations');
if(!$dest || !is_array($dest->rewrite)||($dest->rewrite['slug']??'')!=='destination')
 $issues[]='destination_rewrite_regressed';
$rules=get_option('rewrite_rules',[]);
foreach($should as $name){
 $hasTerm=false;$hasJSF=false;
 foreach($rules as $r=>$q){
  if(str_starts_with($r,$name.'/')&&str_ends_with($r,'/?$'))$hasTerm=true;
  if(str_starts_with($r,$name.'/')&&str_contains($r,'/jsf/'))$hasJSF=true;
 }
 if(!$hasTerm)$issues[]='missing_native_rewrite_'.$name;
 if(!$hasJSF)$issues[]='missing_jsf_rewrite_'.$name;
}
$services=get_option('service-activation',[]);
$expected=$site==='wayfatours.com'?'tour_only':'standalone_and_tour';
if(($services['properties_channel_mode']??null)!==$expected)$issues[]='service_policy_changed';
$sitemap=get_option('rank-math-options-sitemap',[]);
$seo=[];
foreach($should as $name)$seo[$name]=$sitemap['tax_'.$name.'_sitemap']??null;
echo wp_json_encode(['contract'=>'mad4b.shared-taxonomy-routing.acceptance.v1',
 'site'=>home_url('/'),'checked_utc'=>gmdate('c'),'result'=>$issues?'FAIL':'PASS',
 'test_cases'=>count($links)+count($should)*2+4,'supported_languages'=>$locales,
 'managed_taxonomies'=>$should,'term_links'=>$links,
 'service_policy'=>$expected,'seo_sitemap_settings'=>$seo,
 'issues'=>$issues],JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
if($issues)exit(2);
