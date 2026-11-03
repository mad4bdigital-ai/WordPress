<?php
/**
 * Taxonomy Archive Presentation v1: native source contract.
 * No prices, new WP objects, order writes, vendor modification, or URL aliases.
 */
defined('ABSPATH') || exit;
if (wp_get_environment_type() !== 'local' || !defined('SHARED_PRODUCT_LAB_ONLY') || !SHARED_PRODUCT_LAB_ONLY) return;
final class Shared_Product_Taxonomy_Archive_Presentation_v1 {
 public const VERSION='1.0.0';
 // Registry entries bind native WP taxonomies to their CPT/service owner.
 // Extend via source-backed authoring, not by assuming taxonomy == post_type.
 private const TAXONOMIES=[
   'transportation-types'=>['owner'=>'transportations','post_type'=>'transportations','query_id'=>246,
                             'templates'=>[40308,44322]],
   'transport-service-type'=>['owner'=>'transportations','post_type'=>'transportations','query_id'=>246,
                              'templates'=>[40308,44322]],
 ];
 public static function definitions():array {return self::TAXONOMIES;}
 public static function source(string $taxonomy):?array {
  $row=self::TAXONOMIES[$taxonomy]??null;
  $tax=$row?get_taxonomy($taxonomy):null;
  return $tax && $tax->public && in_array($row['post_type'],(array)$tax->object_type,true)
    ?$row+['taxonomy'=>$taxonomy]:null;
 }
 public static function current():?WP_Term {
  global $wp_the_query;
  if(!$wp_the_query instanceof WP_Query)return null;
  $t=$wp_the_query->get_queried_object();
  if(!$t instanceof WP_Term || !self::source($t->taxonomy) || !$wp_the_query->is_tax($t->taxonomy))return null;
  return $t;
 }
 public static function ancestor_terms(WP_Term $t):array {
  $ids=get_ancestors((int)$t->term_id,$t->taxonomy,'taxonomy');
  $result=[];foreach(array_reverse($ids) as $id){
   $a=get_term((int)$id,$t->taxonomy);
   if($a instanceof WP_Term)$result[]=$a;
  }
  return $result;
 }
 public static function term_in_scope(int $post_id,WP_Term $scope):bool {
  $terms=wp_get_object_terms($post_id,$scope->taxonomy,['fields'=>'ids']);
  if(is_wp_error($terms))return false;
  foreach((array)$terms as $id){
   if((int)$id===(int)$scope->term_id)return true;
   $anc=get_ancestors((int)$id,$scope->taxonomy,'taxonomy');
   if(in_array((int)$scope->term_id,array_map('intval',$anc),true))return true;
  }
  return false;
 }
 public static function verified_post(int $post_id,WP_Term $scope):bool {
  $row=self::source($scope->taxonomy);
  if(!$row || get_post_type($post_id)!==$row['post_type'] ||
     get_post_status($post_id)!=='publish')return false;
  $p=get_post($post_id);
  if(!$p || $p->post_password!=='')return false;
  $available=(string)get_post_meta($post_id,'trans_available_status',true);
  $public=(string)get_post_meta($post_id,'trans_is_puplic',true); // real legacy key
  if(!in_array($available,['1','true'],true)||!in_array($public,['1','true'],true))return false;
  // A published fixture is not an authorized visitor offer.
  if(metadata_exists('post',$post_id,'_booking_experience_qa_pricing_fixture'))return false;
  if(!function_exists('service_channel_allowed')||
     !service_channel_allowed($row['owner'],'destination_discovery'))return false;
  return self::term_in_scope($post_id,$scope);
 }
 public static function native_args(WP_Term $term,array $existing=[]):array {
  $row=self::source($term->taxonomy);
  if(!$row)return ['post_type'=>'post','post__in'=>[0],'no_found_rows'=>true];
  $allowed=function_exists('service_channel_allowed') &&
    service_channel_allowed($row['owner'],'destination_discovery');
  if(!$allowed)return ['post_type'=>$row['post_type'],'post__in'=>[0],'no_found_rows'=>true];
  // AND, not the previous cross-taxonomy OR. The selected taxonomy and all
  // descendant Term IDs are resolved by WP_Tax_Query, not caller GET values.
  $base=$existing;
  $base['post_type']=[$row['post_type']];
  $base['post_status']=['publish'];
  $base['tax_query']=[['taxonomy'=>$term->taxonomy,'field'=>'term_id',
    'terms'=>[(int)$term->term_id],'operator'=>'IN','include_children'=>true]];
  $base['meta_query']=['relation'=>'AND',
   ['key'=>'trans_available_status','value'=>['1','true'],'compare'=>'IN'],
   ['key'=>'trans_is_puplic','value'=>['1','true'],'compare'=>'IN'],
   ['key'=>'_booking_experience_qa_pricing_fixture','compare'=>'NOT EXISTS'],
  ];
  $base['has_password']=false;
  $base['ignore_sticky_posts']=true;
  $base['posts_per_page']=max(1,min(12,(int)($existing['posts_per_page']??12)));
  unset($base['post__in'],$base['post__not_in']);
  return $base;
 }
 public static function published_count(WP_Term $term):int {
  static $cache=[];
  $key=$term->taxonomy.':'.$term->term_id;
  if(isset($cache[$key]))return $cache[$key];
  $args=self::native_args($term,['posts_per_page'=>12,'no_found_rows'=>false,'fields'=>'ids']);
  if(isset($args['post__in'])&&$args['post__in']===[0])return $cache[$key]=0;
  $args['posts_per_page']=1;$args['no_found_rows']=false;
  $q=new WP_Query($args);
  return $cache[$key]=(int)$q->found_posts;
 }
 public static function resolve(?WP_Term $term=null):array {
  $term=$term??self::current();
  if(!$term||!($owner=self::source($term->taxonomy)))return ['ok'=>false,'code'=>'UNREGISTERED_ARCHIVE_SCOPE'];
  $discovery=function_exists('service_channel_allowed') &&
    service_channel_allowed($owner['owner'],'destination_discovery');
  $standalone=function_exists('service_channel_allowed') &&
    service_channel_allowed($owner['owner'],'standalone');
  $ancestors=self::ancestor_terms($term);
  $count=$discovery?self::published_count($term):0;
  return [
   'ok'=>true,'code'=>'UNIFIED_TAXONOMY_ARCHIVE','version'=>self::VERSION,
   'taxonomy'=>$term->taxonomy,'term_id'=>(int)$term->term_id,'name'=>$term->name,
   'parent'=>$ancestors?$ancestors[count($ancestors)-1]:null,
   'ancestors'=>$ancestors,'service'=>$owner['owner'],
   'post_type'=>$owner['post_type'],'query_id'=>$owner['query_id'],
   'discovery_allowed'=>$discovery,'standalone_allowed'=>$standalone,
   'verified_count'=>$count,'show_filters'=>$count>0,
   'sale_authorized'=>false,'price_authority'=>'pricing_registry',
  ];
 }
 public static function empty_message(array $ctx):string {
  if(empty($ctx['discovery_allowed']))
   return __('This service is not currently available for browsing.','default');
  return __('No publicly available transport options were found in this category.','default');
 }
}
