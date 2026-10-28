<?php
/** Native WooCommerce admin-post recheck: no external provider registered by default. */
if(!defined('ABSPATH')||!defined('SHARED_PRODUCT_LAB_ONLY')||!SHARED_PRODUCT_LAB_ONLY)return;
add_action('admin_post_shared_product_refund_recheck',static function(){
 if(!current_user_can('manage_woocommerce'))wp_die('Forbidden',403);
 $id=isset($_POST['order_id'])?absint(wp_unslash($_POST['order_id'])):0;
 $hash=isset($_POST['reservation_hash'])?sanitize_text_field(wp_unslash($_POST['reservation_hash'])):'';
 check_admin_referer('shared_product_recheck_'.$id);
 if(!$id||!preg_match('/^[a-f0-9]{64}$/D',$hash))wp_die('Invalid reservation',400);
 $result=shared_product_refund_provider_recheck($id,$hash);
 $code=is_wp_error($result)?$result->get_error_code():$result['status'];
 $url=admin_url('admin.php?page=wc-orders&action=edit&id='.$id);
 wp_safe_redirect(add_query_arg('shared_provider_recheck_result',rawurlencode($code),$url));exit;
});
