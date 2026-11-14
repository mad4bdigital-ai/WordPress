<?php
/**
 * Read-only acceptance of native MAD4B Cache Control's translated FastCGI keys.
 * Discover WPML pairs from published Tour records, not fixed template/term IDs.
 */
defined('ABSPATH')||exit;
if(!defined('WP_CLI')||!WP_CLI||
   !class_exists('MAD4B_Cache_Control_V1',false)){
    WP_CLI::error('Native cache control owner unavailable');
}
global $wpdb;
$before=(string)apply_filters('wpml_current_language',null);
$default=(string)apply_filters('wpml_default_language','en');
if($before==='')WP_CLI::error('Cannot snapshot WPML language');

$sql=$wpdb->prepare(
    "SELECT d.element_id AS source_id, tr.element_id AS translated_id,
      tr.language_code AS translated_language
     FROM {$wpdb->prefix}icl_translations d
     INNER JOIN {$wpdb->prefix}icl_translations tr ON tr.trid=d.trid
     INNER JOIN {$wpdb->posts} source ON source.ID=d.element_id
     INNER JOIN {$wpdb->posts} translated ON translated.ID=tr.element_id
     WHERE d.element_type=%s AND tr.element_type=%s
       AND d.language_code=%s AND tr.language_code<>%s
       AND source.post_type=%s AND translated.post_type=%s
       AND source.post_status='publish' AND translated.post_status='publish'
       AND source.post_name<>translated.post_name
     ORDER BY d.element_id DESC, tr.language_code ASC LIMIT 12",
    'post_tours-and-activities','post_tours-and-activities',
    $default,$default,'tours-and-activities','tours-and-activities');
$pairs=$wpdb->get_results($sql,ARRAY_A);
if(!$pairs)WP_CLI::error('No published localized Tour with differing slugs to exercise');

$results=[];$checked=[];
foreach($pairs as $pair) {
    if(count($results)>=4)break;
    $from=(int)$pair['source_id'];
    $to=(int)$pair['translated_id'];
    $language=(string)$pair['translated_language'];
    $k=$from.'|'.$language;
    if(isset($checked[$k]))continue;
    $checked[$k]=true;
    $initial=(string)apply_filters('wpml_current_language',null);
    try {
        do_action('wpml_switch_language',$language);
        if((string)apply_filters('wpml_current_language',null)!==$language)
            WP_CLI::error('Independent locale switch did not take effect');
        $direct=get_permalink($to);
    } finally {
        do_action('wpml_switch_language',$initial);
    }
    $canonical=MAD4B_Cache_Control_V1::path_from_url($direct);
    if($canonical===null ||
       !str_contains($canonical,'/'.get_post_field('post_name',$to).'/'))
        WP_CLI::error('Cannot independently resolve translated canonical slug');
    $sourcePreview=MAD4B_Cache_Control_V1::preview_target('post:'.$from);
    $targetPreview=MAD4B_Cache_Control_V1::preview_target('post:'.$to);
    if(isset($sourcePreview['error'])||isset($targetPreview['error'])||
      !in_array($canonical,$sourcePreview['paths']??[],true)||
      !in_array($canonical,$targetPreview['paths']??[],true))
        WP_CLI::error('Cache Control omits canonical translated FastCGI path');
    // A translated-prefix path with the default-language slug is a WPML
    // redirect alias, not the real FastCGI cache key. Guard against the
    // exact historical leak in both source and translated preview calls.
    $root=rtrim($canonical,'/');
    $separator=strrpos($root,'/');
    $alias=substr($root,0,$separator+1).
        (string)get_post_field('post_name',$from).'/';
    if($alias!==$canonical&&(
       in_array($alias,$sourcePreview['paths']??[],true)||
       in_array($alias,$targetPreview['paths']??[],true)))
        WP_CLI::error('WPML redirect alias leaked into FastCGI targets');
    if((string)apply_filters('wpml_current_language',null)!==$initial)
        WP_CLI::error('Cache Control leaked localized WPML context');

    $results[]=['source'=>$from,'translation'=>$to,
        'language'=>$language,'canonical_path'=>$canonical,
        'paths_from_source'=>count($sourcePreview['paths']??[])];
}
if(!$results || (string)apply_filters('wpml_current_language',null)!==$before)
    WP_CLI::error('No valid translated paths or WPML context not restored');

WP_CLI::success(wp_json_encode([
    'site'=>home_url(),'pairs_verified'=>count($results),
    'default_language'=>$default,'wpml_context_restored'=>true,
    'source_and_translated_preview_agree'=>true,
    'fastcgi_purge_performed'=>false,'read_only'=>true,
    'examples'=>$results],JSON_UNESCAPED_SLASHES));
