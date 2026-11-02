<?php
/**
 * Plugin Name: MAD4B Dynamic Count Locale Display Guard
 * Description: Locale-correct JetEngine count suffix on shared dynamic destination listings, without rewriting queried content.
 * Version: 0.1.0-staging
 */
defined('ABSPATH')||exit;
add_filter('elementor/widget/render_content',static function($html,$widget){
 if(!is_string($html)||!is_object($widget)||!method_exists($widget,'get_id'))return $html;
 if($widget->get_id()!=='402c8ca')return $html;
 if(isset($_GET['mad4b_wpml_probe'])&&str_starts_with((string)$_GET['mad4b_wpml_probe'],'debug-'))
  error_log('[MAD4B-WPML-DIAG] widget=402c8ca wpml_lang='.apply_filters('wpml_current_language',null).' query_lang='.get_query_var('lang').' bytes='.strlen($html).' tours='.(str_contains($html,'Tours')?'yes':'no').' span='.(str_contains($html,'jet-engine-query-count')?'yes':'no'));
 if(wp_get_environment_type()!=='staging'
  ||rtrim(home_url(),'/')!=='https://tours.dev.mad4b.com'
  ||!defined('ICL_SITEPRESS_VERSION'))return $html;
 $lang=(string)apply_filters('wpml_current_language',null);
 $suffix=['ar'=>'رحلات','fr'=>'circuits','de'=>'Touren','es'=>'excursiones','it'=>'tour'][$lang]??null;
 if($suffix===null)return $html;
 if(isset($_GET['mad4b_wpml_probe']) && str_starts_with((string)$_GET['mad4b_wpml_probe'],'debug-')) {
   $re = '/(<span\\b[^>]*jet-engine-query-count[^>]*>\\s*\\p{N}+\\s*<\\/span>)\\s+Tours(?=\\s|<|$)/u';
   error_log('[MAD4B-WPML-DIAG-PRE] match=' . preg_match($re,$html) .
     ' snippet='.str_replace(["\n","\r"],' ',substr($html,-300)));
 }
 // Only locale-specific numeric tour-count text from the exact JetEngine widget.
 // Never modify dynamic destination titles, taxonomy terms, links or Elementor CSS.
 $html=preg_replace_callback('/(<span\b[^>]*jet-engine-query-count[^>]*>\s*\p{N}+\s*<\/span>)\s+Tours(?=\s|<|$)/u',
   static fn($m)=>$m[1].' '.$suffix,$html);
 return preg_replace_callback('/(\p{N}+)(?:\s|\x{00A0}|&nbsp;|\+)+Tours(?=\s|<|$)/u',
   static fn($m)=>$m[1].' '.$suffix,$html);
},95,2);
