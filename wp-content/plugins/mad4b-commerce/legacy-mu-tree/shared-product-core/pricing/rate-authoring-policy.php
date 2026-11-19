<?php
/** Draft Rate authoring is independent of payment and supplier execution. */
defined('ABSPATH')||exit;
if(!function_exists('mad4b_rate_authoring_enabled')){
 function mad4b_rate_authoring_enabled():bool {
  $legacy=defined('MAD4B_COMMERCE_RUNTIME_ENABLED')&&MAD4B_COMMERCE_RUNTIME_ENABLED===true;
  return in_array(get_option('mad4b_rate_authoring_enabled',$legacy),[true,1,'1'],true);
 }
 function mad4b_rate_authoring_admin_url():string {
  return admin_url(current_user_can('manage_woocommerce')?'admin.php?page=wc-settings&tab=rate_generation':'admin.php?page=mad4b-rate-generation');
 }
}

/** Native seed factors shared by admin, REST and CLI. No alternate price calculator. */
function shared_product_tour_seed_factors(int $id):array {
 $v=get_post_meta($id,'_shared_tour_price_seed_factors_v1',true);$out=['single'=>'1','double'=>'1','triple'=>'1'];
 if(is_array($v))foreach($out as $key=>$default){$f=(string)($v[$key]??'1');if(preg_match('/^(?:0|[1-9][0-9]{0,2})(?:\\.[0-9]{1,4})?$/D',$f)&&(float)$f>0&&(float)$f<=100)$out[$key]=$f;}
 return $out;
}
