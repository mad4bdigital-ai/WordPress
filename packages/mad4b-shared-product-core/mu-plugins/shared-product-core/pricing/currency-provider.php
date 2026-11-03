<?php
function pricing_public_fx_snapshot() {
 $file=dirname(__DIR__,2).'/currency-data/public-rates.json';
 if(!is_readable($file))return null;
 $data=json_decode((string)file_get_contents($file),true);
 if(!is_array($data) || ($data['base']??null)!=='USD' || !is_array($data['rates']??null))return null;
 $fetched=strtotime($data['fetched_at']??'');
 if(!$fetched || abs(time()-$fetched)>86400*2)return null;
 return $data;
}
function pricing_public_fx_rate($from,$to) {
 $from=strtoupper((string)$from);$to=strtoupper((string)$to);
 if(!preg_match('/^[A-Z]{3}$/',$from)||!preg_match('/^[A-Z]{3}$/',$to))return null;
 if($from===$to)return 1.0;
 $data=pricing_public_fx_snapshot();if(!$data)return null;
 $a=$from==='USD'?1.0:($data['rates'][$from]??null);
 $b=$to==='USD'?1.0:($data['rates'][$to]??null);
 if(!is_numeric($a)||!is_numeric($b)||(float)$a<=0||(float)$b<=0)return null;
 return (float)$b/(float)$a;
}
add_filter('wcml_exchange_rates',static function($rates){
 if(!is_array($rates)||get_option('woocommerce_currency')!=='USD')return $rates;
 foreach(array_keys($rates) as $currency){
  $rate=pricing_public_fx_rate('USD',$currency);
  if($rate!==null)$rates[$currency]=round($rate,6);
 }
 return $rates;
},20);
