<?php
$boot=dirname(__DIR__,4).'/wp-load.php';
if(!defined('ABSPATH')){if(!is_file($boot))exit(2);require $boot;}
if(!function_exists('wc_get_orders'))exit(2);
$orders=wc_get_orders(['limit'=>100,'return'=>'ids','orderby'=>'date','order'=>'DESC']);
$audited=0;$errors=0;
foreach($orders as $id){
 $order=wc_get_order($id);if(!$order)continue;
 foreach($order->get_items('line_item') as $item){
  $raw=$item->get_meta('_shared_quote_display_amount',true);
  if($raw===''||$raw===null)continue;
  $audited++;
  $currency=$item->get_meta('_shared_quote_display_currency',true);
  $unit=is_numeric($raw)?(float)$raw:null;
  $total=(float)$item->get_total();$qty=(float)$item->get_quantity();
  $ok=$unit!==null&&$qty>0&&abs($unit*$qty-$total)<0.01&&$currency===$order->get_currency();
  echo 'ORDER_'.(int)$id.'_ITEM_'.(int)$item->get_id().'='.($ok?'PASS':'FAIL')."\n";
  if(!$ok)$errors++;
 }
}
if(!$audited){echo "NO_SNAPSHOTS=SKIP\n";exit(0);}
echo "AUDITED=$audited FAILED=$errors\n";
exit($errors?1:0);
