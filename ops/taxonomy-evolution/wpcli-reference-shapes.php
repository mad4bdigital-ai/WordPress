<?php
/** Metadata shape probe for governed taxonomy migrations. No values or content exported. */
if (!defined('WP_CLI') || !WP_CLI || !defined('ABSPATH')) throw new RuntimeException('wp_cli_required');
$old=getenv('MAD4B_EVOLVE_OLD');$expected=getenv('MAD4B_EVOLVE_EXACT_HOME');
if(!is_string($old)||!preg_match('/^[a-z][a-z0-9_]{2,60}$/D',$old)||$expected!==home_url()||wp_get_environment_type()!=='staging')throw new RuntimeException('exact_scope_required');
global $wpdb;
$keys=['_source_taxonomy','_menu_item_object','_listing_data','_elementor_data','_ih_source_map','_custom_query_var','_default_filter_value','_elementor_conditions'];
$out=['contract'=>'mad4b.taxonomy-evolution-reference-shapes.v1','site'=>home_url(),'legacy'=>$old,'shapes'=>[],'read_only'=>true,'identifiers_or_content_exported'=>false];
$detect=function($value,$prefix,$depth,&$paths,&$nodes)use(&$detect,$old){
 if($depth>12||$nodes++>3500)return;
 if(is_string($value)){
    if($value===$old)$paths[$prefix.' : exact']=true;
    elseif(strpos($value,$old)!==false)$paths[$prefix.' : substring']=true;
    return;
 }
 if(!is_array($value)&&!is_object($value))return;
 foreach((array)$value as $key=>$child){
   $key=is_int($key)?'#':preg_replace('/[^a-zA-Z0-9_-]/','_',substr((string)$key,0,50));
   $detect($child,$prefix.'/'.$key,$depth+1,$paths,$nodes);
 }
};
foreach($keys as $key){
 $sql=$wpdb->prepare("SELECT pm.meta_value FROM {$wpdb->postmeta} pm WHERE pm.meta_key=%s AND pm.meta_value LIKE %s ORDER BY pm.meta_id LIMIT 4",$key,'%'.$wpdb->esc_like($old).'%');
 $values=$wpdb->get_col($sql);
 if(!is_array($values)||!$values)continue;
 $sample=['samples'=>count($values),'formats'=>[],'paths'=>[]];
 foreach($values as $v){
   $parsed=$v;$format='scalar';
   if(is_serialized($v)){$parsed=maybe_unserialize($v);$format='serialized';}
   elseif(strlen($v)<1500000 && (str_starts_with(ltrim($v),'[')||str_starts_with(ltrim($v),'{'))){
    $j=json_decode($v,true);if(json_last_error()===JSON_ERROR_NONE){$parsed=$j;$format='json';}
   }
   $sample['formats'][$format]=($sample['formats'][$format]??0)+1;
   $paths=[];$nodes=0;$detect($parsed,'root',0,$paths,$nodes);
   foreach(array_keys($paths) as $path)$sample['paths'][$path]=true;
 }
 $sample['paths']=array_slice(array_keys($sample['paths']),0,45);
 $out['shapes'][$key]=$sample;
}
echo wp_json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),PHP_EOL;
