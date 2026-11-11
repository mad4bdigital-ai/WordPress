<?php
/**
 * Plugin Name: MAD4B WPML Dynamic Count Locale Boundary
 * Description: Localize exact JetEngine query-count UI suffix at render time, preserving dynamic number and destination metadata.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
add_filter('elementor/widget/render_content',static function($html,$widget){
 if(!is_string($html)||!is_object($widget)||!method_exists($widget,'get_id'))return $html;
 if((string)$widget->get_id()!==(string)MAD4B_Presentation_Experience::setting('jetengine_count_widget_id','402c8ca')||!str_contains($html,'jet-engine-query-count'))return $html;
 if(!MAD4B_Presentation_Experience::enabled('jetengine-count-locale'))return $html;
 $lang=(string)apply_filters('wpml_current_language',null);
 $suffix=['ar'=>'رحلات','fr'=>'circuits','de'=>'Touren','es'=>'excursiones','it'=>'tour'][$lang]??null;
 if($suffix===null)return $html;
 return preg_replace('/(<\/span>)\s+(?:Tours|\+Tours)(?=<|$)/u','$1 '.$suffix,$html);
},110,2);
