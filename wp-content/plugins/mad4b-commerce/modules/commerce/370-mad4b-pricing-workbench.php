<?php
/** Plugin Name: MAD4B Pricing Workbench */
defined('ABSPATH')||exit;
final class MAD4B_Pricing_Workbench {
 const TYPES=['tours-and-activities'=>'tour','properties'=>'accommodation','transportations'=>'transport'];
 static function inspect($id){
  $id=absint($id);$type=get_post_type($id);
  if(!isset(self::TYPES[$type]))return new WP_Error('pricing_product_unsupported');
  $domain=self::TYPES[$type];$alerts=[];$source=[];$settings=[];
  if($domain==='tour'){
   if(!class_exists('Shared_Tour_Pricing_Entry_v1') || !function_exists('shared_lab_tour_price_resolve'))
    return ['product_id'=>$id,'title'=>get_the_title($id),'post_type'=>$type,
     'domain'=>$domain,'source'=>['status'=>'native_tour_provider_unavailable'],
     'settings'=>[],'alerts'=>['pricing_tour_resolver_missing'=>'Native Tour pricing provider is not installed for this site'],
     'checkout_ready'=>false,'commercially_verified'=>false];
   $entry=Shared_Tour_Pricing_Entry_v1::inspect($id);
   $public=shared_lab_tour_price_resolve($id);
   $source=['entry_mode'=>$entry['effective_mode']??'unknown','requested_mode'=>$entry['requested_mode']??'unknown',
     'public_source'=>$public['source']??'none','public_amount'=>$public['best']['final']??null,
     'public_currency'=>$public['best']['currency']??($public['currency']??null),
     'native_rate_currency'=>$public['best']['source_currency']??null,
     'public_rate_id'=>$public['best']['rate_id']??null,
     'published_rate_ids'=>$entry['rate_ids']??[]];
   $settings=['inheritance'=>$entry['origin']??null,'tour_type'=>$entry['type']??null,
     'occupancy_factors'=>$entry['factor_configuration']??[]];
   if(($entry['effective_mode']??'')==='inquiry'&&($public['status']??'')==='available')
    $alerts['pricing_display_inquiry_conflict']='Visible price conflicts with inquiry-only booking mode';
   if(empty($entry['rate_ids'])&&in_array($entry['type']??'',['package','cruise'],true))
    $alerts['pricing_native_rate_missing']='Package or cruise has no published native Tour Rate';
   if(($public['source']??'')==='simple')
    $alerts['pricing_start_price_only']='Public starting price is indicative, not bookable';
  } elseif($domain==='accommodation'){
   global $wpdb;
   $units=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default r JOIN {$wpdb->posts} p ON p.ID=r.child_object_id WHERE r.rel_id=223 AND r.parent_object_id=%d AND p.post_type='units' AND p.post_status='publish'",$id));
   $rates=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='property-rates' AND post_status='publish'");
   $source=['pricing_authority'=>'property-rate','published_site_rates'=>$rates,'linked_published_units'=>$units];
   $settings=['service_channel'=>function_exists('service_channel_mode')?service_channel_mode('properties'):null,
     'amenities_are_descriptive'=>true,'local_property_offers'=>class_exists('Shared_Property_Amenity_Offers')?count(Shared_Property_Amenity_Offers::rows($id)):0];
   if(!$units)$alerts['pricing_property_unit_missing']='Property has no linked published Unit';
   if(!$rates)$alerts['pricing_property_rate_missing']='No published Property Rates exist';
  } else {
   $ids=get_posts(['post_type'=>'transport-rates','post_status'=>'publish','fields'=>'ids',
     'posts_per_page'=>100,'suppress_filters'=>true,'meta_query'=>[['key'=>'source_post_id','value'=>(string)$id]]]);
   $approved=0;
   foreach($ids as $rate)if(function_exists('BookingExperience\\transport_rate_approval_verified')&&BookingExperience\transport_rate_approval_verified($rate))$approved++;
   $source=['pricing_authority'=>'transport-rate','published_rate_ids'=>$ids,'approved_rate_count'=>$approved];
   $settings=['service_channel'=>function_exists('service_channel_mode')?service_channel_mode('transportations'):null,
     'extras_authority'=>'rate-owned','supplier_availability'=>'not_checked'];
   if(!$approved)$alerts['pricing_transport_rate_unapproved']='No currently approved Transport Rate';
   $alerts['pricing_supplier_unverified']='Supplier availability is not certified';
  }
  if(class_exists('MAD4B_SCP_Market_Profiles')&&!count(MAD4B_SCP_Market_Profiles::approved_list()))
   $alerts['pricing_market_profile_missing']='No approved site-bound Market Profiles';
  if(get_option('woocommerce_calc_taxes')!=='yes')
   $alerts['pricing_tax_policy_missing']='WooCommerce tax calculations are not configured';
  return ['product_id'=>$id,'title'=>get_the_title($id),'post_type'=>$type,'domain'=>$domain,
    'source'=>$source,'settings'=>$settings,'alerts'=>$alerts,'checkout_ready'=>false,
    'commercially_verified'=>false];
 }
 static function menu(){
  add_submenu_page('woocommerce','Pricing Workbench','Pricing Workbench','manage_woocommerce',
    'mad4b-pricing-workbench',[self::class,'page']);
 }
 static function page(){
  if(!current_user_can('manage_woocommerce'))wp_die('Forbidden');
  $id=absint($_GET['product_id']??0);
  echo '<div class="wrap"><h1>Pricing Workbench</h1><p>Site-scoped source inspection only. No financial authority or writes.</p>';
  echo '<p><a class="button" href="'.esc_url(admin_url('admin.php?page=mad4b-pricing-simulation-presets')).'">Operational Simulation Presets (read-only)</a></p>';
  echo '<form><input type="hidden" name="page" value="mad4b-pricing-workbench">';
  echo '<label>Product ID <input type="number" min="1" name="product_id" value="'.esc_attr((string)$id).'"></label> ';
  submit_button('Inspect','secondary','submit',false);echo '</form>';
  if(!$id){echo '<p>Try Tour 60627, Property 36362 or Transport 39707.</p></div>';return;}
  $result=self::inspect($id);
  if(is_wp_error($result)){echo '<p>Unsupported item.</p></div>';return;}
  echo '<h2>'.esc_html($result['title']).'</h2><p><a href="'.esc_url(get_edit_post_link($id)).'">Open native editor</a></p>';
  foreach(['source'=>'Pricing authority and display','settings'=>'Overrides and inheritance','alerts'=>'Content readiness candidates'] as $key=>$name){
   echo '<h3>'.esc_html($name).'</h3><table class="widefat striped"><tbody>';
   foreach($result[$key] as $label=>$value)echo '<tr><th>'.esc_html($label).'</th><td><code>'.
     esc_html(is_array($value)?wp_json_encode($value,JSON_UNESCAPED_UNICODE):(string)$value).'</code></td></tr>';
   echo '</tbody></table>';
  }
  echo '<p><strong>Preview-only:</strong> this page cannot publish or authorize prices, markets, supplier inventory or orders.</p></div>';
 }
}
add_action('admin_menu',['MAD4B_Pricing_Workbench','menu']);
/** Strict native Content Readiness source. No status/authorization fields. */
function mad4b_pricing_workbench_alerts($previous,$cfg){
 if(!is_array($previous))return $previous;
 $issues=[];$seen=[];
 $add=static function($type,$id,$code,$severity,$label)use(&$issues,&$seen){
  $key=$type.'|'.$id.'|'.$code;if(isset($seen[$key]))return;
  $seen[$key]=true;
  $issues[]=['id'=>hash('sha256',get_current_blog_id().'|'.$type.'|'.$id.'|'.$code),
   'post_type'=>$type,'object_id'=>(int)$id,'code'=>$code,'severity'=>$severity,
   'label'=>$label,'source'=>'mad4b-pricing-workbench'];
 };
 global $wpdb;
 if(class_exists('MAD4B_SCP_Market_Profiles')&&!count(MAD4B_SCP_Market_Profiles::approved_list()))
  $add('site',0,'pricing_market_profile_missing','high','Pricing: no site-bound approved Market Profiles');
 if(get_option('woocommerce_calc_taxes')!=='yes')
  $add('site',0,'pricing_tax_policy_missing','high','Pricing: tax calculation rules not configured');
 $propertyRates=(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='property-rates' AND post_status='publish'");
 if(!$propertyRates)$add('site',0,'pricing_property_rate_missing','critical','Accommodation: zero published Property Rates');
 $transportRateIds=get_posts(['post_type'=>'transport-rates','post_status'=>'publish',
   'fields'=>'ids','posts_per_page'=>100,'suppress_filters'=>true]);
 $approved=0;foreach($transportRateIds as $rate)
  if(function_exists('BookingExperience\\transport_rate_approval_verified') &&
   BookingExperience\transport_rate_approval_verified($rate))$approved++;
 if(!$approved)$add('site',0,'pricing_transport_rate_missing','critical','Transport: no approved published Rates');
 $original=(string)apply_filters('wpml_default_language','en');
 foreach(['tours-and-activities','transportations','properties'] as $type){
  $posts=get_posts(['post_type'=>$type,'post_status'=>'publish','fields'=>'ids',
    'posts_per_page'=>1200,'suppress_filters'=>true]);
  foreach($posts as $id){
   $id=(int)$id;
   if(has_filter('wpml_object_id')){
    $root=apply_filters('wpml_object_id',$id,$type,false,$original);
    if($root && (int)$root!==$id)continue;
   }
   if($type==='tours-and-activities'){
    $r=Shared_Tour_Pricing_Entry_v1::inspect($id);
    if(empty($r['ok']))continue;
    if(in_array($r['type']??'',['package','cruise'],true)&&empty($r['rate_ids']))
     $add($type,$id,'pricing_native_rate_missing','high','Tour: package/cruise has no published native Rate');
    $display=shared_lab_tour_price_resolve($id);
    if(($display['status']??'')==='available'&&($r['effective_mode']??'')==='inquiry')
     $add($type,$id,'pricing_display_inquiry_conflict','critical','Tour: visible price but effective mode is Inquiry');
   }elseif($type==='transportations'){
    $rates=get_posts(['post_type'=>'transport-rates','post_status'=>'publish','fields'=>'ids',
      'posts_per_page'=>50,'suppress_filters'=>true,
      'meta_query'=>[['key'=>'source_post_id','value'=>(string)$id]]]);
    $yes=false;foreach($rates as $rid)
     if(function_exists('BookingExperience\\transport_rate_approval_verified') &&
      BookingExperience\transport_rate_approval_verified($rid)){$yes=true;break;}
    if(!$yes)$add($type,$id,'pricing_transport_rate_unapproved','high','Transport product has no approved published Rate');
   }else{
    // Only properties explicitly marked for overnight stays can require a Unit.
    if(get_post_meta($id,'prop_supports_overnight_stay',true)!=='supported')continue;
    $units=(int)$wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}jet_rel_default r JOIN {$wpdb->posts} p ON p.ID=r.child_object_id WHERE r.rel_id=223 AND r.parent_object_id=%d AND p.post_type='units' AND p.post_status='publish'",$id));
    if(!$units)$add($type,$id,'pricing_property_unit_missing','high','Overnight property has no published linked Unit');
   }
  }
 }
 return array_merge($previous,$issues);
}
add_filter('mad4b/content_readiness/external_issues','mad4b_pricing_workbench_alerts',30,2);
