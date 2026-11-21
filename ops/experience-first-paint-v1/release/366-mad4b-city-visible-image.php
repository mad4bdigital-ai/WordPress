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
  $jsName='mad4b-city-visible-image.js';
  $js=$dir.$jsName;
  // Content-addressed read-only release. The previous verified script remains
  // the safe fallback if the candidate asset is missing, modified or unreadable.
  // The filename changes on every new release to bypass cached HTML asset URLs
  // without globally purging or rewriting third-party LiteSpeed bundles.
  $candidateName='mad4b-city-visible-image.339807974258.js';
  $candidate=$dir.$candidateName;
  if(is_file($candidate) && !is_link($candidate) && filesize($candidate)<16384
      && hash_file('sha256',$candidate)==='339807974258b258c6bdfce6ae36d43d1b3526db46a63544628782eb86850de3'){
   $js=$candidate;
   $jsName=$candidateName;
  }
  if(is_file($css))
   wp_enqueue_style('mad4b-city-geometry-parity',MAD4B_Presentation_Experience::asset_url('mad4b-city-geometry-and-intro.css'),[],(string)filemtime($css));
  if(is_file($js))
   wp_enqueue_script('mad4b-city-visible-image',MAD4B_Presentation_Experience::asset_url($jsName),[],(string)filemtime($js),true);
 }
 public static function script_tag(string $tag,string $handle,string $src):string {
  if($handle!=='mad4b-city-visible-image')return $tag;
  // The image gate must never wait for LiteSpeed JS delay or aggregation.
  if(str_contains($tag,'data-no-optimize'))return $tag;
  return preg_replace('/<script\b/','<script data-no-optimize="1" data-no-defer="1" data-cfasync="false"',$tag,1)?:$tag;
 }
 public static function ready():void {
  if(!self::site()||is_admin()||is_feed()||wp_doing_ajax()||(!is_front_page()&&!is_tax('destinations')))return;
  echo '<script id="mad4b-city-lazy-gate" data-no-optimize="1" data-no-defer="1">(function(){var root=document.documentElement;root.classList.add("mad4b-city-geometry-lazy");function failOpen(){if(!window.mad4bCityVisibleReady)root.classList.remove("mad4b-city-geometry-lazy");}if(document.readyState==="loading")document.addEventListener("DOMContentLoaded",function(){setTimeout(failOpen,4800);},{once:true});else setTimeout(failOpen,4800);})();</script>'."\n";
 }
}
add_filter('script_loader_tag',['MAD4B_City_Geometry_Viewport_V1','script_tag'],1,3);
add_action('wp_enqueue_scripts',['MAD4B_City_Geometry_Viewport_V1','assets'],115);
add_action('wp_head',['MAD4B_City_Geometry_Viewport_V1','ready'],1);

