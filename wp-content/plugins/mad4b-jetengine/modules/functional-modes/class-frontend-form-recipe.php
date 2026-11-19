<?php
defined('ABSPATH') || exit;
/** Presentation-only Recipe bridge for native legacy JetEngine forms. */
final class MAD4B_JetEngine_Frontend_Form_Recipe_V1 {
 public static function boot():void {
  add_action('wp_enqueue_scripts',[__CLASS__,'enqueue'],30);
 }
 public static function enqueue():void {
  if(is_admin() || !class_exists('Jet_Engine'))return;
  $dir=__DIR__.'/assets/';
  $url=plugins_url('mad4b-jetengine/modules/functional-modes/assets/');
  MAD4B_JetEngine_Recipe_Registry_V1::enqueue('form');
  wp_enqueue_style('mad4b-jetengine-frontend-form-recipe',
    $url.'jetengine-field-recipe.css',[],
    (string)filemtime($dir.'jetengine-field-recipe.css'));
  wp_enqueue_script('mad4b-jetengine-frontend-form-recipe',
    $url.'frontend-form-recipe.js',[],
    (string)filemtime($dir.'frontend-form-recipe.js'),true);
 }
}
MAD4B_JetEngine_Frontend_Form_Recipe_V1::boot();
