<?php
/** Local ETG: MAD4B owns market identity; WooCommerce owns commercial targeting and offer categories. */
if(!defined('ABSPATH')||(!defined('MAD4B_COMMERCE_RUNTIME_ENABLED') || !MAD4B_COMMERCE_RUNTIME_ENABLED))return;
/** Offer taxonomy is not another season, nor a Woo product category. */
add_action('init',static function(){
 if(!post_type_exists('offer-campaign'))return;
 if(taxonomy_exists('season'))register_taxonomy_for_object_type('season','offer-campaign');
 if(!taxonomy_exists('offer-category'))register_taxonomy('offer-category',['offer-campaign'],[
  'labels'=>['name'=>'Offer Categories','singular_name'=>'Offer Category','menu_name'=>'Offer Categories'],
  'hierarchical'=>true,'public'=>false,'show_ui'=>true,'show_in_rest'=>true,'show_admin_column'=>true,
  'rewrite'=>false,'query_var'=>false,'show_in_quick_edit'=>true,
  'capabilities'=>['manage_terms'=>'manage_woocommerce','edit_terms'=>'manage_woocommerce','delete_terms'=>'manage_woocommerce','assign_terms'=>'edit_posts']]);
},9999);
/** Dynamic from the canonical, site-bound MAD4B approved records: never WPML inferred languages or guessed countries. */
function shared_woo_approved_market_options(){
 $options=[['key'=>'','value'=>'Global editorial / approved-market-wide commercial rule']];
 if(!class_exists('MAD4B_SCP_Market_Profiles'))return $options;
 foreach(MAD4B_SCP_Market_Profiles::approved_list() as $market){
  $id=sanitize_key($market['profile_id']??'');if(!$id)continue;
  $options[]=['key'=>$id,'value'=>sprintf('%s — %s / %s (%s)',$market['label'],$market['country'],$market['language'],$id)];
 }
 return $options;
}
/** Populate JetEngine's native Repeater select options on *read*, not by rewriting Market records. */
add_filter('option_jet_engine_meta_boxes',static function($boxes){
 if(!is_array($boxes))return $boxes;
 $market_options=shared_woo_approved_market_options();
 foreach(['meta-4','meta-2'] as $key){
  if(!isset($boxes[$key]['meta_fields'])||!is_array($boxes[$key]['meta_fields']))continue;
  foreach($boxes[$key]['meta_fields'] as &$field){
   if(!in_array($field['name']??'',['woo_offer_market_rules','season_topbar_messages'],true))continue;
   if(!isset($field['repeater-fields'])||!is_array($field['repeater-fields']))continue;
   foreach($field['repeater-fields'] as &$child){
    if(($child['name']??'')==='market_profile_id'){$child['options_source']='manual';$child['options']=$market_options;}
   }
   unset($child);
  }
  unset($field);
 }
 return $boxes;
},20);
/** Trusted visitor market context is supplied by MAD4B bridge, not country query params. */
function shared_woo_market_rule_match($rule,$context=null){
 if(!is_array($rule)||empty($rule['enabled']))return false;
 if(!class_exists('MAD4B_SCP_Market_Profiles')||!function_exists('shared_offer_current_visible_language'))return false;
 $language=shared_offer_current_visible_language();
 if(!$language||!isset(MAD4B_SCP_Market_Profiles::enabled_languages()[$language]))return false;
 $wanted=sanitize_key((string)($rule['market_profile_id']??''));
 if(!$wanted)return ($rule['scope']??'global')==='global';
 $approved=MAD4B_SCP_Market_Profiles::approved($wanted);
 if(!$approved||($approved['language']??'')!==$language)return false;
 $context=is_array($context)?$context:(function_exists('shared_offer_mad4b_context')?shared_offer_mad4b_context():[]);
 $actual=$context['market']??[];
 return is_array($actual)&&($actual['profile_id']??'')===$wanted&&($actual['revision']??null)===$approved['revision']
  &&($actual['site_uuid']??'')===$approved['site_uuid'];
}
/** If an offer campaign is tagged with Seasons, the published commercial offer must be inside an enabled term window. */
function shared_woo_campaign_season_eligible($campaign_id,$today=null){
 $terms=wp_get_object_terms(absint($campaign_id),'season',['fields'=>'ids']);
 if(is_wp_error($terms))return false;
 if(!$terms)return true; // Nonseasonal evergreen campaigns retain their own verified campaign dates.
 $today=$today?:wp_date('Y-m-d');
 if(!function_exists('shared_offer_valid_date')||!shared_offer_valid_date($today))return false;
 foreach($terms as $term_id){
  if(!in_array((string)get_term_meta($term_id,'season_enabled',true),['1','true','on'],true))continue;
  $window=shared_offer_term_window($term_id,$today)?:shared_offer_occasion_window($term_id,$today);
  if(is_array($window)&&count($window)===2&&$window[0]<=$today&&$today<=$window[1])return true;
 }
 return false;
}
/** A campaign is commercial; its targeting lives as JetEngine post-meta, and cannot auto-approve any market. */
function shared_woo_campaign_market_eligible($campaign_id,$context=null){
 $post=get_post(absint($campaign_id));if(!$post||$post->post_type!=='offer-campaign'||$post->post_status!=='publish')return false;
 if(!shared_woo_campaign_season_eligible($post->ID))return false;
 $rows=get_post_meta($post->ID,'woo_offer_market_rules',true);
 if(!is_array($rows)||!$rows)return false; // Fail closed until targeting is deliberately configured.
 $context=is_array($context)?$context:(function_exists('shared_offer_mad4b_context')?shared_offer_mad4b_context():[]);
 $actual=$context['market']??[];
 foreach(array_slice($rows,0,50) as $row){
  if(!shared_woo_market_rule_match($row,$context))continue;
  // Commercial global means all *approved* markets, never an inferred worldwide entitlement.
  if(empty($row['market_profile_id'])){
   $id=sanitize_key((string)($actual['profile_id']??''));
   $approved=$id?MAD4B_SCP_Market_Profiles::approved($id):[];
   if(!$approved||($approved['language']??'')!==shared_offer_current_visible_language()
    ||($actual['site_uuid']??'')!==($approved['site_uuid']??'')
    ||($actual['revision']??null)!==($approved['revision']??null))continue;
  }
  return true;
 }
 return false;
}
/** Bind to the existing Shared Product Core offer resolver without changing the price authority. */
add_filter('shared_offer/woo_campaign_eligible',static fn($eligible,$campaign_id)=>$eligible&&shared_woo_campaign_market_eligible($campaign_id),10,2);
/** Legacy seasonal rules are WooCommerce presentation preferences; market identity remains MAD4B-only. */
add_filter('shared_offer/seasonal_rule_eligible',static function($eligible,$rule){
 if(!$eligible)return false;
 $profile=sanitize_key((string)($rule['market_profile_id']??''));
 if(!$profile)return true; // Season-only global information remains non-commercial.
 return shared_woo_market_rule_match(['enabled'=>true,'scope'=>'domestic','market_profile_id'=>$profile]);
},12,2);
/** Read-only WooCommerce / MAD4B split diagnostics for editors. */
add_action('add_meta_boxes_offer-campaign',static function(){
 add_meta_box('shared-woo-market-authority','Market targeting authority',static function($post){
  $rows=get_post_meta($post->ID,'woo_offer_market_rules',true);$rows=is_array($rows)?$rows:[];
  echo '<p>MAD4B approves countries and visible WPML languages. WooCommerce stores targeting per campaign; editing these rows cannot approve a market.</p>';
  echo '<p>Configured targeting rows: <strong>'.count($rows).'</strong>. Approved MAD4B market profiles: <strong>'.max(0,count(shared_woo_approved_market_options())-1).'</strong>.</p>';
  echo '<p><a href="'.esc_url(admin_url('admin.php?page=mad4b-market-profiles')).'">Manage approved markets in MAD4B</a></p>';
  foreach($rows as $row)if(!empty($row['market_profile_id'])&&!MAD4B_SCP_Market_Profiles::approved(sanitize_key($row['market_profile_id'])))
   echo '<p><strong>Review required:</strong> A configured Market Profile is unavailable, unapproved or hidden by WPML. It will not match public targeting.</p>';
 },'offer-campaign','normal','default');
});
/** One editorial entrypoint without cloning MAD4B market or Season taxonomy data. */
add_action('admin_menu',static function(){
 if(!class_exists('WooCommerce'))return;
 add_submenu_page('woocommerce','Offer Targeting & Seasons','Offer Targeting','manage_woocommerce','shared-woo-offer-targeting',static function(){
  if(!current_user_can('manage_woocommerce'))wp_die('Access denied');
  $market_status=class_exists('MAD4B_SCP_Market_Profiles')?MAD4B_SCP_Market_Profiles::status():[];
  $offer_settings=get_option('global-offers',[]);
  $campaigns=get_posts(['post_type'=>'offer-campaign','post_status'=>['publish','draft','pending','future','private'],'posts_per_page'=>100,'orderby'=>'modified','order'=>'DESC']);
  echo '<div class="wrap"><h1>WooCommerce — Offer Targeting & Seasons</h1>';
  echo '<p><strong>Shared governance:</strong> MAD4B approves market identities; WooCommerce stores targeting and offer eligibility; Seasons supply the dates; JetEngine edits Meta Fields and Repeaters; Elementor displays only eligible content.</p>';
  echo '<table class="widefat striped" style="max-width:850px"><tbody>';
  foreach([
   'Approved MAD4B Market Profiles'=>(int)($market_status['approved_count']??0),
   'Available WPML languages'=>implode(', ',(array)($market_status['enabled_languages']??[])),
   'Tour products (CPT Woo Integration)'=>function_exists('seasonal_tour_source_status')?(seasonal_tour_source_status()['tour_cpt']??'not configured'):'source unavailable',
   'Tour Rates (CPT Woo Integration)'=>function_exists('seasonal_tour_source_status')?(seasonal_tour_source_status()['rate_cpt']??'not configured'):'source unavailable',
   'WooCommerce offer campaigns'=>count($campaigns),
   'Seasonal topbar enabled'=>!empty($offer_settings['seasonal_templates_enabled'])?'Yes':'No',
   'Financial offers enabled'=>!empty($offer_settings['offers_enabled'])?'Yes':'No',
   'Season Taxonomy'=>taxonomy_exists('season')?'Shared with campaigns and tours':'Unavailable',
   'Offer Categories'=>taxonomy_exists('offer-category')?'Campaign classification only':'Unavailable',
  ] as $label=>$value)echo '<tr><th scope="row">'.esc_html($label).'</th><td>'.esc_html((string)$value).'</td></tr>';
  echo '</tbody></table>';
  echo '<p>The Top Bar tours are selected from the native CPT WooCommerce Integration post types. Offer Campaigns remain a separate editorial/commercial campaign CPT; product_cat is not required for tours.</p>';
  echo '<p><a class="button button-primary" href="'.esc_url(admin_url('post-new.php?post_type=offer-campaign')).'">Create draft campaign</a> ';
  echo '<a class="button" href="'.esc_url(admin_url('edit.php?post_type=offer-campaign')).'">Manage campaigns</a> ';
  echo '<a class="button" href="'.esc_url(admin_url('edit-tags.php?taxonomy=offer-category&post_type=offer-campaign')).'">Offer Categories</a> ';
  echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=shared-offer-seasons')).'">Seasonal Top Bar</a> ';
  echo '<a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-market-profiles')).'">MAD4B Market Profiles</a></p>';
  echo '<h2>Campaign targeting readiness</h2><table class="widefat striped"><thead><tr><th>Campaign</th><th>Post state</th><th>Seasons</th><th>Offer Categories</th><th>Market rules</th></tr></thead><tbody>';
  foreach($campaigns as $post){
   $rules=get_post_meta($post->ID,'woo_offer_market_rules',true);$rules=is_array($rules)?$rules:[];
   $seasons=wp_get_object_terms($post->ID,'season',['fields'=>'names']);$categories=wp_get_object_terms($post->ID,'offer-category',['fields'=>'names']);
   echo '<tr><td><a href="'.esc_url(get_edit_post_link($post->ID)).'">'.esc_html($post->post_title).'</a></td><td>'.esc_html($post->post_status).'</td><td>'.esc_html(is_wp_error($seasons)?'Unavailable':implode(', ',$seasons)).'</td><td>'.esc_html(is_wp_error($categories)?'Unavailable':implode(', ',$categories)).'</td><td>'.count($rules).'</td></tr>';
  }
  echo '</tbody></table><p>No market is activated by adding a targeting row. Commercial messages and prices still require Shared Product Core certification and the appropriate Brand Authority review.</p></div>';
 });
},95);
