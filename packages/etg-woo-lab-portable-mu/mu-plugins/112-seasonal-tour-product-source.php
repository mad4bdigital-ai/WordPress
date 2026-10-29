<?php
/** Read-only data source for nested seasonal tour listings, based on CPT WooCommerce Integration. */
if ( ! defined( 'ABSPATH' ) ) return;
/** Never duplicate products, prices or taxonomy terms; inspect canonical integration settings. */
function seasonal_tour_source_status() {
 $settings = get_option( 'cptwooint_settings', [] );
 $types = is_array($settings) ? (array)($settings['selected_post_types'] ?? []) : [];
 $supported = class_exists('\\TinySolutions\\cptwooint\\Helpers\\Fns')
  ? \TinySolutions\cptwooint\Helpers\Fns::supported_post_types() : [];
 $tour='tours-and-activities';$rate='tour-rates';
 return [
  'contract'=>'etg.topbar-cptwoo-source.v1',
  'provider'=>'cpt-woo-integration',
  'plugin_active'=>function_exists('is_plugin_active')&&is_plugin_active('cpt-woo-integration/cpt-woo-integration.php'),
  'tour_cpt'=>$tour,'rate_cpt'=>$rate,
  'tour_product_supported'=>in_array($tour,$supported,true)&&isset($types[$tour]),
  'rate_product_supported'=>in_array($rate,$supported,true)&&isset($types[$rate]),
  'tour_price_meta'=>$types[$tour]['regular_price']??'',
  'rate_price_meta'=>$types[$rate]['regular_price']??'',
  'rate_sale_meta'=>$types[$rate]['sale_price']??'',
  'season_taxonomy_for_both'=>taxonomy_exists('season')&&is_object_in_taxonomy($tour,'season')&&is_object_in_taxonomy($rate,'season'),
  'listing_source'=>'tours-and-activities',
  'price_resolver'=>'WooCommerce + Shared Product Core (not Top Bar)',
  'creates_duplicate_woo_product'=>false,
 ];
}
/** Reusable JetEngine Query Builder equivalent for the approved season's primary tour CPT. */
function seasonal_tour_query_args($season_term_id,$limit=6){
 $status=seasonal_tour_source_status();$term=get_term(absint($season_term_id),'season');
 if(!$status['tour_product_supported']||!$status['season_taxonomy_for_both']||!$term||is_wp_error($term))return null;
 return [
  'post_type'=>$status['tour_cpt'],
  'post_status'=>'publish',
  'posts_per_page'=>max(1,min(12,(int)$limit)),
  'no_found_rows'=>true,
  'ignore_sticky_posts'=>true,
  'tax_query'=>[[ 'taxonomy'=>'season','field'=>'term_id','terms'=>[(int)$term->term_id],'include_children'=>false ]],
 ];
}
/** Native WooCommerce product validation; no silent replacement with product posts. */
function seasonal_tour_candidates($season_term_id,$limit=6){
 $args=seasonal_tour_query_args($season_term_id,$limit);
 if(!$args||!function_exists('wc_get_product'))return [];
 $query=new WP_Query($args);$out=[];
 foreach($query->posts as $post){
  if($post->post_type!=='tours-and-activities')continue;
  $product=wc_get_product($post->ID);
  if(!$product||$product->get_id()!==$post->ID)continue;
  $out[]=['id'=>$post->ID,'post_type'=>$post->post_type,'product_id'=>$product->get_id(),
   'title'=>get_the_title($post),'url'=>get_permalink($post),
   'season_term_id'=>(int)$season_term_id, 'price_authority'=>'woocommerce_cpt_integration'];
 }
 return $out;
}
/** Editor info only; do not insert financial offers or terms. */
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce'))return;
 add_submenu_page('woocommerce','Seasonal Tour Data Source','Seasonal Tour Source','manage_woocommerce','seasonal-tour-product-source',static function(){
  if(!current_user_can('manage_woocommerce'))wp_die('Access denied');
  $status=seasonal_tour_source_status();
  echo '<div class="wrap"><h1>Seasonal Tour Listing Source</h1><p>Native CPT WooCommerce Integration; season filtering uses original tour taxonomy and not WooCommerce product_cat.</p><table class="widefat striped" style="max-width:900px"><tbody>';
  foreach($status as $key=>$value)if($key!=='contract')echo '<tr><th>'.esc_html(str_replace('_',' ',$key)).'</th><td>'.esc_html(is_bool($value)?($value?'Yes':'No'):(string)$value).'</td></tr>';
  echo '</tbody></table><p>The nested listing should query <code>tours-and-activities</code> by the current <code>season</code> term. Tour Rates price and availability remain controlled by the existing pricing engine.</p></div>';
 });
},97);
