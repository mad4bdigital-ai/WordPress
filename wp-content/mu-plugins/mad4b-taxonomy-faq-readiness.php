<?php
/**
 * Plugin Name: MAD4B — Taxonomy FAQ Content Readiness
 * Description: Bounded source-only FAQ gaps for the existing governed Content Readiness ledger.
 */
defined('ABSPATH')||exit;
final class MAD4B_Taxonomy_FAQ_Readiness {
 const SOURCE='mad4b-taxonomy-faq';
 const MAX_TOTAL=40;
 const MAX_PER_TAX=8;
 private static function approved(array $raw):bool {
  foreach($raw as $row){
   if(!is_array($row))continue;
   if((string)($row['faq_review']??'')==='approved' &&
      !in_array((string)($row['faq_enabled']??'1'),['false','0','no','off'],true) &&
      trim((string)($row['faq_question']??''))!=='' &&
      trim(wp_strip_all_tags((string)($row['faq_answer']??'')))!=='')return true;
  }
  return false;
 }
 private static function covered(WP_Term $term,array &$seen,int $depth=0):bool {
  if($depth>4 || isset($seen[$term->term_id]))return false;
  $seen[$term->term_id]=true;
  $items=get_term_meta($term->term_id,'mad4b_term_faq_items',true);
  if(is_array($items) && self::approved($items))return true;
  $mode=(string)get_term_meta($term->term_id,'mad4b_term_faq_mode',true);
  if($mode==='override' || $term->parent<=0)return false;
  $parent=get_term($term->parent,$term->taxonomy);
  return $parent instanceof WP_Term && self::covered($parent,$seen,$depth+1);
 }
 public static function scan() {
  if(!class_exists('MAD4B_Taxonomy_FAQ_Source'))return [];
  $issues=[];
  foreach(MAD4B_Taxonomy_FAQ_Source::enabled_taxonomies() as $taxonomy){
   if(!taxonomy_exists($taxonomy))return new WP_Error('faq_taxonomy_registry_incomplete','Enabled taxonomy unavailable');
   $terms=get_terms(['taxonomy'=>$taxonomy,'hide_empty'=>true,'number'=>self::MAX_PER_TAX,'orderby'=>'count','order'=>'DESC','suppress_filter'=>true]);
   if(is_wp_error($terms)||!is_array($terms))return new WP_Error('faq_term_inventory_unavailable','Taxonomy FAQ inventory unavailable');
   foreach($terms as $term){
    if(!$term instanceof WP_Term || (int)$term->count<1)continue;
    $seen=[];if(self::covered($term,$seen))continue;
    $code='faq_term_missing_'.$taxonomy.'_'.$term->term_id;
    $issues[]=['id'=>hash('sha256',get_current_blog_id().'|site|0|'.$code),
     'post_type'=>'site','object_id'=>0,'code'=>$code,'severity'=>'medium',
     'label'=>mb_substr('ContentJob candidate: add reviewed taxonomy FAQs for '. $taxonomy .' / '. $term->name .' (#'. $term->term_id .')',0,235),
     'source'=>self::SOURCE];
    if(count($issues)>=self::MAX_TOTAL)return $issues;
   }
  }
  return $issues;
 }
 public static function append($existing,$policy=[]){
  if(!is_array($existing))return $existing;
  if(!class_exists('MAD4B_SCP_Content_Readiness_Alerts',false))return $existing;
  $scan=self::scan();
  if(is_wp_error($scan))return $scan;
  return array_merge($existing,$scan);
 }
}
add_filter('mad4b/content_readiness/external_issues',['MAD4B_Taxonomy_FAQ_Readiness','append'],66,2);
