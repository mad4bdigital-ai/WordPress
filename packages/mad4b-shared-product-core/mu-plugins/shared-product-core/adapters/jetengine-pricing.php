<?php
/** Shared pricing adapter for JetEngine dynamic functions, lab only. */
if (!defined('ABSPATH')) return;
add_filter('jet-engine/dynamic-functions/functions-list',static function($functions){
 $functions['pricing_display']=['label'=>'Shared Pricing / Converted Price','cb'=>static function($args){
  $id=0;
  if(is_array($args))$id=absint($args['post_id']??$args['id']??0);
  return function_exists('pricing_post_currency_display') ? pricing_post_currency_display($id?:get_the_ID()) : '';
 }];
 return $functions;
});
