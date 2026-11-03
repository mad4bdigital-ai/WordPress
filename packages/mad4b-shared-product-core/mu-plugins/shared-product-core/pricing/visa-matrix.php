<?php
/** Visa matrix, country-level only; quotation not an eligibility guarantee. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
function shared_product_visa_matrix_validate($matrix){
 if(!is_array($matrix)||($matrix['schema']??null)!==1||!is_array($matrix['rates']??null)||count($matrix['rates'])>200)return new WP_Error('invalid_visa_matrix');
 $seen=[];
 foreach($matrix['rates'] as $row){
  if(!is_array($row)||!is_numeric($row['country_id']??null)||!is_string($row['nationality']??null)||!preg_match('/^[A-Z]{2}$/D',$row['nationality'])||!is_string($row['service']??null)||!preg_match('/^[a-z0-9_-]+$/D',$row['service']))return new WP_Error('invalid_visa_rate');
  $country=shared_product_destination_country((int)$row['country_id']);if(is_wp_error($country))return $country;
  if(!is_numeric($row['amount']??null)||!is_finite((float)$row['amount'])||(float)$row['amount']<0||!is_string($row['currency']??null)||!preg_match('/^[A-Z]{3}$/D',$row['currency']))return new WP_Error('invalid_visa_amount');
  $key=$row['country_id'].'/'.$row['nationality'].'/'.$row['service'];if(isset($seen[$key]))return new WP_Error('duplicate_visa_rate');$seen[$key]=true;
 }
 return true;
}
function shared_product_visa_quote($visa_id,$country_id,$nationality,$service,$currency=null){
 $eligible=shared_product_visa_eligibility($visa_id,$country_id,$nationality);if(is_wp_error($eligible))return $eligible;
 if(!is_string($service)||!preg_match('/^[a-z0-9_-]+$/D',$service))return new WP_Error('invalid_visa_service');
 $matrix=json_decode((string)get_post_meta($visa_id,'shared_product_visa_rates',true),true);
 $valid=shared_product_visa_matrix_validate($matrix);if(is_wp_error($valid))return $valid;
 $matched=[];
 foreach($matrix['rates'] as $row)if((int)$row['country_id']===(int)$country_id&&$row['nationality']===$nationality&&$row['service']===$service)$matched[]=$row;
 if(count($matched)!==1)return new WP_Error('visa_rate_unavailable');
 $rate=$matched[0];$target=strtoupper($currency?:pricing_display_currency());$wcml=get_option('_wcml_settings',[]);
 if(!isset($wcml['currency_options'][$target]))return new WP_Error('unsupported_currency');
 $converted=(float)$rate['amount']===0?0:pricing_currency_convert((float)$rate['amount'],$rate['currency'],$target);
 if($converted===null||!is_finite((float)$converted))return new WP_Error('visa_fx_unavailable');
 return ['visa_id'=>(int)$visa_id,'country_id'=>(int)$country_id,'nationality'=>$nationality,'service'=>$service,'currency'=>$target,'amount'=>round((float)$converted,2),'status'=>'preview_only_not_visa_approval_or_checkout_authority'];
}
add_action('add_meta_boxes_travel-visa',static function(){
 add_meta_box('shared-product-visa-rates','Visa Pricing Matrix',static function($post){
  wp_nonce_field('shared_product_visa_save','shared_product_visa_nonce');
  echo '<p>Country-level destination, nationality, service and amount. Visa eligibility is not guaranteed.</p>';
  echo '<textarea name="shared_product_visa_rates" rows="12" style="width:100%;font-family:monospace">'.esc_textarea(get_post_meta($post->ID,'shared_product_visa_rates',true)).'</textarea>';
  echo '<p class="description">JSON schema 1: rates[{country_id,nationality,service,amount,currency}]</p>';
 },'travel-visa','normal','default');
});
add_action('save_post_travel-visa',static function($id){
 if(!isset($_POST['shared_product_visa_nonce'])||!wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['shared_product_visa_nonce'])),'shared_product_visa_save')||!current_user_can('edit_post',$id))return;
 if(defined('DOING_AUTOSAVE')&&DOING_AUTOSAVE)return;
 $raw=wp_unslash($_POST['shared_product_visa_rates']??'');if(!is_string($raw)||strlen($raw)>100000)return;
 if(trim($raw)===''){delete_post_meta($id,'shared_product_visa_rates');return;}
 $matrix=json_decode($raw,true);if(is_wp_error(shared_product_visa_matrix_validate($matrix)))return;
 update_post_meta($id,'shared_product_visa_rates',wp_json_encode($matrix,JSON_UNESCAPED_SLASHES));
});
