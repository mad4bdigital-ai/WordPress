<?php
/**
 * Plugin Name: MAD4B City Listing Viewport Geometry and Unified Home Introduction
 * Description: Prioritize actually visible City Listing backgrounds; synchronize native source card geometry and unified SEO intro appearance.
 * Source module: live WordPress implementation, generalized by site profile.
 */
defined('ABSPATH') || exit;
final class MAD4B_City_Geometry_Viewport_V1 {
 public static function site():bool { return MAD4B_Presentation_Experience::enabled('city-visible-image'); }
 public static function assets():void {
  if(!self::site()||is_admin()||is_feed()||wp_doing_ajax())return;
  if(!is_front_page()&&!is_tax('destinations'))return;
  $dir=MAD4B_Presentation_Experience::asset_path('');
  $css=$dir.'mad4b-city-geometry-and-intro.css';
  $js=$dir.'mad4b-city-visible-image.js';
  if(is_file($css))
   wp_enqueue_style('mad4b-city-geometry-parity',MAD4B_Presentation_Experience::asset_url('mad4b-city-geometry-and-intro.css'),[],(string)filemtime($css));
  if(is_file($js))
   wp_enqueue_script('mad4b-city-visible-image',MAD4B_Presentation_Experience::asset_url('mad4b-city-visible-image.js'),[],(string)filemtime($js),true);
 }
 public static function ready():void {
  if(!self::site()||is_admin()||is_feed()||wp_doing_ajax()||(!is_front_page()&&!is_tax('destinations')))return;
  echo '<script id="mad4b-city-lazy-gate">document.documentElement.classList.add("mad4b-city-geometry-lazy");</script>'."\n";
 }
}
add_action('wp_enqueue_scripts',['MAD4B_City_Geometry_Viewport_V1','assets'],115);
add_action('wp_head',['MAD4B_City_Geometry_Viewport_V1','ready'],1);

