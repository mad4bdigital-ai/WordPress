<?php
/** Lab integration; no order persistence, payment attempt, or checkout activation. */
require '/var/www/html/wp-load.php';
if(wp_get_environment_type()!=='local')exit(21);
$c='ETG_Traveller_Nationality_Checkout_Blocks';
$bad=0;
$assert=static function($name,$pass)use(&$bad){echo "$name=".($pass?'PASS':'FAIL').PHP_EOL;if(!$pass)$bad++;};
$old=WC()->cart;
$oldCurrency=get_woocommerce_currency();
try {
 $quotes=[];
 foreach([[66139,3],[66138,2]] as [$id,$persons]){
  $q=shared_product_booking_configuration_quote($id,'general','double',['quantities'=>['persons'=>$persons],'options'=>[]]);
  $assert('SERVER_QUOTE_'.$id,!is_wp_error($q)&&($q['quantities']['persons']??0)===$persons);
  if(is_wp_error($q))continue;
  $quotes[$id]=$q;
 }
 if(count($quotes)!==2)exit(30);
 $cart=new WC_Cart();
 $items=[];
 foreach($quotes as $id=>$q){
  $items['rate-'.$id]=['key'=>'rate-'.$id,'product_id'=>$id,'variation_id'=>0,'variation'=>[],
    'quantity'=>1,'data'=>wc_get_product($id),'shared_booking_configuration'=>$q,
    'line_total'=>(float)$q['total'],'line_subtotal'=>(float)$q['total'],
    'line_tax'=>0,'line_subtotal_tax'=>0,'line_tax_data'=>[]];
 }
 $cart->set_cart_contents($items);WC()->cart=$cart;
 $expected=$c::expected_items($cart->get_cart());
 $assert('TWO_RATE_BOOKINGS',count($expected)===2);
 $assert('VERIFIED_3_PERSONS_NOT_QUANTITY',($expected['rate-66139']['travellers']??null)===3);
 $assert('VERIFIED_2_PERSONS_NOT_QUANTITY',($expected['rate-66138']['travellers']??null)===2);
 $first=$cart->get_cart();
 $checkCart=$first;
 $goodLine=$checkCart['rate-66139'];
 $goodLine['etg_traveller_nationalities']=['EG','SA','US'];
 $checkCart['rate-66139']=$goodLine;
 $other=$checkCart['rate-66138'];
 $other['etg_traveller_nationalities']=['US','EG'];
 $checkCart['rate-66138']=$other;
 $cart->set_cart_contents($checkCart);
 $errors=new WP_Error();
 $cCheck='ETG_Traveller_Nationality_Switcher';
 $cCheck::classic_checkout_validate([],$errors);
 $assert('CLASSIC_VERIFIED_COUNT_ACCEPTS',$errors->has_errors()===false);
 $badCart=$checkCart;$badCart['rate-66139']['etg_traveller_nationalities']=['EG'];
 $cart->set_cart_contents($badCart);
 $errors=new WP_Error();$cCheck::classic_checkout_validate([],$errors);
 $assert('CLASSIC_COUNT_MISMATCH_FAIL_CLOSED',$errors->get_error_code()==='nationality_booking_count_mismatch');
 $badCart=$checkCart;unset($badCart['rate-66139']['shared_booking_configuration']);
 $cart->set_cart_contents($badCart);
 $errors=new WP_Error();$cCheck::classic_checkout_validate([],$errors);
 $assert('CLASSIC_MISSING_SERVER_QUOTE_BLOCKED',$errors->get_error_code()==='nationality_booking_unverified');
 $cart->set_cart_contents($checkCart);
 $requestData=['travellers'=>['rate-66139'=>['EG','SA','US'],'rate-66138'=>['US','EG']]];
 $valid=$c::validate_request($requestData,$expected);
 $assert('VALID_MULTI_RATE_NATIONALITIES',!is_wp_error($valid) && count($valid['rate-66139'])===3);
 $badData=['travellers'=>['rate-66139'=>['EG'],'rate-66138'=>['US','EG']]];
 $assert('BAD_TRAVELLER_COUNT_REJECTED',is_wp_error($c::validate_request($badData,$expected)));
 $order=new WC_Order();
 foreach($items as $row){$item=apply_filters('woocommerce_checkout_create_order_line_item_object',new WC_Order_Item_Product(),'test-cart',['product_id'=>$row['product_id']],$order);$item->set_product_id($row['product_id']);$item->set_quantity(1);$order->add_item($item);}
 $request=new WP_REST_Request('POST','/wc/store/v1/checkout');
 $request->set_param('extensions',[$c::NAMESPACE_ID=>$requestData]);
 $c::apply_to_order($order,$request);
 $meta=[];foreach($order->get_items('line_item') as $i)$meta[$i->get_product_id()]=$i;
 $assert('ORDER_RATE_66139_3_IDENTITIES',
  $meta[66139]->get_meta('Traveller 1 passport country (ISO2)')==='EG' &&
  $meta[66139]->get_meta('Traveller 2 passport country (ISO2)')==='SA' &&
  $meta[66139]->get_meta('Traveller 3 passport country (ISO2)')==='US');
 $assert('ORDER_RATE_66138_2_IDENTITIES',
  $meta[66138]->get_meta('Traveller 1 passport country (ISO2)')==='US' &&
  $meta[66138]->get_meta('Traveller 2 passport country (ISO2)')==='EG');
 $c::apply_to_order($order,$request);
 $keys=array_map(static fn($m)=>(string)$m->key,$meta[66139]->get_meta_data());
 $nationKeys=array_values(array_filter($keys,static fn($key)=>str_starts_with($key,'Traveller ') && str_ends_with($key,' passport country (ISO2)')));
 $assert('IDEMPOTENT_ORDER_META',count($nationKeys)===3&&count($keys)===4);
 $assert('ROOT_BOOKING_REGISTRY_ID',strlen((string)$meta[66139]->get_meta('_etg_tm_registry_id'))===24);
 $assert('ORDER_ID_NOT_PERSISTED',!$order->get_id());
 $assert('CURRENCY_NOT_CHANGED',get_woocommerce_currency()===$oldCurrency);
 $rest=rest_do_request(new WP_REST_Request('GET','/wc/store/v1/cart'));
 $result=json_decode(wp_json_encode($rest->get_data()),true);$lines=$result['items']??[];
 $actual=[];foreach($lines as $item)$actual[$item['id']]=($item['extensions'][$c::NAMESPACE_ID]['travellers']??null);
 $assert('STORE_API_REAL_RATES_HTTP_200',$rest->get_status()===200);
 $assert('STORE_API_COUNTS_3_AND_2',($actual[66139]??null)===3&&($actual[66138]??null)===2);
 $unsigned=$items['rate-66139'];unset($unsigned['shared_booking_configuration']);
 $assert('UNVERIFIED_RATE_BLOCKED',$c::traveller_count($unsigned)===0);
 $wrong=$items['rate-66139'];$wrong['shared_booking_configuration']['total']*=1.2;
 $assert('TAMPERED_RATE_PRICE_BLOCKED',$c::traveller_count($wrong)===0);
 $draft=['product_id'=>66133,'quantity'=>1,'data'=>wc_get_product(66133),
    'configuration'=>['schema_version'=>2,'quantities'=>['persons'=>4]]];
 $assert('V2_DRAFT_NOT_A_PAYABLE_BOOKING',$c::traveller_count($draft)===0);
} finally {WC()->cart=$old;}
echo "REAL_BOOKING_TEST_FAILURES=$bad\n";exit($bad?1:0);
