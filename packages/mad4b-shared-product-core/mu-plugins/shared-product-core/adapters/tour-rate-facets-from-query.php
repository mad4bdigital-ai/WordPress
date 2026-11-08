<?php
/**
 * Tour Rate facets: the same resolved JetEngine Query Builder listing drives
 * filter values. Not a separate independent terms query.
 */
if (!defined('ABSPATH') || !((defined('MAD4B_COMMERCE_RUNTIME_ENABLED') && MAD4B_COMMERCE_RUNTIME_ENABLED===true) || (defined('MAD4B_PRESENTATION_RUNTIME_ENABLED') && MAD4B_PRESENTATION_RUNTIME_ENABLED===true))) return;

/** True only for an explicit cruise-tour mode. */
if (!function_exists('shared_product_program_mode_supported')) {
 function shared_product_program_mode_supported($mode) {
  return is_scalar($mode) && (string)$mode === 'cruise_tour';
 }
}

/**
 * Keep the stored Rate term ID as the filter value; WPML translates only
 * the visitor-visible label, otherwise Query Builder meta equality breaks.
 */
function shared_product_rate_facet_term_label($term){
 if(!($term instanceof WP_Term))return '';
 $language=apply_filters('wpml_current_language',null);
 if($language && has_filter('wpml_object_id')){
  $translation=apply_filters('wpml_object_id',$term->term_id,$term->taxonomy,true,$language);
  if($translation){
   $localized=get_term(absint($translation),$term->taxonomy);
   if($localized instanceof WP_Term)return $localized->name;
  }
 }
 return $term->name;
}

/** Resolve immutable, published Tour authority; AJAX uses the shared signed resolver. */
function shared_product_rate_facet_tour_id() {
 if(function_exists('shared_product_verified_presentation_tour_id'))
  return shared_product_verified_presentation_tour_id();
 global $wp_the_query;
 if(!$wp_the_query instanceof WP_Query || !$wp_the_query->is_singular('tours-and-activities'))return 0;
 $tour=$wp_the_query->get_queried_object();
 return $tour instanceof WP_Post && $tour->post_status==='publish' ? (int)$tour->ID : 0;
}

/** Object-scoped facets use eligibility, never the currently selected facet values. */
final class Shared_Product_Rate_Facet_Context_v1 {
 private static ?WeakMap $queries=null;
 public static function source($listing,int $tour): ?object {
  if(!$listing || ($listing->query_id??'')!=='tour_rates'
    || ($listing->query_type??'')!=='posts' || !$tour
    || $tour!==shared_product_rate_facet_tour_id())return null;
  $copy=clone $listing;
  $copy->final_query=null;$copy->final_query_raw=null;
  self::$queries ??= new WeakMap();self::$queries[$copy]=$tour;
  return $copy;
 }
 public static function tour($query): int {
  return is_object($query) && self::$queries && isset(self::$queries[$query])
   ? (int)self::$queries[$query] : 0;
 }
}
function shared_product_is_rate_facet_query($query): bool {
 return Shared_Product_Rate_Facet_Context_v1::tour($query)>0;
}
add_filter('jet-engine/query-builder/filters/is-filters-request',static function($is_request,$query){
 return shared_product_is_rate_facet_query($query) ? false : $is_request;
},1000,2);
add_filter('jet-engine/query-builder/types/posts-query/args',static function($args,$query){
 $tour=Shared_Product_Rate_Facet_Context_v1::tour($query);
 if(!$tour || !is_array($args))return $args;
 $args['queried_object_id']=$tour;
 foreach(($args['meta_query']??[])as$key=>$row)
  if(is_array($row)&&($row['key']??'')==='related_tour_id')$args['meta_query'][$key]['value']=(string)$tour;
 return $args;
},24,2);

/** Do not publish independent facets if the underlying listing query is absent. */
function shared_product_tour_facets($tour_id) {
 static $cache=[];
 $tour_id=absint($tour_id);
 if (!$tour_id || $tour_id!==shared_product_rate_facet_tour_id() || get_post_type($tour_id)!=='tours-and-activities' || get_post_status($tour_id)!=='publish')
  return ['ids'=>[],'packages'=>[],'programs'=>[],'show_program'=>false,'query'=>'unavailable'];
 $mode=get_post_meta($tour_id,'tour_mode',true);
 $programSource=function_exists('shared_product_tour_itinerary_contract_v1')
   ?shared_product_tour_itinerary_contract_v1($tour_id):[];
 $show=!empty($programSource['customer_program_choice']);
 $key=$tour_id.'|'.(string)apply_filters('wpml_current_language',null);
 if(isset($cache[$key]))return $cache[$key];
 $empty=['ids'=>[],'packages'=>[],'programs'=>[],'show_program'=>$show,'query'=>'unavailable'];
 if(!class_exists('\Jet_Engine\Query_Builder\Manager'))return $cache[$key]=$empty;
 $manager=\Jet_Engine\Query_Builder\Manager::instance();
 $listing=$manager->get_query_by_id('tour_rates');
 if(!$listing || ($listing->query_type??'')!=='posts' || ($listing->query_id??'')!=='tour_rates')
  return $cache[$key]=$empty;
 // Resolve macros and the runtime eligibility post__in of the actual query.
 // Clone prevents this facet computation from mutating the live listing state.
 try{
  $query=Shared_Product_Rate_Facet_Context_v1::source($listing,$tour_id);
  if(!$query)return $cache[$key]=$empty;
  $args=$query->get_query_args();
 }catch(\Throwable $e){return $cache[$key]=$empty;}
 if(!is_array($args) || !in_array('tour-rates',(array)($args['post_type']??[]),true) ||
    empty($args['post__in']) || !is_array($args['post__in']))
  return $cache[$key]=$empty;
 $candidates=array_values(array_unique(array_filter(array_map('absint',$args['post__in']))));
 if(!$candidates)return $cache[$key]=$empty;
 // Fail closed if this query doesn't explicitly belong to the current tour;
 // request settings or a supplier list may never override its association.
 $args['post_type']='tour-rates';
 $args['post_status']='publish';
 $args['fields']='ids';
 $args['posts_per_page']=-1;
 $args['nopaging']=true;
 $args['post__in']=$candidates;
 $args['suppress_filters']=false;
 $ids=array_map('absint',get_posts($args));
 $ids=array_values(array_filter($ids,static function($rate_id)use($tour_id,$candidates){
   return in_array($rate_id,$candidates,true) &&
     get_post_status($rate_id)==='publish' &&
     (int)get_post_meta($rate_id,'related_tour_id',true)===$tour_id;
 }));
 $packages=[];$programs=[];
 foreach($ids as $rate_id){
  $package=absint(get_post_meta($rate_id,'related_package_term_id',true));
  if($package){
   $term=get_term($package,'package_levels');
   if($term instanceof WP_Term)$packages[$package]=shared_product_rate_facet_term_label($term);
  }
  if($show){
   $program=absint(get_post_meta($rate_id,'related_program_term_id',true));
   if($program && function_exists('shared_product_tour_program_allowed_v1')
      && shared_product_tour_program_allowed_v1($tour_id,$program)){
    $canonical=shared_product_program_canonical_term_v1($program);
    $term=get_term($canonical,'programs');
    if($term instanceof WP_Term)
       $programs[$canonical]=shared_product_rate_facet_term_label($term);
   }
  }
 }
 asort($packages,SORT_NATURAL|SORT_FLAG_CASE);
 asort($programs,SORT_NATURAL|SORT_FLAG_CASE);
 return $cache[$key]=['ids'=>$ids,'packages'=>$packages,'programs'=>$programs,
   'show_program'=>$show,'query'=>'tour_rates'];
}

/** Restore options only for matching filter metadata, not for unrelated selectors. */
add_filter('jet-smart-filters/filters/filter-options',static function($options,$filter_id){
 $name=(string)get_post_meta(absint($filter_id),'_query_var',true);
 if(!in_array($name,['related_program_term_id','related_package_term_id'],true))return $options;
 $tour=shared_product_rate_facet_tour_id();
 if(!$tour)return $options; // No guessed cross-request tour context.
 $facets=shared_product_tour_facets($tour);
 $placeholder=isset($options['']) && is_scalar($options['']) && trim((string)$options[''])!==''
  ? (string)$options['']
  : ($name==='related_program_term_id'?'Select Program':'Select Package');
 $options=[''=>$placeholder];
 if($name==='related_program_term_id' && !$facets['show_program'])return $options;
 $value=$name==='related_program_term_id'?$facets['programs']:$facets['packages'];
 foreach($value as $id=>$label)$options[(string)$id]=$label;
 return $options;
},200,2);

/** Program select must not exist for non-cruise tours in this listing. */
add_filter('elementor/frontend/widget/should_render',static function($should,$widget){
 if(!$should || !is_object($widget) || !method_exists($widget,'get_settings'))return $should;
 if($widget->get_name()!=='jet-smart-filters-select')return $should;
 $configured=$widget->get_settings('filter_id');
 $filter_id=absint(is_array($configured)?reset($configured):$configured);
 if(!$filter_id || get_post_meta($filter_id,'_query_var',true)!=='related_program_term_id')return $should;
 $provider=(string)$widget->get_settings('query_id');
 $allowed=[];
 if(function_exists('shared_presentation_registry_v2')){
   foreach(shared_presentation_registry_v2() as $definition){
     if(($definition['entity_type']??'')!=='tour-rates'
       ||($definition['parent_type']??'')!=='tours-and-activities'
       ||($definition['eligibility_source']??'')!=='shared_product_eligible_tour_rate_ids')continue;
     $ref=(string)($definition['provider']??'');
     if(str_starts_with($ref,'jet-engine/'))$allowed[]=substr($ref,strlen('jet-engine/'));
   }
 }
 if(!in_array($provider,$allowed,true))return $should;
 $tour=shared_product_rate_facet_tour_id();
 if(!$tour)return $should;
 $f=shared_product_tour_facets($tour);
 return $f['show_program'] && !empty($f['programs']);
},100,2);
