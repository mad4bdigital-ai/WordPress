<?php
$out=['contract'=>'mad4b.pricing-cache-acceptance.v1','state'=>'FAILED','checks'=>[],'observed_utc'=>gmdate('c')];$ids=[];$old=$GLOBALS['woocommerce_wpml']??null;
try{
 if(wp_get_environment_type()!=='staging'||!function_exists('shared_lab_tour_price_resolve')||!function_exists('shared_lab_tour_price_resolve_uncached'))throw new RuntimeException('STAGING_PRICING_RUNTIME_REQUIRED');
 $GLOBALS['mad4b_acceptance_currency']='USD';$GLOBALS['woocommerce_wpml']=(object)['multi_currency'=>new class{function get_client_currency(){return $GLOBALS['mad4b_acceptance_currency'];}}];
 foreach([111,237] as $amount){$id=wp_insert_post(['post_type'=>'tours-and-activities','post_status'=>'publish','post_title'=>'MAD4B pricing cache acceptance','post_name'=>'mad4b-pricing-cache-'.wp_generate_uuid4()],true);if(is_wp_error($id))throw new RuntimeException('FIXTURE_CREATE_FAILED');$ids[]=$id;update_post_meta($id,'start_price',$amount);update_post_meta($id,'tour_base_currency','USD');}
 $settings=get_option('_wcml_settings',[]);$currencies=array_values(array_intersect(['USD','EUR','EGP','GBP'],array_keys($settings['currency_options']??[])));if(!$currencies)$currencies=['USD'];
 foreach($currencies as $currency){$GLOBALS['mad4b_acceptance_currency']=$currency;
  foreach([gmdate('Y-m-d'),gmdate('Y-m-d',time()+86400*30)] as $day)foreach($ids as $i=>$id){
   $cold=shared_lab_tour_price_resolve_uncached($id,$day);$warm=shared_lab_tour_price_resolve($id,$day);$again=shared_lab_tour_price_resolve($id,$day);$expected=pricing_currency_convert([111,237][$i],'USD',$currency);
   $ok=($cold['status']??'')==='available'&&($warm['best']['final']??null)===$expected&&$warm===$cold&&$again===$cold;
   $out['checks'][]=['currency'=>$currency,'day'=>$day,'fixture'=>$i,'expected'=>$expected,'actual'=>$warm['best']['final']??null,'pass'=>$ok];
  }
 }
 $GLOBALS['mad4b_acceptance_currency']='USD';$id=$ids[0];$before=shared_lab_tour_price_resolve($id,gmdate('Y-m-d'));update_post_meta($id,'start_price',222);$after=shared_lab_tour_price_resolve($id,gmdate('Y-m-d'));$fresh=shared_lab_tour_price_resolve_uncached($id,gmdate('Y-m-d'));
 $out['same_request_invalidation']=['before'=>$before['best']['final']??null,'after'=>$after['best']['final']??null,'fresh'=>$fresh['best']['final']??null,'pass'=>$after===$fresh&&($fresh['best']['final']??null)===222.0];
 $out['state']=array_reduce($out['checks'],static fn($a,$c)=>$a&&$c['pass'],true)&&$out['same_request_invalidation']['pass']?'PASS':'FAILED';
}catch(Throwable $e){$out['error']=$e->getMessage();}
finally{foreach($ids as $id)wp_delete_post($id,true);$GLOBALS['woocommerce_wpml']=$old;$out['fixtures_deleted']=count($ids);}
echo 'MAD4B_PRICING_ACCEPTANCE='.wp_json_encode($out)."\n";
