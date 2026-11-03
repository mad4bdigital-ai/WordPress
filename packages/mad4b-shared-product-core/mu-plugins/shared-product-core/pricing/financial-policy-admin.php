<?php
/** Generic guarded administrative policy editing, isolated lab. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_validate_financial_rules($rules){
 if(!is_array($rules)||($rules['schema']??null)!==1||!is_array($rules['payment_fees']??null)||!is_array($rules['offers']??null)||count($rules['offers'])>100)return new WP_Error('invalid_financial_policy_schema');
 foreach($rules['payment_fees'] as $method=>$fee){
  if(!is_string($method)||!preg_match('/^[a-z0-9_-]{1,80}$/D',$method)||!is_array($fee))return new WP_Error('invalid_payment_method');
  foreach(['percent','fixed'] as $field)if(!isset($fee[$field])||!is_numeric($fee[$field])||!is_finite((float)$fee[$field])||(float)$fee[$field]<0)return new WP_Error('invalid_payment_fee');
  if((float)$fee['percent']>100)return new WP_Error('invalid_payment_fee');
 }
 $seen=[];
 foreach($rules['offers'] as $offer){
  if(!is_array($offer)||!is_string($offer['id']??null)||!preg_match('/^[a-z0-9_-]+$/D',$offer['id'])||isset($seen[$offer['id']]))return new WP_Error('invalid_offer_id');$seen[$offer['id']]=true;
  foreach(['start','end'] as $key){$v=$offer[$key]??null;if(!is_string($v)||!preg_match('/^\d{4}-\d{2}-\d{2}$/D',$v)||!checkdate((int)substr($v,5,2),(int)substr($v,8,2),(int)substr($v,0,4)))return new WP_Error('invalid_offer_date');}
  if($offer['end']<$offer['start']||!isset($offer['percent'])||!is_numeric($offer['percent'])||(float)$offer['percent']<0||(float)$offer['percent']>100)return new WP_Error('invalid_offer_value');
  foreach(['payment_methods','country_ids','nationalities'] as $key)if(isset($offer[$key])&&!is_array($offer[$key]))return new WP_Error('invalid_offer_scope');
 }
 return true;
}
add_action('admin_menu',static function(){
 add_submenu_page('options-general.php','Booking Financial Policies','Booking Financial Policies','manage_options','shared-product-financial',static function(){
  if(!current_user_can('manage_options'))wp_die('Forbidden');
  $saved=get_option('shared_product_payment_offer_rules',null);
  echo '<div class="wrap"><h1>Booking Financial Policies</h1><p>Lab-only policy editor. Preview calculations do not authorize payment charges.</p>';
  if(isset($_GET['updated']))echo '<div class="notice notice-success"><p>Validated policy saved.</p></div>';
  echo '<form method="post" action="'.esc_url(admin_url('admin-post.php')).'">';wp_nonce_field('shared_product_financial_save');
  echo '<input type="hidden" name="action" value="shared_product_financial_save"><label for="financial-rules">Payment fees and scoped promotions (JSON)</label><p><textarea id="financial-rules" name="financial_rules" rows="22" style="width:100%;font-family:monospace">'.esc_textarea($saved===null?'':wp_json_encode($saved,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)).'</textarea></p>';
  submit_button('Validate and save');echo '</form></div>';
 });
});
add_action('admin_post_shared_product_financial_save',static function(){
 if(!current_user_can('manage_options'))wp_die('Forbidden',403);
 check_admin_referer('shared_product_financial_save');
 $raw=wp_unslash($_POST['financial_rules']??'');if(!is_string($raw)||strlen($raw)>100000)wp_die('Invalid policy size',400);
 $rules=json_decode($raw,true);$result=shared_product_validate_financial_rules($rules);
 if(is_wp_error($result))wp_die(esc_html($result->get_error_code()),400);
 update_option('shared_product_payment_offer_rules',$rules,false);
 wp_safe_redirect(admin_url('options-general.php?page=shared-product-financial&updated=1'));exit;
});
