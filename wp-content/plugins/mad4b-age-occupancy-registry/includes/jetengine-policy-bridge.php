<?php
/**
 * Link registered JetEngine price-calculation options pages to the one WooCommerce
 * age/occupancy policy editor. JetEngine never stores a second policy copy.
 */
defined('ABSPATH') || exit;

final class MAD4B_Age_Occupancy_JetEngine_Bridge {
 public static function init(){
  add_action('admin_notices',[__CLASS__,'notice']);
 }

 public static function is_pricing_page($page){
  if(!is_array($page)||empty($page['slug']))return false;
  $args=maybe_unserialize($page['args']??[]);
  if(!is_array($args))$args=[];
  $label=(string)($page['name']??$args['name']??$args['title']??'');
  $slug=(string)$page['slug'];
  if(preg_match('/pric|calculat|financial|fare/i',$label.' '.$slug))return true;
  $fields=maybe_unserialize($page['meta_fields']??[]);
  if(!is_array($fields))return false;
  foreach($fields as $field){
   if(!is_array($field))continue;
   if(in_array($field['name']??'',[
    'shared_mode_policies_json','shared_mode_policy_rows',
    'shared_financial_rules_json','shared_payment_fee_rows',
   ],true))return true;
  }
  return false;
 }

 public static function notice(){
  if(!current_user_can('manage_woocommerce')||!function_exists('jet_engine'))return;
  $requested=[];
  foreach(['page','subpage','option_page'] as $key){
   if(isset($_GET[$key])&&is_string($_GET[$key]))$requested[]=sanitize_key(wp_unslash($_GET[$key]));
  }
  if(!$requested)return;
  $engine=jet_engine();
  $data=$engine->options_pages->data??null;
  if(!is_object($data)||!method_exists($data,'get_items'))return;
  $items=$data->get_items();
  if(!is_array($items))return;
  $matched=false;
  foreach($items as $page){
   if(!is_array($page))continue;
   if(in_array(sanitize_key((string)($page['slug']??'')),$requested,true)&&self::is_pricing_page($page)){$matched=true;break;}
  }
  if(!$matched)return;
  $global=get_option(MAD4B_Age_Aware_Stay::OPTION,[]);
  $bands=is_array($global)&&is_array($global['bands']??null)?count($global['bands']):0;
  $enabled=is_array($global)&&!empty($global['age_pricing_enabled']);
  $summary=$bands===0?'No global age bands configured; legacy prices remain unchanged.'
   :($bands.' global age band(s); per-person age pricing '.($enabled?'enabled':'disabled').'.');
  $url=add_query_arg(['page'=>MAD4B_Age_Occupancy_Admin::PAGE,'scope'=>'global'],admin_url('admin.php'));
  echo '<div class="notice notice-info"><p><strong>Shared Age &amp; Occupancy Pricing:</strong> '.esc_html($summary).
   ' <a href="'.esc_url($url).'">Edit global pricing policy in WooCommerce</a>. The JetEngine Price Calculation page retains its own mode and financial rules; age rules are inherited from WooCommerce without copying settings.</p></div>';
 }
}
MAD4B_Age_Occupancy_JetEngine_Bridge::init();
