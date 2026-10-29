<?php
$_SERVER['HTTP_HOST']='localhost:8094';
require '/var/www/html/wp-load.php';
use Commerce\Composition\ServiceProductIdentity as Identity;
global $wpdb;
$passed=0;$failed=[];
$ok=static function($v,$label)use(&$passed,&$failed){
    $v?$passed++:$failed[]=$label;
};
$counts=[
    'products'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'"),
    'orders'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
    'supplier_links'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->prefix}etg_booking_links"),
];
$old=get_current_user_id();
try {
    wp_set_current_user((int)get_users([
       'role'=>'administrator','number'=>1,'fields'=>'ID'])[0]);
    $cfg=get_option('cptwooint_settings',[]);
    $enabled=$cfg['selected_post_types']??[];
    $ok(function_exists('cptwooint'),'installed_cpt_woo_provider');
    $ok(isset($enabled['tours-and-activities']),
        'tour_service_type_configured');
    $ok(isset($enabled['tour-rates']),
        'tour_rate_type_configured_independently');
    $ok(!isset($enabled['properties']),
        'property_type_not_configured_on_current_site');
    $id=Identity::inspect(60645);
    $ok(!is_wp_error($id) &&
        $id['woocommerce_product_id']===60645 &&
        $id['source_id']===60645 &&
        $id['identity_owner']==='cpt_product_integration',
        'original_tour_post_id_is_wc_product_id');
    $wc=wc_get_product(60645);
    $ok($wc instanceof WC_Product &&
        $wc->get_id()===60645 &&
        get_post_type(60645)==='tours-and-activities',
        'wc_data_store_reads_original_cpt_product');
    $ok(!is_wp_error($id) &&
        $id['wc_raw_price_is_approved_quote']===false &&
        $id['order_item_creation_authorized']===false &&
        $id['supplier_booking_authorized']===false &&
        $id['payment_authorized']===false,
        'raw_cpt_price_never_promoted_to_certified_quote');
    $noRateAsService=Identity::inspect(61158);
    $ok(is_wp_error($noRateAsService) &&
        $noRateAsService->get_error_code()==='source_identity_not_service',
        'rate_cpt_not_accidentally_sold_as_second_service');
    $unsupported=Identity::inspect(36474);
    $ok(is_wp_error($unsupported) &&
        $unsupported->get_error_code()==='source_identity_cpt_type_not_enabled',
        'unsupported_property_fails_closed_without_fake_product');
    $unknown=Identity::inspect(999999999);
    $ok(is_wp_error($unknown),'missing_service_fails_closed');
    $ok((int)$wpdb->get_var($wpdb->prepare(
         "SELECT COUNT(*) FROM {$wpdb->posts}
          WHERE ID=%d AND post_type='product'",60645))===0,
        'no_duplicate_woo_product_post_required');
    wp_set_current_user(0);
    $guest=Identity::inspect(60645);
    $ok(is_wp_error($guest) &&
        $guest->get_error_code()==='source_identity_operator_required',
        'nonoperator_cannot_access_private_binding_preview');
} finally {
    wp_set_current_user($old);
}
$after=[
    'products'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type='product'"),
    'orders'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->prefix}wc_orders"),
    'supplier_links'=>(int)$wpdb->get_var(
       "SELECT COUNT(*) FROM {$wpdb->prefix}etg_booking_links"),
];
$ok($after===$counts,'source_product_inspection_has_no_writes');
echo wp_json_encode([
    'result'=>$failed?'FAIL':'PASS',
    'assertions'=>$passed,'failed'=>$failed,
    'product_post_count'=>$after['products'],
    'woocommerce_order_count'=>$after['orders'],
    'source_product_id'=>60645,
    'out_of_scope_property_id'=>36474,
    'all_supplier_booking_writes_disabled'=>true
],JSON_UNESCAPED_SLASHES).PHP_EOL;
if($failed)exit(2);
