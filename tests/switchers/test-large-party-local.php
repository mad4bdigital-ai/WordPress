<?php
/** Safe in-memory Woo local-lab validation of 55 people, Woo quantity=1. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(19);
$tm='ETG_Traveller_Management';$cl='ETG_Traveller_Nationality_Switcher';$blk='ETG_Traveller_Nationality_Checkout_Blocks';
$fail=0;$test=static function($name,$good)use(&$fail){echo $name.'='.($good?'PASS':'FAIL').PHP_EOL;if(!$good)$fail++;};
$quote=shared_product_booking_configuration_quote(66139,'general','double',['quantities'=>['persons'=>55],'options'=>[]]);
$test('SERVER_QUOTE_55',!is_wp_error($quote));
if(is_wp_error($quote))exit(3);
$line=['key'=>'party55','product_id'=>66139,'variation_id'=>0,'variation'=>[],'quantity'=>1,
 'data'=>wc_get_product(66139),'shared_booking_configuration'=>$quote];
$test('WOO_QUANTITY_REMAINS_ONE',$line['quantity']===1);
$test('RESOLVER_SUPPORTS_55',$tm::resolve($line)['count']===55);
$test('BATCH_COUNT_55_IS_THREE',(int)ceil(55/$tm::LEGACY_BATCH_SIZE)===3);
$codes=array_fill(0,55,'EG');
$check=$cl::validate_list($codes);
$test('NATIONALITY_ALL_55_VALID',!is_wp_error($check)&&count($check)===55);
$expected=$blk::expected_items(['party55'=>$line]);
$test('STORE_EXPECTED_55',($expected['party55']['travellers']??0)===55);
$valid=$blk::validate_request(['travellers'=>['party55'=>$codes]],$expected);
$test('BLOCKS_55_COMPLETE',!is_wp_error($valid));
$test('BLOCKS_54_REJECTED',is_wp_error($blk::validate_request(['travellers'=>['party55'=>array_slice($codes,0,54)]],$expected)));
$test('NATIONALITY_1000_DENIED',is_wp_error($cl::validate_list(array_fill(0,1000,'EG'))));
$test('NATIONALITY_INDEX_999_DENIED',is_wp_error($cl::validate_list([999=>'EG'])));
$test('NATIONALITY_SPARSE_ARRAY_DENIED',is_wp_error($cl::validate_list([0=>'EG',2=>'SA'])));
$line['etg_traveller_nationalities']=$codes;
$cart=new WC_Cart();$cart->set_cart_contents(['party55'=>$line]);
$old=WC()->cart;WC()->cart=$cart;
try{
 $classic=new WP_Error();
 $cl::classic_checkout_validate([],$classic);
 $test('CLASSIC_55_NATIONALITIES_VALID',!$classic->has_errors());
 $partialLine=$line;$partialLine['etg_traveller_nationalities']=array_slice($codes,0,54);
 $partialCart=new WC_Cart();$partialCart->set_cart_contents(['party55'=>$partialLine]);
 WC()->cart=$partialCart;$error=new WP_Error();$cl::classic_checkout_validate([],$error);
 $test('CLASSIC_54_NATIONALITIES_REJECTED',$error->has_errors());
 WC()->cart=$cart;
 $item=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),'party55',$line,null);
 $order=new WC_Order();
 $item->set_product_id(66139);$item->set_quantity(1);$order->add_item($item);
 $initialCurrency=$order->get_currency();
 $initialTotal=$order->get_total();
 $request=new WP_REST_Request('POST','/wc/store/v1/checkout');
 $request->set_param('extensions',[$blk::NAMESPACE_ID=>['travellers'=>['party55'=>$codes]]]);
 $blk::apply_to_order($order,$request);
 $meta=array_filter($item->get_meta_data(),static function($e){return str_starts_with((string)$e->key,'Traveller ')&&str_ends_with((string)$e->key,' passport country (ISO2)');});
 $test('ORDER_55_NATIONALITY_METADATA',count($meta)===55);
 $test('ORDER_IN_MEMORY_ONLY',$order->get_id()===0);
 $test('PASSPORT_LAST_PERSON_CORRECT',$item->get_meta('Traveller 55 passport country (ISO2)')==='EG');
 $test('NO_PRICE_OR_CURRENCY_MUTATION',$order->get_total()===$initialTotal&&$order->get_currency()===$initialCurrency);
 $res=rest_do_request(new WP_REST_Request('GET','/wc/store/v1/cart'));
 $body=json_decode(wp_json_encode($res->get_data()),true);$ns=$blk::NAMESPACE_ID;
 $test('REAL_STORE_API_55',$res->get_status()===200&&($body['items'][0]['extensions'][$ns]['travellers']??0)===55);
} finally {WC()->cart=$old;}
echo "LARGE_PARTY_FAILURES=$fail\n";exit($fail?1:0);
