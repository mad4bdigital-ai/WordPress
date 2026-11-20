<?php
/**
 * Canonical vehicle operating costs: native JetEngine taxonomy term meta.
 * Reads only; no term, profile, rate, checkout or WPML writes.
 */
defined('ABSPATH') || exit;
final class MAD4B_Transport_Vehicle_Term_Profiles_V1 {
 public const TAXONOMY='transport-vehicle-types';
 public const KEY_TO_SLUG=[
  'limousine'=>'limousine','luxury_7_seats'=>'luxury-7-seats',
  'h1'=>'h1','hiace'=>'hiace','coaster'=>'coaster','bus'=>'bus'
 ];
 public const META=[
  'fixed'=>'mad4b_vehicle_cost_base_egp',
  'per_km'=>'mad4b_vehicle_cost_per_km_egp',
  'per_drive_min'=>'mad4b_vehicle_cost_drive_min_egp',
  'per_wait_min'=>'mad4b_vehicle_cost_wait_min_egp',
  'overnight'=>'mad4b_vehicle_cost_overnight_egp',
  'min_fare'=>'mad4b_vehicle_cost_min_fare_egp',
  'deadhead_pct'=>'mad4b_vehicle_cost_deadhead_pct'
 ];
 public const LIMIT=[
  'fixed'=>200000,'per_km'=>10000,'per_drive_min'=>1000,
  'per_wait_min'=>1000,'overnight'=>100000,'min_fare'=>200000,
  'deadhead_pct'=>200
 ];
 public static function active():bool {
  // WordPress stores boolean true as the string '1' for non-autoloaded
  // options. Accept both types; never treat arbitrary truthy text as approval.
  return in_array(get_option('mad4b_transport_vehicle_term_canonical_v1',false),
   [true,'1'],true);
 }
 public static function profile(string $key){
  if(!isset(self::KEY_TO_SLUG[$key]))return new WP_Error('vehicle_term_unknown_key');
  if(!taxonomy_exists(self::TAXONOMY))
   return new WP_Error('vehicle_term_taxonomy_missing');
  $term=get_term_by('slug',self::KEY_TO_SLUG[$key],self::TAXONOMY);
  if(!$term instanceof WP_Term)return new WP_Error('vehicle_term_missing_'.$key);
  $profile=[];
  foreach(self::META as $field=>$meta){
   $raw=get_term_meta($term->term_id,$meta,true);
   if(!is_scalar($raw)||is_bool($raw)||$raw===''||
      !is_numeric($raw)||!is_finite((float)$raw)||
      (float)$raw<0||(float)$raw>self::LIMIT[$field])
    return new WP_Error('vehicle_term_cost_invalid_'.$field);
   $profile[$field]=round((float)$raw,4);
  }
  $basis=(string)get_term_meta($term->term_id,'mad4b_vehicle_cost_basis',true);
  if(!in_array($basis,['manual_draft','pdf_proposal'],true))
   return new WP_Error('vehicle_term_cost_basis_invalid');
  $sample=get_term_meta($term->term_id,'mad4b_vehicle_cost_samples',true);
  if(!is_numeric($sample)||(int)$sample<0||(int)$sample>51)
   return new WP_Error('vehicle_term_samples_invalid');
  $profile['source']=$basis;
  $profile['samples']=(int)$sample;
  $mode=(string)get_term_meta($term->term_id,'mad4b_vehicle_dispatch_mode',true);
  if(!in_array($mode,['inherit','enabled','disabled'],true))
   return new WP_Error('vehicle_term_dispatch_invalid');
  return ['term_id'=>$term->term_id,'slug'=>$term->slug,
   'vehicle_key'=>$key,'profile'=>$profile,
   'dispatch_mode'=>$mode,
   'currency'=>'EGP','owner'=>'jetengine_taxonomy_term_meta',
   'edit_url'=>get_edit_term_link($term->term_id,self::TAXONOMY,'')?:null];
 }
 public static function profiles(){
  if(!self::active())return null; // legacy company option stays canonical pre-migration.
  $map=[];
  foreach(array_keys(self::KEY_TO_SLUG) as $key){
   $term=self::profile($key);
   if(is_wp_error($term))return $term; // fail closed if any required cost term absent.
   $map[$key]=$term;
  }
  return $map;
 }
}
/**
 * Term costs are finance-sensitive: editor manage_categories must not confer
 * edit, creation, deletion or assignment of cost-bearing vehicle terms.
 * The filter operates on only our exact taxonomy while JetEngine owns its
 * native taxonomy registration, fields, and save workflow.
 */
add_filter('register_taxonomy_args',static function($args,$taxonomy){
 if($taxonomy!==MAD4B_Transport_Vehicle_Term_Profiles_V1::TAXONOMY)
  return $args;
 $args['capabilities']=[
  'manage_terms'=>'manage_options','edit_terms'=>'manage_options',
  'delete_terms'=>'manage_options','assign_terms'=>'manage_options'
 ];
 return $args;
},20,2);
/* Progressive presentation only; native JetEngine term form owns saving. */
add_action('admin_enqueue_scripts',static function($hook){
 if(!in_array($hook,['edit-tags.php','term.php'],true))return;
 $screen=function_exists('get_current_screen')?get_current_screen():null;
 if(!$screen||$screen->taxonomy!=='transport-vehicle-types')return;
 if(!current_user_can('manage_options'))return;
 $root=dirname(__DIR__);
 $main=$root.'/mad4b-pricing-registry.php';
 foreach(['css'=>'style','js'=>'script'] as $ext=>$type){
  $file='assets/transport-vehicle-term-editor.'.$ext;
  if(!is_file($root.'/'.$file))continue;
  $uri=plugins_url($file,$main);
  $rev=(string)filemtime($root.'/'.$file);
  if($type==='style')
   wp_enqueue_style('mad4b-vehicle-term-editor',$uri,[],$rev);
  else
   wp_enqueue_script('mad4b-vehicle-term-editor',$uri,[],$rev,true);
 }
},110);
