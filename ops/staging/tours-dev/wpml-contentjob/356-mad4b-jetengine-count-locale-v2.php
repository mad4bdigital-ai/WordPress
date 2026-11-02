<?php
/**
 * Plugin Name: MAD4B WPML Dynamic Count Locale Boundary
 * Description: Localize the exact JetEngine destination tour-count label with language-aware singular/plural, preserving dynamic queries and IDs.
 * Version: 0.2.0-staging
 */
defined('ABSPATH') || exit;
if(!function_exists('mad4b_wpml_tour_count_word_v2')){
 function mad4b_wpml_tour_count_word_v2($lang,$count){
  $count=(int)$count;
  switch($lang){
   case 'ar':
    if($count===1)return 'رحلة';
    if($count===2)return 'رحلتان';
    return ($count>=3 && $count<=10) ? 'رحلات' : 'رحلة';
   case 'fr': return $count===1 ? 'circuit':'circuits';
   case 'de': return $count===1 ? 'Tour':'Touren';
   case 'es': return $count===1 ? 'excursión':'excursiones';
   case 'it': return 'tour';
   default: return '';
  }
 }
}
if(!function_exists('mad4b_wpml_tour_count_render_v2')){
 function mad4b_wpml_tour_count_render_v2($html,$lang){
  if(!is_string($html)||!in_array($lang,['ar','fr','de','es','it'],true))return $html;
  // Exact Elementor widget 402c8ca / JetEngine query 273 only.
  $pattern='~(?P<span><span\b(?=[^>]*\bjet-engine-query-count\b)(?=[^>]*\bquery-273\b)[^>]*>\s*(?P<count>[0-9]+)\s*</span>)(?:\s|&nbsp;|&#160;)+(?:\+?Tours|رحلات|رحلة|رحلتان|circuits?|Touren|Tour|excursiones|excursión|tour)(?=\s*</div>)~u';
  return preg_replace_callback($pattern,static function($m)use($lang){
   return $m['span'].' '.mad4b_wpml_tour_count_word_v2($lang,(int)$m['count']);
  },$html)??$html;
 }
}
add_filter('elementor/widget/render_content',static function($html,$widget){
 if(!is_string($html)||!is_object($widget)||!method_exists($widget,'get_id'))return $html;
 if((string)$widget->get_id()!=='402c8ca'||!str_contains($html,'jet-engine-query-count'))return $html;
 if(wp_get_environment_type()!=='staging'
  ||wp_parse_url((string)get_option('siteurl'),PHP_URL_HOST)!=='tours.dev.mad4b.com'
  ||!defined('ICL_SITEPRESS_VERSION'))return $html;
 $lang=(string)apply_filters('wpml_current_language',null);
 return mad4b_wpml_tour_count_render_v2($html,$lang);
},110,2);
