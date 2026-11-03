<?php
/**
 * Portable taxonomy authorities for tour booking choices, travel season and price.
 * No new taxonomies, fake prices, implicit discounts or checkout writes.
 */
if (!defined('ABSPATH')) return;

function shared_product_booking_modes_from_taxonomy($tour_id) {
 $id=(int)$tour_id;
 if(get_post_type($id)!=='tours-and-activities')return new WP_Error('booking_mode_invalid_tour');
 $terms=wp_get_post_terms($id,'tour_categories');
 if(is_wp_error($terms))return $terms;
 $modes=[];
 $recognized=[];
 $canonical=['private-tour'=>'private','private-trip'=>'private','group-tour'=>'group','group-trip'=>'group',
  'visite-en-groupe'=>'group','mixed'=>'mixed','mixed-group-private'=>'mixed'];
 $canonical=apply_filters('shared_product_booking_category_map',$canonical);
 if(!is_array($canonical))return new WP_Error('booking_category_map_invalid');
 foreach($terms as $term){
  $mode=$canonical[$term->slug]??null;
  if($mode===null)continue;
  if(!in_array($mode,['group','private','mixed'],true))return new WP_Error('booking_category_mode_invalid');
  $recognized[]=['term_id'=>$term->term_id,'slug'=>$term->slug,'mode'=>$mode];
  if($mode==='mixed'){$modes['group']=true;$modes['private']=true;}
  else $modes[$mode]=true;
 }
 return ['taxonomy'=>'tour_categories','modes'=>array_keys($modes),'terms'=>$recognized];
}

function shared_product_season_term_matches_date($term,$date) {
 if(!$term instanceof WP_Term || $term->taxonomy!=='season' || !is_string($date))return false;
 if(get_term_meta($term->term_id,'season_enabled',true)!=='1')return false;
 $mode=(string)get_term_meta($term->term_id,'season_mode',true);
 if($mode!=='' && $mode!=='travel')return false;
 $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date,new DateTimeZone('UTC'));
 if(!$d || $d->format('Y-m-d')!==$date)return false;
 $sm=(int)get_term_meta($term->term_id,'season_start_month',true);
 $sd=(int)get_term_meta($term->term_id,'season_start_day',true);
 $em=(int)get_term_meta($term->term_id,'season_end_month',true);
 $ed=(int)get_term_meta($term->term_id,'season_end_day',true);
 $cross=get_term_meta($term->term_id,'season_cross_year',true)==='1';
 $year=(int)substr($date,0,4);
 foreach([$year-1,$year] as $y){
  if(!checkdate($sm,$sd,$y)||!checkdate($em,$ed,$y+($cross?1:0)))continue;
  $from=sprintf('%04d-%02d-%02d',$y,$sm,$sd);
  $until=sprintf('%04d-%02d-%02d',$y+($cross?1:0),$em,$ed);
  if($date>=$from && $date<=$until)return true;
 }
 return false;
}

function shared_product_taxonomy_season_context($tour,$rate,$date,$requested=null) {
 $tour=(int)$tour;$rate=$rate===null?null:(int)$rate;
 if($rate!==null && (int)get_post_meta($rate,'related_tour_id',true)!==$tour)return new WP_Error('season_rate_parent_invalid');
 if($requested!==null && (!$date||!is_int($requested)||$requested<1))return new WP_Error('season_selection_requires_date');
 $authority=$rate!==null?$rate:$tour;
 $terms=wp_get_post_terms($authority,'season');
 if(is_wp_error($terms))return $terms;
 // Tour categories are not substituted when an explicitly chosen Rate lacks season terms.
 if(!$terms){
  if($requested!==null)return new WP_Error('season_not_assigned_to_source');
  return ['status'=>'no_season_taxonomy_assigned','source'=>$rate!==null?'rate':'tour','term_id'=>null,'slug'=>null];
 }
 if(!$date)return new WP_Error('season_travel_date_required');
 $matches=[];
 foreach($terms as $term){
  if($requested!==null && (int)$term->term_id!==$requested)continue;
  if(shared_product_season_term_matches_date($term,$date))$matches[]=$term;
 }
 if(count($matches)!==1)return new WP_Error($matches?'ambiguous_season_for_date':'season_not_valid_for_travel_date');
 $t=$matches[0];
 return ['status'=>'season_taxonomy_verified','source'=>$rate!==null?'rate':'tour','taxonomy'=>'season',
   'term_id'=>(int)$t->term_id,'slug'=>$t->slug,'travel_date'=>$date,
   'price_effect'=>'none_native_rate_already_season_priced'];
}

/** Explicit percentage of the base price. No implicit 100% or guessing from Group Rate. */
function shared_product_private_price_rule($tour,$rate) {
 $source_id=$rate===null?(int)$tour:(int)$rate;
 $value=get_post_meta($source_id,'_shared_product_private_price_percent',true);
 if($value==='' || $value===null)return new WP_Error('private_pricing_rule_missing');
 if(!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<=0||(float)$value>300)
  return new WP_Error('private_pricing_rule_invalid');
 return ['source'=>$rate===null?'tour':'rate','source_id'=>$source_id,'percent'=>(float)$value,
   'status'=>'explicit_private_base_price_percentage'];
}

/** Private pricing configuration on the existing Tour and Tour Rate editors. */
foreach(['tours-and-activities','tour-rates'] as $type){
 add_action('add_meta_boxes_'.$type,static function()use($type){
  add_meta_box('shared_product_private_pricing','Private booking price (taxonomy controlled)',
    'shared_product_private_pricing_editor',$type,'normal','default');
 });
 add_action('save_post_'.$type,'shared_product_private_pricing_save',25,2);
}
function shared_product_private_pricing_editor($post){
 $modes=get_post_type($post)==='tours-and-activities'?shared_product_booking_modes_from_taxonomy($post->ID):null;
 if(get_post_type($post)==='tour-rates'){
  $tour=(int)get_post_meta($post->ID,'related_tour_id',true);
  $modes=$tour?shared_product_booking_modes_from_taxonomy($tour):new WP_Error('tour_relation_missing');
 }
 $eligible=is_array($modes)&&in_array('private',$modes['modes'],true);
 echo '<p>Private eligibility uses the existing <code>tour_categories</code> taxonomy. No new category or price is generated.</p>';
 echo '<p><strong>Private booking allowed:</strong> '.($eligible?'Yes':'No / taxonomy not configured').'</p>';
 wp_nonce_field('shared_product_private_pricing_save','shared_product_private_price_nonce');
 $value=get_post_meta($post->ID,'_shared_product_private_price_percent',true);
 echo '<label for="shared-product-private-percent">Private base price (% of existing direct/Rate base)</label> ';
 echo '<input id="shared-product-private-percent" type="number" min="0.01" max="300" step="0.01" name="shared_product_private_price_percent" value="'.esc_attr($value).'">';
 echo '<p class="description">Leave blank to block an uncertified private quote. 100% means the same base only when explicitly approved. Does not modify extras, taxes, cart, orders or payment.</p>';
}
function shared_product_private_pricing_save($id,$post){
 if(!isset($_POST['shared_product_private_price_nonce'])||!is_string($_POST['shared_product_private_price_nonce']))return;
 if(!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_product_private_price_nonce'])),'shared_product_private_pricing_save'))return;
 if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
 if(!current_user_can('edit_post',$id)||!in_array(get_post_type($id),['tour-rates','tours-and-activities'],true))return;
 $raw=$_POST['shared_product_private_price_percent']??null;
 if(!is_string($raw))return;
 $raw=trim(wp_unslash($raw));
 if($raw===''){delete_post_meta($id,'_shared_product_private_price_percent');return;}
 if(!is_numeric($raw)||!is_finite((float)$raw)||(float)$raw<=0||(float)$raw>300)return;
 update_post_meta($id,'_shared_product_private_price_percent',(string)round((float)$raw,2));
}
