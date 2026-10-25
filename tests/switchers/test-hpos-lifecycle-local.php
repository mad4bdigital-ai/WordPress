<?php
/** Local Woo HPOS persistence only; no payment, emails, stock or publishing. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(31);
$blk='Travel_Booking_Traveller_Nationality_Checkout_Blocks';
$test=static function($name,$value)use(&$fail){echo "$name=".($value?'PASS':'FAIL').PHP_EOL;if(!$value)$fail++;};
$fail=0;$id=0;
$quote=shared_product_booking_configuration_quote(66139,'general','double',
 ['quantities'=>['persons'=>3],'options'=>[]]);
if(is_wp_error($quote))exit(32);
$line=['product_id'=>66139,'quantity'=>1,'data'=>wc_get_product(66139),
 'shared_booking_configuration'=>$quote];
$cart=new WC_Cart();$cart->set_cart_contents(['qa-hpos'=>$line]);
$old=WC()->cart;WC()->cart=$cart;
try{
 $test('HPOS_MODE_ENABLED',class_exists('Automattic\WooCommerce\Utilities\OrderUtil') &&
  Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled());
 $order=new WC_Order();
 // No financial checkout. This is a restricted local checkout draft only.
 $order->set_status('checkout-draft');
 $order->set_currency(get_woocommerce_currency());
 $order->set_created_via('travel_booking_qa');
 $item=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),'qa-hpos',$line,null);
 $item->set_product_id(66139);$item->set_quantity(1);
 $order->add_item($item);
 $req=new WP_REST_Request('POST','/wc/store/v1/checkout');
 $req->set_param('extensions',[$blk::NAMESPACE_ID=>[
  'travellers'=>['qa-hpos'=>['EG','US','SA']],'confirmed'=>['qa-hpos'=>true]
 ]]);
 $blk::apply_to_order($order,$req);
 $test('DRAFT_ORDER_META_IN_MEMORY',$item->get_meta('Traveller 2 passport country (ISO2)')==='US');
 $beforeCurrency=$order->get_currency();$beforeTotal=$order->get_total();
 $order->save();$id=$order->get_id();
 $test('DRAFT_ORDER_SAVED_BY_WOO_API',$id>0);
 $again=wc_get_order($id);
 $test('HPOS_DRAFT_RELOADED',$again instanceof WC_Order &&
  $again->get_created_via()==='travel_booking_qa');
 $stored=array_values($again->get_items('line_item'));
 $test('HPOS_3_ISO_COUNTRIES_PERSISTED',count($stored)===1 &&
  $stored[0]->get_meta('Traveller 1 passport country (ISO2)')==='EG' &&
  $stored[0]->get_meta('Traveller 2 passport country (ISO2)')==='US' &&
  $stored[0]->get_meta('Traveller 3 passport country (ISO2)')==='SA');
 $test('ORDER_LEDGER_PRICE_UNCHANGED',$again->get_currency()===$beforeCurrency&&
  (float)$again->get_total()===(float)$beforeTotal);
 $badOrder=new WC_Order();$a=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),'dup-a',$line,null);
 $b=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),'dup-b',$line,null);
 $a->set_product_id(66139);$a->set_quantity(1);
 $b->set_product_id(66139);$b->set_quantity(1);
 $badOrder->add_item($a);$badOrder->add_item($b);
 $blocked=false;
 try{$blk::apply_to_order($badOrder,$req);}
 catch(Automattic\WooCommerce\StoreApi\Exceptions\RouteException $e){$blocked=true;}
 $test('DUPLICATE_PRODUCT_LINES_AMBIGUOUS_FAIL_CLOSED',$blocked);
}finally{
 if($id){$existing=wc_get_order($id);if($existing)$existing->delete(true);}
 WC()->cart=$old;
}
$test('QA_ORDER_HARD_DELETED',$id>0 && !wc_get_order($id));
echo "HPOS_LIFECYCLE_FAILURES=$fail\n";exit($fail?1:0);
