<?php
/** Real WooCommerce cart/order object test. All items remain in-memory; no order save. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(10);
$fail=0;$assert=static function($n,$ok)use(&$fail){echo "$n=".($ok?'PASS':'FAIL').PHP_EOL;if(!$ok)$fail++;};
$tm='ETG_Traveller_Management';$blocks='ETG_Traveller_Nationality_Checkout_Blocks';$switcher='ETG_Traveller_Nationality_Switcher';
$quote=shared_product_booking_configuration_quote(66139,'general','double',['quantities'=>['persons'=>3],'options'=>[]]);
if(is_wp_error($quote))exit(21);
$base=['key'=>'booking-main','product_id'=>66139,'variation_id'=>0,'variation'=>[],'quantity'=>1,'data'=>wc_get_product(66139),'shared_booking_configuration'=>$quote,
 'etg_traveller_nationalities'=>['EG','SA','US']];
$extra=['key'=>'activity-linked','product_id'=>66133,'variation_id'=>0,'variation'=>[],'quantity'=>1,'data'=>wc_get_product(66133)];
$cart=new WC_Cart();$cart->set_cart_contents(['booking-main'=>$base,'activity-linked'=>$extra]);
$old=WC()->cart;WC()->cart=$cart;
$link=static fn($value)=>[['contract'=>$tm::CONTRACT,'authority'=>'server_verified','booking_key'=>'booking-main','service_key'=>'activity-linked','traveller_indices'=>[0,2]]];
add_filter('etg_traveller_management/verified_service_links',$link);
try{
 $reg=$tm::registry($cart->get_cart());
 $assert('VERIFIED_PARENT_REGISTRY',count($reg['bookings'])===1&&count($reg['bookings']['booking-main']['people'])===3);
 $assert('VERIFIED_LINK_TWO_SUBSET',($reg['linked_services']['activity-linked']['traveller_indices']??[])===[0,2]);
 $items=$blocks::expected_items($cart->get_cart());
 $assert('ONE_NATIONALITY_COLLECTION_PER_BOOKING',count($items)===1&&isset($items['booking-main']));
 $err=new WP_Error();$switcher::classic_checkout_validate([],$err);
 $assert('CLASSIC_SKIP_VALID_LINKED_EXTRA',!$err->has_errors());
 $order=new WC_Order();
 $order_items=[];
 foreach($cart->get_cart() as $key=>$row){
  $item=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),$key,$row,$order);
  $item->set_product_id($row['product_id']);$item->set_quantity(1);
  $switcher::order_item($item,$key,$row,$order);
  $order->add_item($item);$order_items[$key]=$item;
 }
 $root=$order_items['booking-main'];$child=$order_items['activity-linked'];
 $assert('CLASSIC_ROOT_HAS_THREE_COUNTRIES',$root->get_meta('Traveller 3 passport country (ISO2)')==='US');
 $assert('CLASSIC_CHILD_REFERENCES_ROOT',$child->get_meta('_etg_tm_parent_registry_id')===$root->get_meta('_etg_tm_registry_id'));
 $assert('CLASSIC_CHILD_HAS_NO_DUPLICATE_COUNTRIES',$child->get_meta('Traveller 1 passport country (ISO2)')==='');
 $assert('CLASSIC_CHILD_SUBSET_ASSIGNMENT',$child->get_meta('_etg_tm_traveller_indices')===[0,2]);
 $req=new WP_REST_Request('POST','/wc/store/v1/checkout');
 $req->set_param('extensions',[$blocks::NAMESPACE_ID=>['travellers'=>['booking-main'=>['EG','SA','US']]]]);
 $blocks::apply_to_order($order,$req);
 $assert('BLOCKS_ROOT_STILL_HAS_THREE',$root->get_meta('Traveller 3 passport country (ISO2)')==='US');
 $assert('BLOCKS_CHILD_STILL_REFERENCES_ROOT',$child->get_meta('_etg_tm_parent_registry_id')===$root->get_meta('_etg_tm_registry_id'));
 $assert('BLOCKS_PARENT_IDEMPOTENT',count(array_filter($root->get_meta_data(),static fn($x)=>preg_match('/^Traveller [0-9]+ passport country/',(string)$x->key)))===3);
 $response=rest_do_request(new WP_REST_Request('GET','/wc/store/v1/cart'));
 $json=json_decode(wp_json_encode($response->get_data()),true);
 $byId=[];foreach($json['items']??[] as $item)$byId[$item['id']]=$item;
 $ns=$blocks::NAMESPACE_ID;
 $assert('STORE_API_LINKED_EXTRA_NO_SECOND_FORM',($byId[66133]['extensions'][$ns]['bookable']??true)===false);
 $assert('STORE_API_MAIN_REQUIRES_THREE',($byId[66139]['extensions'][$ns]['travellers']??0)===3);
 $assert('ORDER_NEVER_SAVED',$order->get_id()===0);
} finally {
 remove_filter('etg_traveller_management/verified_service_links',$link);
 WC()->cart=$old;
}
echo "LINKED_SERVICE_FAILURES=$fail\n";
exit($fail?1:0);
